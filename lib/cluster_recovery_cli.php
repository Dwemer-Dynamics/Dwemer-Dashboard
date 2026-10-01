<?php
// Command-line entry points for full-cluster recovery. The Dashboard launches only `worker`, as the web
// account. `setup`, `activate` and `rollback` are operator commands: they require root, print what will
// happen and need a typed confirmation. Activation swaps data directories and keeps the old cluster.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/cluster_recovery.php';

class CrCancelled extends RuntimeException {}

function cr_out(string $line): void { fwrite(STDOUT, $line . PHP_EOL); }
function cr_log(string $line): void { fwrite(STDERR, '[' . date('c') . '] ' . $line . PHP_EOL); }

function cr_check_cancel(string $dir): void
{
    if (!empty($GLOBALS['cr_signal']) || is_file("$dir/cancel")) throw new CrCancelled();
}

function cr_progress(string $dir, int $done, int $total): void
{
    cr_check_cancel($dir);
    cr_update_status($dir, ['progress' => ['done' => $done, 'total' => $total]]);
}

// Control characters and very long database messages never reach the browser.
function cr_safe_text(string $text): string
{
    return mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text)), 0, 400);
}

function cr_worker(string $id): int
{
    if (posix_geteuid() === 0) { cr_log('Refusing to run the recovery worker as root.'); return 1; }
    if ($problem = cr_root_problem()) { cr_log($problem); return 1; }
    $dir = cr_job_dir($id);
    $lock = cr_lock("$dir/worker.lock", LOCK_EX);
    if (!$lock) { cr_log('Another worker owns this job.'); return 1; }
    $manifest = cr_read_json("$dir/job.json");
    if ((cr_read_json("$dir/status.json")['state'] ?? '') !== 'queued' || !$manifest) { cr_log('This job is not waiting to start.'); return 1; }
    umask(0077);
    $GLOBALS['cr_signal'] = false;
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) pcntl_signal($signal, static function (): void { $GLOBALS['cr_signal'] = true; });
    $bin = (string)$manifest['pg_bin'];
    $stage = 'staging';
    $set = static function (string $next, string $message, array $extra = []) use ($dir, &$stage): void {
        cr_check_cancel($dir);
        $stage = $next;
        cr_update_status($dir, ['state' => $next, 'stage' => $next, 'message' => $message, 'progress' => null, 'worker_pid' => getmypid()] + $extra);
    };
    try {
        $set('staging', 'Protecting the selected backup from automatic cleanup.');
        cr_stage($dir, $manifest);
        $set('verifying', 'Reading the whole backup to check it before restoring.');
        $scan = cr_scan($dir, $manifest);
        cr_preflight($dir, $manifest, $scan);
        $set('initializing', 'Creating a private PostgreSQL instance for this recovery.', ['sha256' => $scan['sha256']]);
        cr_initialize($dir, $bin);
        $set('restoring', 'Restoring the backup into the private instance. Large backups can take hours.');
        cr_restore($dir, $bin, $manifest);
        $set('inspecting', 'Counting recovered databases and events.');
        $inspection = cr_inspect($dir);
        $set('stopping', 'Stopping the private instance.', $inspection);
        if (!cr_stop_instance($dir, $bin)) throw new ClusterRecoveryException('The private PostgreSQL instance did not stop cleanly.');
        cr_update_status($dir, ['state' => 'ready', 'finished' => time(), 'progress' => null,
            'message' => 'Recovered copy is ready and stopped. The live database has not been changed.']);
        return 0;
    } catch (CrCancelled) {
        $stopped = cr_stop_instance($dir, $bin);
        cr_update_status($dir, ['state' => 'cancelled', 'stage' => $stage, 'finished' => time(), 'progress' => null, 'message' => 'Recovery cancelled. The live database was not changed.',
            'error' => $stopped ? null : 'The private PostgreSQL instance did not stop. Discard will retry.']);
        return 0;
    } catch (Throwable $e) {
        cr_log(get_class($e) . ' during ' . $stage . ': ' . $e->getMessage());
        cr_stop_instance($dir, $bin);
        cr_update_status($dir, ['state' => 'failed', 'stage' => $stage, 'finished' => time(), 'progress' => null, 'message' => 'Recovery failed. The live database was not changed.',
            'error' => $e instanceof ClusterRecoveryException ? $e->getMessage() : 'Unexpected recovery error. See worker.log in the recovery job folder.']);
        return 1;
    }
}

