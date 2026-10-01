<?php
// Isolated recovery of full-cluster (pg_dumpall) backups. A background worker owned by the web
// account restores the dump into a private PostgreSQL instance with no TCP listener. Nothing here
// connects to the live cluster; activating a recovered copy is an operator-run root CLI step.
require_once __DIR__ . '/cluster_backup.php';

class ClusterRecoveryException extends RuntimeException {}

const CR_PORT = 55432; // Names the private socket only; the instance sets listen_addresses=''.
const CR_ACTIVE = ['queued', 'staging', 'verifying', 'initializing', 'restoring', 'inspecting', 'stopping'];
const CR_STAGES = ['staging' => 'Protect backup copy', 'verifying' => 'Check backup file', 'initializing' => 'Create isolated PostgreSQL',
    'restoring' => 'Restore SQL', 'inspecting' => 'Inspect recovered data', 'stopping' => 'Stop isolated PostgreSQL'];
const CR_STATE_LABELS = ['queued' => 'Starting', 'staging' => 'Protecting backup copy', 'verifying' => 'Checking backup file',
    'initializing' => 'Creating isolated PostgreSQL', 'restoring' => 'Restoring SQL', 'inspecting' => 'Inspecting recovered data',
    'stopping' => 'Stopping isolated PostgreSQL', 'ready' => 'Recovered copy ready', 'failed' => 'Failed', 'cancelled' => 'Cancelled',
    'interrupted' => 'Interrupted', 'activated' => 'Activated by operator'];
const CR_PRODUCT_DATABASES = ['chim' => ['CHIM', 'dwemer'], 'stobe' => ['STOBE', 'stobe'], 'dialectic' => ['DIALECTIC', 'dialectic']];

function cr_root(): string
{
    $root = getenv('DWEMER_CLUSTER_RECOVERY_ROOT');
    return is_string($root) && $root !== '' ? rtrim($root, '/') : '/var/lib/dwemer-cluster-recovery';
}

function cr_cli(): string { return __DIR__ . '/cluster_recovery_cli.php'; }

function cr_setup_command(): string { return 'sudo php ' . cr_cli() . ' setup'; }

// The recovery root holds unencrypted database files: private, owned by this account, outside the web root.
function cr_root_problem(): ?string
{
    $root = cr_root();
    if (!function_exists('posix_geteuid')) return 'The PHP posix extension is required for recovery.';
    if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $root) || str_contains($root, '..')) return 'The recovery folder path is not supported.';
    clearstatcache(true, $root);
    if (!is_dir($root) || is_link($root)) return 'Recovery storage is not prepared. An administrator must run: ' . cr_setup_command();
    $stat = stat($root);
    if ($stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0077) !== 0) {
        return 'Recovery storage must be private to the web account (mode 0700). An administrator must run: ' . cr_setup_command();
    }
    $web = realpath(dirname(__DIR__, 2));
    $real = realpath($root);
    if ($web && $real && ($real === $web || str_starts_with($real . '/', rtrim($web, '/') . '/'))) return 'Recovery storage must be outside the web folder.';
    return null;
}

// Prefer the newest installed server major; the operator CLI requires it to match production on activation.
function cr_pg_bin(): ?array
{
    $found = null;
    foreach (glob('/usr/lib/postgresql/*/bin', GLOB_ONLYDIR) ?: [] as $bin) {
        $major = (int)basename(dirname($bin));
        foreach (['initdb', 'pg_ctl', 'postgres', 'psql', 'pg_config'] as $tool) if (!is_executable("$bin/$tool")) continue 2;
        if (!$found || $major > $found['major']) $found = ['bin' => $bin, 'major' => $major];
    }
    return $found;
}

function cr_job_id_valid(mixed $id): bool { return is_string($id) && preg_match('/^\d{8}-\d{6}-[a-f0-9]{8}$/D', $id) === 1; }

function cr_job_dir(string $id): string
{
    if (!cr_job_id_valid($id)) throw new ClusterRecoveryException('Invalid recovery job.');
    return cr_root() . '/jobs/' . $id;
}

function cr_read_json(string $path): ?array
{
    if (is_link($path) || !is_file($path)) return null;
    $data = json_decode((string)@file_get_contents($path, false, null, 0, 1048576), true);
    return is_array($data) ? $data : null;
}

// Atomic replace so readers never see a partial status file.
function cr_write_json(string $path, array $data): void
{
    $temporary = @tempnam(dirname($path), '.status-');
    if ($temporary === false) throw new RuntimeException('Could not write recovery status.');
    @chmod($temporary, 0600);
    if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)) === false
        || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not write recovery status.');
    }
}

