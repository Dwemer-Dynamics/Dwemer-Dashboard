<?php
// Identify PostgreSQL's full-cluster format without reading database contents into memory.
function dashboardIsClusterBackup(string $path): bool
{
    $header = @file_get_contents($path, false, null, 0, 2048);
    return is_string($header) && preg_match('/^-- PostgreSQL database cluster dump\r?$/m', $header) === 1;
}

// Manual and automatic Distro backups share an unfiltered pg_dumpall export.
function dashboardCreateClusterBackup(string $path, string $host, string $port, string $user, string $password, string &$error = ''): bool
{
    $error = '';
    $temporary = @tempnam(dirname($path), '.cluster-');
    $errors = @tempnam(dirname($path), '.cluster-errors-');
    try {
        if ($temporary === false || $errors === false) throw new RuntimeException('Could not prepare the export file.');
        @chmod($temporary, 0600);
        @chmod($errors, 0600);
        set_time_limit(0);
        $environment = getenv();
        $environment['PGPASSWORD'] = $password;
        $environment['PGCONNECT_TIMEOUT'] = '5';
        $process = @proc_open(
            ['pg_dumpall', '--host', $host, '--port', $port, '--username', $user, '--no-password'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $temporary, 'w'], 2 => ['file', $errors, 'w']],
            $pipes, null, $environment
        );
        if (!is_resource($process)) throw new RuntimeException('Could not start pg_dumpall.');
        $exitCode = proc_close($process);
        clearstatcache(true, $temporary);
        if ($exitCode !== 0 || !dashboardIsClusterBackup($temporary)) {
            throw new RuntimeException('Full PostgreSQL export failed. Check that the backup account can read every database and server role. No partial backup was saved.');
        }
        if (!@rename($temporary, $path)) throw new RuntimeException('Could not save the completed export.');
        return true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return false;
    } finally {
        foreach ([$temporary, $errors] as $file) {
            if (is_string($file) && is_file($file)) @unlink($file);
        }
    }
}