function cr_same_file(array|false $stat, array $manifest): bool
{
    return $stat && $stat['ino'] === $manifest['inode'] && $stat['size'] === $manifest['bytes'] && $stat['mtime'] === $manifest['mtime'];
}

function cr_stage(string $dir, array $manifest): void
{
    $target = "$dir/backup.sql";
    if ($manifest['linked']) {
        clearstatcache(true, $target);
        if (!cr_same_file(@stat($target), $manifest)) throw new ClusterRecoveryException('The protected backup changed after it was selected. Nothing was restored.');
        cr_update_status($dir, ['staged' => 'linked']);
        return;
    }
    $in = @fopen($manifest['path'], 'rbe');
    if (!$in || !cr_same_file(fstat($in), $manifest)) throw new ClusterRecoveryException('The backup changed or was removed before it could be copied. Nothing was restored.');
    $out = @fopen("$target.part", 'xbe');
    if (!$out) throw new RuntimeException('Could not create the staged copy.');
    $done = 0; $reported = 0;
    while (!feof($in) && ($chunk = fread($in, 8 << 20)) !== false && $chunk !== '') {
        if (fwrite($out, $chunk) !== strlen($chunk)) throw new ClusterRecoveryException('Could not copy the backup. Check free disk space.');
        $done += strlen($chunk);
        if ($done - $reported >= 256 << 20) { cr_progress($dir, $done, $manifest['bytes']); $reported = $done; }
    }
    $unchanged = cr_same_file(fstat($in), $manifest);
    fclose($in);
    if (!fflush($out) || !fclose($out) || $done !== $manifest['bytes'] || !$unchanged) throw new ClusterRecoveryException('The backup changed while it was being copied. Nothing was restored.');
    if (!rename("$target.part", $target)) throw new RuntimeException('Could not finish the staged copy.');
    cr_update_status($dir, ['staged' => 'copied']);
}

// One streaming pass: checksum, database/extension inventory and rejection of non-pg_dumpall content.
// This is a sanity check for trusted backups, not a sandbox: SQL inside the dump still runs as superuser
// of the private instance, whose processes run as the web account.
function cr_scan(string $dir, array $manifest): array
{
    $handle = @fopen("$dir/backup.sql", 'rbe');
    if (!$handle) throw new ClusterRecoveryException('The protected backup could not be read.');
    $hash = hash_init('sha256');
    $result = ['databases' => [], 'extensions' => [], 'from_major' => null, 'complete' => false];
    $copy = false; $lineStart = true; $lineNo = 0; $read = 0; $reported = 0;
    while (($line = fgets($handle, 1 << 20)) !== false) {
        hash_update($hash, $line);
        $read += strlen($line);
        $wasStart = $lineStart;
        $lineStart = str_ends_with($line, "\n");
        if ($read - $reported >= 256 << 20) { cr_progress($dir, $read, $manifest['bytes']); $reported = $read; }
        if (!$wasStart) continue;
        $lineNo++;
        $text = rtrim($line, "\r\n");
        if ($copy) { if ($text === '\\.') $copy = false; continue; }
        if ($text === '') continue;
        if ($text[0] === '\\') {
            if (preg_match('/^\\\\connect\s+(?:-reuse-previous=on\s+"dbname=\'((?:[^\']|\'\')+)\'"|"?([A-Za-z0-9_]+)"?)$/', $text, $match)) {
                $result['databases'][($match[2] ?? '') !== '' ? $match[2] : str_replace("''", "'", $match[1])] = true;
            } elseif (!preg_match('/^\\\\(?:un)?restrict\s+[A-Za-z0-9]+$/', $text)) {
                throw new ClusterRecoveryException("SQL line $lineNo uses a psql command that pg_dumpall does not write. Nothing was restored.");
            }
        } elseif ($text[0] === 'C') {
            if (preg_match('/^COPY\s.+\sFROM\s+stdin;$/', $text)) $copy = true;
            elseif (preg_match('/^CREATE\s+TABLESPACE\b/i', $text)) throw new ClusterRecoveryException('This backup defines custom tablespaces, which point at live server folders. Recovery does not support them. Nothing was restored.');
            elseif (preg_match('/^CREATE\s+EXTENSION\s+(?:IF\s+NOT\s+EXISTS\s+)?"?([A-Za-z0-9_-]+)"?/i', $text, $match)) $result['extensions'][strtolower($match[1])] = true;
            elseif (preg_match('/^CREATE\s+DATABASE\s+"?([^"\s]+)"?/', $text, $match)) $result['databases'][$match[1]] = true;
        } elseif ($text[0] === '-') {
            if ($result['from_major'] === null && preg_match('/^-- Dumped from database version (\d+)/', $text, $match)) $result['from_major'] = (int)$match[1];
            if ($text === '-- PostgreSQL database cluster dump complete') $result['complete'] = true;
        }
    }
    $complete = feof($handle);
    fclose($handle);
    if (!$complete || $read !== $manifest['bytes']) throw new ClusterRecoveryException('The protected backup could not be read completely or changed size. Nothing was restored.');
    $result['sha256'] = hash_final($hash);
    cr_log('Backup checked: ' . $read . ' bytes, sha256 ' . $result['sha256'] . ', extensions: ' . implode(',', array_keys($result['extensions'])));
    return $result;
}