function cr_update_status(string $dir, array $changes): array
{
    $status = array_replace(cr_read_json("$dir/status.json") ?? [], $changes, ['updated' => time()]);
    cr_write_json("$dir/status.json", $status);
    return $status;
}

// Lock files use close-on-exec so PostgreSQL children never inherit the worker's liveness lock.
function cr_lock(string $path, int $mode)
{
    $handle = @fopen($path, 'ce');
    if (!$handle) throw new RuntimeException('Could not open a recovery lock.');
    if (!flock($handle, $mode | LOCK_NB)) { fclose($handle); return null; }
    return $handle;
}

function cr_worker_alive(string $dir): bool
{
    $lock = cr_lock("$dir/worker.lock", LOCK_SH);
    if (!$lock) return true;
    flock($lock, LOCK_UN);
    fclose($lock);
    return false;
}

function cr_current_id(): ?string
{
    $id = trim((string)@file_get_contents(cr_root() . '/current', false, null, 0, 64));
    return cr_job_id_valid($id) && is_dir(cr_job_dir($id)) ? $id : null;
}

// Runs a fixed argument vector without a shell. Output is truncated to keep logs and errors bounded.
function cr_run(array $command, ?string &$output = null, ?string $cwd = null, array $env = []): int
{
    $process = @proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd,
        ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8'] + $env);
    if (!is_resource($process)) { $output = 'Could not start ' . basename($command[0]) . '.'; return 127; }
    $output = '';
    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk === false) break;
        $output = substr($output . $chunk, -16384);
    }
    fclose($pipes[1]);
    return proc_close($process);
}

function cr_instance_running(string $dir): bool { clearstatcache(true, "$dir/data/postmaster.pid"); return is_file("$dir/data/postmaster.pid"); }

function cr_stop_instance(string $dir, string $bin): bool
{
    if (!cr_instance_running($dir)) return true;
    foreach (['fast', 'immediate'] as $mode) {
        cr_run(["$bin/pg_ctl", 'stop', '-D', "$dir/data", '-m', $mode, '-w', '-t', '120']);
        if (!cr_instance_running($dir)) return true;
    }
    return false;
}

function cr_job_state(string $dir): ?array
{
    $status = cr_read_json("$dir/status.json");
    if (!$status) return null;
    // A lost worker leaves an active state behind; report it rather than pretending it continues.
    if (in_array($status['state'] ?? '', CR_ACTIVE, true) && !cr_worker_alive($dir)
        && (($status['state'] ?? '') !== 'queued' || time() - (int)($status['updated'] ?? 0) > 60)) {
        $status['interrupted_from'] = $status['state'];
        $status['state'] = 'interrupted';
        $status['error'] = 'The recovery worker stopped unexpectedly (for example, the server restarted). Discard this attempt and start again.';
    }
    return $status;
}