function cr_preflight(string $dir, array $manifest, array $scan): void
{
    if (!$scan['complete']) throw new ClusterRecoveryException('This backup has no completion marker; it may have been cut off while saving. Nothing was restored.');
    if ($scan['from_major'] !== null && $scan['from_major'] > $manifest['pg_major']) {
        throw new ClusterRecoveryException("This backup came from PostgreSQL {$scan['from_major']}, but only PostgreSQL {$manifest['pg_major']} is installed.");
    }
    if (cr_run([$manifest['pg_bin'] . '/pg_config', '--sharedir'], $share) !== 0) throw new ClusterRecoveryException('Could not locate PostgreSQL extension files.');
    $missing = array_filter(array_keys($scan['extensions']), static fn(string $name): bool => !is_file(trim($share) . "/extension/$name.control"));
    if ($missing) throw new ClusterRecoveryException('Install these PostgreSQL extensions, then start a new recovery: ' . implode(', ', $missing) . '.');
    $free = cr_free_bytes();
    $required = cr_required_bytes($manifest['bytes'], false);
    if ($free !== null && $free < $required) throw new ClusterRecoveryException('Not enough free disk space. Recovery needs about ' . cr_format_bytes($required) . '; ' . cr_format_bytes($free) . ' is free.');
}

// A fresh cluster with no TCP listener; only the web account can open its socket directory.
function cr_initialize(string $dir, string $bin): void
{
    if (cr_run(["$bin/initdb", '-D', "$dir/data", '-U', 'postgres', '--auth-local=trust', '--auth-host=reject', '-E', 'UTF8', '--locale=C.UTF-8'], $output) !== 0) {
        cr_log($output);
        throw new ClusterRecoveryException('Could not create the private PostgreSQL instance. See worker.log in the recovery job folder.');
    }
    if (!is_dir("$dir/run") && !mkdir("$dir/run", 0700)) throw new RuntimeException('Could not create the private socket folder.');
    // Debian production clusters read /etc/postgresql, so these restore-only settings never follow an activation.
    $settings = "\n# Dwemer isolated recovery instance\nlisten_addresses = ''\nport = " . CR_PORT . "\nunix_socket_directories = '$dir/run'\n"
        . "unix_socket_permissions = 0700\nsynchronous_commit = off\nwal_level = minimal\nmax_wal_senders = 0\nautovacuum = off\n"
        . "max_wal_size = '4GB'\ncheckpoint_timeout = '30min'\nmaintenance_work_mem = '256MB'\nshared_buffers = '256MB'\n";
    if (file_put_contents("$dir/data/postgresql.conf", $settings, FILE_APPEND) === false) throw new RuntimeException('Could not configure the private instance.');
    if (cr_run(["$bin/pg_ctl", 'start', '-D', "$dir/data", '-l', "$dir/postgres.log", '-w', '-t', '120'], $output) !== 0) {
        cr_log($output);
        throw new ClusterRecoveryException('The private PostgreSQL instance did not start. See postgres.log in the recovery job folder.');
    }
}

// psql reports progress only through its file offset; read it from /proc instead of estimating.
function cr_read_position(int $pid, string $path, ?int &$fd): ?int
{
    if ($fd === null) {
        foreach (glob("/proc/$pid/fd/*") ?: [] as $link) if (@readlink($link) === $path) { $fd = (int)basename($link); break; }
    }
    $info = $fd === null ? false : @file_get_contents("/proc/$pid/fdinfo/$fd");
    return is_string($info) && preg_match('/^pos:\s+(\d+)/m', $info, $match) ? (int)$match[1] : null;
}

function cr_restore(string $dir, string $bin, array $manifest): void
{
    $file = "$dir/backup.sql";
    // ON_ERROR_STOP stays off because the fresh instance already has its bootstrap role: the dump's own
    // CREATE ROLE postgres is the single expected error, and its ALTER ROLE still applies the saved settings.
    // Any other error terminates psql once its stderr is read, so later statements may already have run;
    // the attempt is then marked failed and never ready.
    $process = @proc_open(["$bin/psql", '-X', '-q', '-h', "$dir/run", '-p', (string)CR_PORT, '-U', 'postgres', '-d', 'postgres', '-v', 'ON_ERROR_STOP=0', '-f', $file],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes, $dir,
        ['PATH' => '/usr/bin:/bin', 'LANG' => 'C.UTF-8', 'PGOPTIONS' => '-c client_min_messages=warning']);
    if (!is_resource($process)) throw new ClusterRecoveryException('Could not start psql.');
    $log = fopen("$dir/restore.log", 'ae');
    $pid = (int)proc_get_status($process)['pid'];
    $fd = null; $buffer = ''; $logged = 0; $allowed = false; $failure = null; $cancelled = false; $terminated = false; $reported = 0;
    $check = static function (string $line) use ($log, &$logged, &$allowed, &$failure): void {
        if ($log && $logged < 4 << 20) $logged += (int)fwrite($log, $line . "\n");
        if (preg_match('/:\s*(ERROR|FATAL|PANIC|error):\s+(.*)$/', $line, $match)) {
            if ($match[1] === 'ERROR' && trim($match[2]) === 'role "postgres" already exists' && !$allowed) $allowed = true;
            else $failure ??= $line;
        }
    };
    stream_set_blocking($pipes[2], false);
    while (!feof($pipes[2])) {
        $read = [$pipes[2]]; $write = $except = null;
        if (@stream_select($read, $write, $except, 2) > 0) {
            $buffer .= (string)fread($pipes[2], 65536);
            while (($end = strpos($buffer, "\n")) !== false || strlen($buffer) > 65536) {
                $check(substr($buffer, 0, $end === false ? 65536 : $end));
                $buffer = substr($buffer, $end === false ? 65536 : $end + 1);
            }
        }
        $cancelled = $cancelled || !empty($GLOBALS['cr_signal']) || is_file("$dir/cancel");
        if (($failure !== null || $cancelled) && !$terminated) { proc_terminate($process, SIGTERM); $terminated = true; }
        if (time() - $reported >= 5 && !$terminated) {
            $position = cr_read_position($pid, $file, $fd);
            if ($position !== null) cr_update_status($dir, ['progress' => ['done' => min($position, $manifest['bytes']), 'total' => $manifest['bytes']]]);
            $reported = time();
        }
    }
    // A final message without a trailing newline still counts.
    if ($buffer !== '') $check($buffer);
    fclose($pipes[2]);
    if ($log) fclose($log);
    $code = proc_close($process);
    if ($cancelled) throw new CrCancelled();
    if ($failure === null && $code !== 0) $failure = "psql exited with status $code";
    if ($failure !== null) {
        $message = preg_match('/^psql:[^:]*:(\d+):\s*(.*)$/', $failure, $match) ? 'Restore failed at SQL line ' . $match[1] . ': ' . $match[2] : $failure;
        if (stripos($failure, 'No space left') !== false) $message = 'The disk filled up during the restore. Free space, discard this attempt and start again.';
        throw new ClusterRecoveryException(cr_safe_text($message));
    }
}