function cr_php_cli(): string
{
    foreach ([PHP_BINARY, '/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
        if ($candidate !== '' && str_contains(strtolower(basename($candidate)), 'php') && is_executable($candidate)) return $candidate;
    }
    throw new ClusterRecoveryException('The PHP command-line program is not installed.');
}

function cr_free_bytes(): ?int { $free = @disk_free_space(cr_root()); return $free === false ? null : (int)$free; }

// Restored databases include indexes and per-row overhead; reserve 1.6x SQL size plus slack.
function cr_required_bytes(int $sqlBytes, bool $copy): int { return (int)($sqlBytes * 1.6) + 2 * 1024 ** 3 + ($copy ? $sqlBytes : 0); }

/** Stage a server-side cluster backup and launch the background worker. $path must already be allowlisted. */
function cr_start(string $path, string $filename, string $source): array
{
    if ($problem = cr_root_problem()) throw new ClusterRecoveryException($problem);
    $control = cr_lock(cr_root() . '/control.lock', LOCK_EX);
    if (!$control) throw new ClusterRecoveryException('Another recovery action is running. Try again shortly.');
    try {
        if (cr_current_id()) throw new ClusterRecoveryException('A recovery attempt already exists. Cancel or discard it before starting another.');
        if (!dashboardIsClusterBackup($path)) throw new ClusterRecoveryException('This file is not a full PostgreSQL backup.');
        $pg = cr_pg_bin();
        if (!$pg) throw new ClusterRecoveryException('PostgreSQL server programs (initdb, pg_ctl, postgres, psql) are not installed.');
        clearstatcache(true, $path);
        $source_stat = stat($path);
        if (!$source_stat || $source_stat['size'] === 0) throw new ClusterRecoveryException('The backup is empty or unreadable.');
        $id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $dir = cr_root() . '/jobs/' . $id;
        if (!is_dir(cr_root() . '/jobs') && !@mkdir(cr_root() . '/jobs', 0700)) throw new RuntimeException('Could not prepare recovery storage.');
        if (!@mkdir($dir, 0700)) throw new RuntimeException('Could not prepare the recovery job.');
        // A hard link keeps the selected bytes even if retention removes the original; otherwise the worker copies them.
        $linked = @link($path, "$dir/backup.sql") && (stat("$dir/backup.sql")['ino'] ?? null) === $source_stat['ino'];
        if (!$linked) @unlink("$dir/backup.sql");
        $required = cr_required_bytes($source_stat['size'], !$linked);
        $free = cr_free_bytes();
        if ($free !== null && $free < $required) {
            @unlink("$dir/backup.sql"); @rmdir($dir);
            throw new ClusterRecoveryException('Not enough free disk space. Recovery needs about ' . cr_format_bytes($required) . '; ' . cr_format_bytes($free) . ' is free.');
        }
        $manifest = ['id' => $id, 'filename' => $filename, 'source' => $source, 'path' => $path, 'bytes' => $source_stat['size'],
            'mtime' => $source_stat['mtime'], 'inode' => $source_stat['ino'], 'device' => $source_stat['dev'], 'linked' => $linked,
            'pg_bin' => $pg['bin'], 'pg_major' => $pg['major'], 'created' => time()];
        cr_write_json("$dir/job.json", $manifest);
        cr_write_json("$dir/status.json", ['state' => 'queued', 'started' => time(), 'updated' => time(), 'message' => 'Starting the recovery worker.']);
        if (file_put_contents(cr_root() . '/current', $id) === false) throw new RuntimeException('Could not record the recovery job.');
        cr_launch($id, $dir);
        return cr_view();
    } finally {
        flock($control, LOCK_UN);
        fclose($control);
    }
}

// Detach into a new session and close inherited descriptors (sessions, Apache sockets, storage locks).
function cr_launch(string $id, string $dir): void
{
    if (@touch("$dir/worker.log")) @chmod("$dir/worker.log", 0600);
    $script = 'for fd in /proc/$$/fd/*; do fd=${fd##*/}; if [ "$fd" -gt 2 ] 2>/dev/null; then eval "exec $fd>&-"; fi; done; exec "$@"';
    $process = @proc_open(['/usr/bin/setsid', '-f', '/bin/bash', '-c', $script, 'dwemer-cluster-recovery', cr_php_cli(), cr_cli(), 'worker', $id],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$dir/worker.log", 'a'], 2 => ['file', "$dir/worker.log", 'a']], $pipes, $dir,
        ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8', 'HOME' => $dir, 'DWEMER_CLUSTER_RECOVERY_ROOT' => cr_root()]);
    if (!is_resource($process) || proc_close($process) !== 0) {
        cr_update_status($dir, ['state' => 'failed', 'error' => 'The recovery worker could not start.', 'finished' => time()]);
        throw new ClusterRecoveryException('The recovery worker could not start. Nothing was changed.');
    }
}

function cr_require_current(string $id): string
{
    if (cr_current_id() !== $id) throw new ClusterRecoveryException('This recovery attempt has changed. Refresh and try again.');
    return cr_job_dir($id);
}

function cr_cancel(string $id): array
{
    $dir = cr_require_current($id);
    $status = cr_job_state($dir);
    if (!in_array($status['state'] ?? '', CR_ACTIVE, true) && ($status['state'] ?? '') !== 'interrupted') throw new ClusterRecoveryException('This recovery is not running.');
    if (file_put_contents("$dir/cancel", (string)time()) === false) throw new RuntimeException('Could not request cancellation.');
    if (!cr_worker_alive($dir)) {
        $manifest = cr_read_json("$dir/job.json") ?? [];
        $stopped = cr_stop_instance($dir, (string)($manifest['pg_bin'] ?? ''));
        cr_update_status($dir, ['state' => 'cancelled', 'finished' => time(), 'error' => $stopped ? null : 'The isolated PostgreSQL instance did not stop. Discard will retry.']);
    }
    return cr_view();
}

function cr_discard(string $id): array
{
    $control = cr_lock(cr_root() . '/control.lock', LOCK_EX);
    if (!$control) throw new ClusterRecoveryException('Another recovery action is running. Try again shortly.');
    try {
        $dir = cr_require_current($id);
        $status = cr_job_state($dir);
        if (in_array($status['state'] ?? '', CR_ACTIVE, true)) throw new ClusterRecoveryException('Cancel the recovery and wait for it to stop before discarding it.');
        $manifest = cr_read_json("$dir/job.json") ?? [];
        if (!cr_stop_instance($dir, (string)($manifest['pg_bin'] ?? ''))) throw new ClusterRecoveryException('The isolated PostgreSQL instance did not stop. Nothing was deleted.');
        // Keep the current pointer until every file is gone, so a partial deletion stays visible and retryable.
        if (!cr_remove_tree($dir)) throw new ClusterRecoveryException('Some recovery files could not be deleted, so this attempt was kept. Try Discard again; an administrator may need to remove it from the recovery folder.');
        if (!@unlink(cr_root() . '/current') && file_exists(cr_root() . '/current')) throw new RuntimeException('Could not clear the recovery job record.');
        return cr_view();
    } finally {
        flock($control, LOCK_UN);
        fclose($control);
    }
}

// Never follows links: a link is removed itself, not its target. Returns whether the path is gone.
function cr_remove_tree(string $path): bool
{
    if (is_link($path) || !is_dir($path)) @unlink($path);
    else {
        foreach (scandir($path) ?: [] as $entry) if ($entry !== '.' && $entry !== '..') cr_remove_tree("$path/$entry");
        @rmdir($path);
    }
    clearstatcache(true, $path);
    return !is_link($path) && !file_exists($path);
}

function cr_format_bytes(int $bytes): string
{
    $unit = $bytes > 0 ? min(4, (int)floor(log($bytes, 1024))) : 0;
    return round($bytes / 1024 ** $unit, 1) . ' ' . ['B', 'KB', 'MB', 'GB', 'TB'][$unit];
}

/** Browser-safe status: no filesystem paths beyond the CLI command, no credentials, no raw logs. */
function cr_view(): array
{
    $problem = cr_root_problem();
    $pg = cr_pg_bin();
    $view = ['preflight' => ['problem' => $problem, 'setup_command' => cr_setup_command(), 'postgres_major' => $pg['major'] ?? null,
        'free_bytes' => $problem ? null : cr_free_bytes()], 'job' => null];
    if ($problem || !($id = cr_current_id())) return $view;
    $dir = cr_job_dir($id);
    $manifest = cr_read_json("$dir/job.json") ?? [];
    $status = cr_job_state($dir) ?? ['state' => 'failed', 'error' => 'The recovery status could not be read.'];
    $state = (string)($status['state'] ?? 'failed');
    $order = array_keys(CR_STAGES);
    $reached = array_search($status['interrupted_from'] ?? $status['stage'] ?? $state, $order, true);
    $stages = [];
    foreach (CR_STAGES as $key => $label) {
        $position = array_search($key, $order, true);
        $done = in_array($state, ['ready', 'activated'], true) || ($reached !== false && $position < $reached);
        $current = $reached !== false && $position === $reached && !$done;
        $stages[] = ['key' => $key, 'label' => $label, 'status' => $done ? 'done' : ($current ? (in_array($state, CR_ACTIVE, true) ? 'current' : 'stopped') : 'waiting')];
    }
    $view['job'] = ['id' => $id, 'state' => $state, 'label' => CR_STATE_LABELS[$state] ?? $state, 'active' => in_array($state, CR_ACTIVE, true),
        'filename' => $manifest['filename'] ?? null, 'source' => $manifest['source'] ?? null, 'bytes' => $manifest['bytes'] ?? null,
        'started' => $status['started'] ?? null, 'updated' => $status['updated'] ?? null, 'finished' => $status['finished'] ?? null,
        'message' => $status['message'] ?? null, 'error' => $status['error'] ?? null, 'progress' => $status['progress'] ?? null,
        'cancel_requested' => is_file("$dir/cancel"), 'staged' => $status['staged'] ?? null, 'sha256' => $status['sha256'] ?? null,
        'postgres_major' => $manifest['pg_major'] ?? null, 'databases' => $status['databases'] ?? [], 'products' => $status['products'] ?? [],
        'stages' => $stages, 'activated' => $status['activated'] ?? null,
        'activation_command' => $state === 'ready' ? 'sudo php ' . cr_cli() . ' activate ' . $id : null];
    $retained = $status['activated']['retained'] ?? '';
    $view['job']['rollback_command'] = $state === 'activated' && is_string($retained) && preg_match('/^[A-Za-z0-9_.-]+$/D', $retained)
        ? 'sudo php ' . cr_cli() . ' rollback ' . $retained : null;
    return $view;
}