function cr_connect(string $dir, string $database)
{
    $quote = static fn(string $value): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    $conn = @pg_connect('host=' . $quote("$dir/run") . ' port=' . CR_PORT . ' dbname=' . $quote($database) . ' user=postgres connect_timeout=10', PGSQL_CONNECT_FORCE_NEW);
    if (!$conn) throw new RuntimeException('Could not connect to the private instance.');
    return $conn;
}

function cr_fetch_all($conn, string $sql): array
{
    $result = @pg_query($conn, $sql);
    if (!$result) throw new RuntimeException('Inspection query failed.');
    return pg_fetch_all($result) ?: [];
}

// Each product database is inspected independently; one unreadable database does not hide the others.
function cr_inspect(string $dir): array
{
    $conn = cr_connect($dir, 'postgres');
    $databases = array_map(static fn(array $row): array => ['name' => $row['name'], 'bytes' => (int)$row['bytes']],
        cr_fetch_all($conn, 'SELECT datname AS name, pg_database_size(oid) AS bytes FROM pg_database WHERE NOT datistemplate ORDER BY datname'));
    $roles = (int)cr_fetch_all($conn, "SELECT count(*) AS n FROM pg_roles WHERE rolname !~ '^pg_'")[0]['n'];
    pg_close($conn);
    $names = array_column($databases, 'name');
    $products = [];
    foreach (CR_PRODUCT_DATABASES as $key => [$label, $database]) {
        $item = ['key' => $key, 'label' => $label, 'database' => $database, 'present' => in_array($database, $names, true)];
        if ($item['present']) {
            try {
                $db = cr_connect($dir, $database);
                $has = cr_fetch_all($db, "SELECT to_regclass('public.eventlog') IS NOT NULL AND EXISTS (SELECT 1 FROM information_schema.columns
                    WHERE table_schema='public' AND table_name='eventlog' AND column_name='gamets') AS ok")[0]['ok'] === 't';
                $item['eventlog'] = $has;
                if ($has) {
                    $row = cr_fetch_all($db, 'SELECT count(*) AS events, min(gamets) FILTER (WHERE gamets > 0) AS first, max(gamets) AS last FROM public.eventlog')[0];
                    $item += ['events' => (int)$row['events'], 'gamets_first' => $row['first'], 'gamets_last' => $row['last']];
                }
                pg_close($db);
            } catch (Throwable $e) {
                cr_log("Inspection of $database failed: " . $e->getMessage());
                $item['error'] = 'This database could not be inspected.';
            }
        }
        $products[] = $item;
    }
    return ['databases' => $databases, 'roles' => $roles, 'products' => $products];
}

// ---- Operator commands (root) ----

function cr_require_root(): void
{
    if (posix_geteuid() !== 0) throw new ClusterRecoveryException('Run this command with sudo.');
}

function cr_confirm(string $phrase): void
{
    cr_out("To continue, type exactly: $phrase");
    $answer = fgets(STDIN);
    if (!is_string($answer) || trim($answer) !== $phrase) throw new ClusterRecoveryException('Confirmation did not match. Nothing was changed.');
}

function cr_setup(string $owner): void
{
    cr_require_root();
    $account = posix_getpwnam($owner);
    $root = cr_root();
    if (!$account || $account['uid'] === 0) throw new ClusterRecoveryException("Unknown or unsuitable account: $owner");
    if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $root) || str_contains($root, '..') || str_starts_with($root . '/', '/var/www/')) throw new ClusterRecoveryException('Choose a recovery folder outside the web root.');
    if (is_link($root)) throw new ClusterRecoveryException('The recovery folder must not be a symbolic link.');
    if (!is_dir($root) && !mkdir($root, 0700, true)) throw new ClusterRecoveryException('Could not create the recovery folder.');
    if (!chown($root, $account['uid']) || !chgrp($root, $account['gid']) || !chmod($root, 0700)) throw new ClusterRecoveryException('Could not set recovery folder ownership.');
    cr_out("Recovery folder ready: $root (owner $owner, mode 0700).");
}

function cr_production(): array
{
    $configs = glob('/etc/postgresql/*/main/postgresql.conf') ?: [];
    if (count($configs) !== 1) throw new ClusterRecoveryException('Expected exactly one Debian PostgreSQL cluster named "main".');
    $text = (string)file_get_contents($configs[0]);
    $major = basename(dirname($configs[0], 2));
    $data = preg_match("/^\s*data_directory\s*=\s*'([^']+)'/m", $text, $match) ? $match[1] : "/var/lib/postgresql/$major/main";
    $port = preg_match('/^\s*port\s*=\s*(\d+)/m', $text, $match) ? $match[1] : '5432';
    $postgres = posix_getpwnam('postgres');
    if (!$postgres || !is_dir($data) || is_link($data)) throw new ClusterRecoveryException('The production PostgreSQL data folder was not found.');
    return ['major' => $major, 'data' => rtrim($data, '/'), 'port' => $port, 'uid' => $postgres['uid']];
}

function cr_cluster(array $production, string $action): bool
{
    $command = ['pg_ctlcluster'];
    if ($action === 'stop') array_push($command, '--mode', 'fast');
    $code = cr_run(array_merge($command, [$production['major'], 'main', $action]), $output);
    if ($action === 'status') return $code === 0;
    if ($code !== 0) cr_out(trim($output));
    if ($action === 'stop') return !cr_cluster($production, 'status');
    for ($i = 0; $i < 60; $i++) {
        if (cr_run(['pg_isready', '-q', '-p', $production['port']]) === 0) return true;
        sleep(1);
    }
    return false;
}

function cr_list_databases(array $production): void
{
    cr_run(['runuser', '-u', 'postgres', '--', 'psql', '-X', '-At', '-p', $production['port'], '-d', 'postgres', '-c',
        'SELECT datname FROM pg_database WHERE NOT datistemplate ORDER BY 1'], $output);
    cr_out('Databases now served: ' . preg_replace('/\s+/', ' ', trim($output)));
}

// Root must not follow anything the web account placed in its folder before handing it to postgres.
function cr_check_tree(string $path, int $uid): void
{
    $pending = [$path];
    while ($pending) {
        $current = array_pop($pending);
        $stat = lstat($current);
        $type = $stat ? $stat['mode'] & 0170000 : 0;
        if (!$stat || $stat['uid'] !== $uid || !in_array($type, [0040000, 0100000], true) || ($type === 0100000 && $stat['nlink'] !== 1)) {
            throw new ClusterRecoveryException("Unexpected file in the recovered data folder: $current. Nothing was changed.");
        }
        if ($type === 0040000) foreach (scandir($current) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') $pending[] = "$current/$entry";
    }
}

function cr_mark_activated(string $dir, int $uid, array $changes): void
{
    $temporary = tempnam($dir, '.status-');
    $status = array_replace(cr_read_json("$dir/status.json") ?? [], $changes, ['updated' => time()]);
    if ($temporary === false || file_put_contents($temporary, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false
        || !chown($temporary, $uid) || !rename($temporary, "$dir/status.json")) cr_out('Warning: could not update the Dashboard status for this job.');
}

// Root opens web-owned lock files read-only and only when the opened descriptor is the regular, singly linked,
// web-owned file it checked by name, so it never follows, creates or alters a file the web account substituted.
function cr_root_open_lock(string $path, int $uid)
{
    $named = @lstat($path);
    $handle = $named && ($named['mode'] & 0170000) === 0100000 ? @fopen($path, 're') : false;
    $stat = $handle ? fstat($handle) : false;
    if (!$stat || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || $stat['uid'] !== $uid
        || $named['uid'] !== $uid || $stat['ino'] !== $named['ino'] || $stat['dev'] !== $named['dev']) {
        if ($handle) fclose($handle);
        throw new ClusterRecoveryException('The recovery lock file is missing or unexpected. Nothing was changed.');
    }
    return $handle;
}

function cr_root_worker_alive(string $dir, int $uid): bool
{
    $lock = cr_root_open_lock("$dir/worker.lock", $uid);
    $free = flock($lock, LOCK_SH | LOCK_NB);
    if ($free) flock($lock, LOCK_UN);
    fclose($lock);
    return !$free;
}

// Root takes the Dashboard's control lock so a browser start or discard cannot run during activation or
// rollback. A lock it creates is handed to the web account only if the name still points at the created file.
function cr_root_control_lock(array $owner)
{
    $path = cr_root() . '/control.lock';
    $handle = @fopen($path, 'xe');
    if ($handle) {
        $stat = fstat($handle);
        $named = @lstat($path);
        $same = $stat && $named && $stat['ino'] === $named['ino'] && $stat['dev'] === $named['dev'];
        fclose($handle);
        if (!$same || !lchown($path, $owner['uid']) || !lchgrp($path, $owner['gid'])) {
            if ($same) @unlink($path);
            throw new ClusterRecoveryException('The recovery lock file is missing or unexpected. Nothing was changed.');
        }
    }
    $handle = cr_root_open_lock($path, $owner['uid']);
    if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); throw new ClusterRecoveryException('Another recovery action is running. Try again shortly. Nothing was changed.'); }
    return $handle;
}

function cr_with_control_lock(?array $owner, callable $action): void
{
    $lock = $owner ? cr_root_control_lock($owner) : null;
    try { $action(); } finally { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } }
}

function cr_activate(string $id): void
{
    cr_require_root();
    $root = cr_root();
    $dir = cr_job_dir($id);
    $owner = is_link($root) ? false : @stat($root);
    if (!$owner) throw new ClusterRecoveryException('Recovery job not found.');
    cr_with_control_lock($owner, static fn() => cr_activate_locked($id, $dir, $owner));
}

function cr_activate_locked(string $id, string $dir, array $owner): void
{
    $jobStat = @lstat($dir);
    if (!$jobStat || ($jobStat['mode'] & 0170000) !== 0040000 || $jobStat['uid'] !== $owner['uid']) throw new ClusterRecoveryException('Recovery job not found.');
    $status = cr_read_json("$dir/status.json") ?? [];
    $manifest = cr_read_json("$dir/job.json") ?? [];
    if (($status['state'] ?? '') !== 'ready' || cr_root_worker_alive($dir, $owner['uid']) || cr_instance_running($dir)) throw new ClusterRecoveryException('This recovery is not ready for activation.');
    $production = cr_production();
    $data = "$dir/data";
    if (trim((string)@file_get_contents("$data/PG_VERSION")) !== $production['major']) throw new ClusterRecoveryException('The recovered copy uses a different PostgreSQL major version than production.');
    cr_run(["/usr/lib/postgresql/{$production['major']}/bin/pg_controldata", $data], $control);
    if (!preg_match('/^Database cluster state:\s+shut down$/m', $control)) throw new ClusterRecoveryException('The recovered copy was not shut down cleanly. Nothing was changed.');
    if ((stat($data)['dev'] ?? null) !== (stat(dirname($production['data']))['dev'] ?? null)) {
        throw new ClusterRecoveryException('The recovery folder is on a different filesystem from ' . $production['data'] . '. Move it to the same filesystem first.');
    }
    cr_check_tree($data, $owner['uid']);
    $retained = $production['data'] . '.before-recovery-' . date('Ymd-His');
    cr_out('Recovered from backup: ' . ($manifest['filename'] ?? 'unknown'));
    foreach ($status['products'] ?? [] as $product) {
        cr_out(sprintf('  %s (%s): %s', $product['label'], $product['database'], empty($product['present']) ? 'not in backup' : number_format((int)($product['events'] ?? 0)) . ' events'));
    }
    cr_out('This will stop PostgreSQL ' . $production['major'] . '/main, move ' . $production['data'] . ' to ' . $retained
        . ', put the recovered copy in its place and start PostgreSQL again. Close every game and stop mod servers first.');
    cr_confirm("ACTIVATE $id");
    if (!cr_cluster($production, 'stop')) throw new ClusterRecoveryException('PostgreSQL did not stop. Nothing was changed.');
    if (!rename($production['data'], $retained)) { cr_cluster($production, 'start'); throw new ClusterRecoveryException('Could not set the current cluster aside. PostgreSQL was restarted unchanged.'); }
    if (!rename($data, $production['data'])) {
        rename($retained, $production['data']);
        cr_cluster($production, 'start');
        throw new ClusterRecoveryException('Could not move the recovered copy. The original cluster was put back and restarted.');
    }
    if (cr_run(['chown', '-R', '--no-dereference', 'postgres:postgres', '--', $production['data']], $output) !== 0 || !chmod($production['data'], 0700) || !cr_cluster($production, 'start')) {
        cr_out('The recovered copy did not start. Restoring the original cluster.');
        cr_cluster($production, 'stop');
        $rejected = $production['data'] . '.rejected-recovery-' . date('Ymd-His');
        if (rename($production['data'], $rejected) && rename($retained, $production['data']) && cr_cluster($production, 'start')) {
            throw new ClusterRecoveryException("The original cluster is running again. The failed copy was kept at $rejected.");
        }
        throw new ClusterRecoveryException("Automatic rollback failed. The original cluster is at $retained. Check the PostgreSQL log before retrying.");
    }
    cr_list_databases($production);
    cr_mark_activated($dir, $owner['uid'], ['state' => 'activated', 'message' => 'Activated by an operator. The previous cluster was kept.',
        'activated' => ['at' => time(), 'retained' => basename($retained)]]);
    cr_out("Activation complete. The previous cluster is kept at $retained.");
    cr_out('Load the matching game saves only after checking Playthrough Saves. To undo: sudo php ' . cr_cli() . ' rollback ' . basename($retained));
}

function cr_rollback(string $name): void
{
    cr_require_root();
    $root = cr_root();
    $owner = !is_link($root) && is_dir($root) ? stat($root) : null;
    cr_with_control_lock($owner ?: null, static fn() => cr_rollback_locked($name));
}

function cr_rollback_locked(string $name): void
{
    $production = cr_production();
    if (!preg_match('/^' . preg_quote(basename($production['data']), '/') . '\.before-recovery-\d{8}-\d{6}$/D', $name)) throw new ClusterRecoveryException('Name a kept cluster folder such as main.before-recovery-20260101-120000.');
    $retained = dirname($production['data']) . '/' . $name;
    $stat = @lstat($retained);
    if (!$stat || ($stat['mode'] & 0170000) !== 0040000 || $stat['uid'] !== $production['uid']
        || trim((string)@file_get_contents("$retained/PG_VERSION")) !== $production['major']) throw new ClusterRecoveryException('The kept cluster folder was not found or is not a PostgreSQL ' . $production['major'] . ' cluster.');
    $rejected = $production['data'] . '.rejected-recovery-' . date('Ymd-His');
    cr_out("This will stop PostgreSQL, move the current cluster to $rejected, restore $retained and start PostgreSQL again.");
    cr_confirm("ROLLBACK $name");
    if (!cr_cluster($production, 'stop')) throw new ClusterRecoveryException('PostgreSQL did not stop. Nothing was changed.');
    if (!rename($production['data'], $rejected)) { cr_cluster($production, 'start'); throw new ClusterRecoveryException('Could not set the current cluster aside. PostgreSQL was restarted unchanged.'); }
    if (!rename($retained, $production['data'])) { rename($rejected, $production['data']); cr_cluster($production, 'start'); throw new ClusterRecoveryException('Could not restore the kept cluster. The current cluster was restarted.'); }
    if (!cr_cluster($production, 'start')) throw new ClusterRecoveryException("PostgreSQL did not start after rollback. The replaced cluster is at $rejected. Check the PostgreSQL log.");
    cr_list_databases($production);
    cr_out("Rollback complete. The replaced cluster was kept at $rejected; delete it only after checking your data.");
}

$command = $argv[1] ?? '';
try {
    if ($command === 'worker' && cr_job_id_valid($argv[2] ?? null)) exit(cr_worker($argv[2]));
    if ($command === 'status') { cr_out(json_encode(cr_view(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); exit(0); }
    if ($command === 'setup') { cr_setup(($argv[2] ?? '') === '--owner' && isset($argv[3]) ? $argv[3] : 'www-data'); exit(0); }
    if ($command === 'activate' && cr_job_id_valid($argv[2] ?? null)) { cr_activate($argv[2]); exit(0); }
    if ($command === 'rollback' && isset($argv[2])) { cr_rollback($argv[2]); exit(0); }
    fwrite(STDERR, "Usage: php cluster_recovery_cli.php setup [--owner www-data] | status | activate JOB_ID | rollback main.before-recovery-YYYYMMDD-HHMMSS\n"
        . "Set DWEMER_CLUSTER_RECOVERY_ROOT to use a folder other than " . cr_root() . ".\n");
    exit(64);
} catch (ClusterRecoveryException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
