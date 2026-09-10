<?php
require_once __DIR__ . '/cluster_backup.php';
class StorageBackupException extends RuntimeException {}

// Listings use filenames only. Inspect a potentially large SQL file only after an explicit restore.
function sm_backup_scope(string $path, ?string $filename = null, bool $inspect = false, string $fallback = 'chim'): array
{
    $name = strtolower($filename ?? basename($path));
    $cluster = $inspect ? dashboardIsClusterBackup($path) : preg_match('/^(?:auto|manual)_backup_cluster_/', $name) === 1;
    if ($cluster) {
        return ['cluster' => true, 'includes_dwemer' => false, 'includes_stobe' => false, 'includes_dialectic' => false,
            'scope_slug' => 'cluster', 'scope_label' => 'Entire PostgreSQL server' . ($inspect ? '' : ' (from filename)'),
            'scope_short_label' => 'Entire PostgreSQL server', 'badge_class' => 'backup-scope-both',
            'explicit' => $inspect, 'verified' => $inspect];
    }
    $flags = ['dwemer' => false, 'stobe' => false, 'dialectic' => false];
    $explicit = false;
    if ($inspect) {
        $handle = @fopen($path, 'rb');
        if (!$handle) throw new StorageBackupException('The backup file could not be read.');
        try {
            $lineStart = true;
            while (($line = fgets($handle, 1048576)) !== false) {
                $wasLineStart = $lineStart;
                $lineStart = str_ends_with($line, "\n");
                if (!$wasLineStart) continue;
                if (preg_match('/^\\\\connect\s+"?([a-zA-Z0-9_]+)"?\s*$/', trim($line), $match)) {
                    $database = strtolower($match[1]);
                    if (!array_key_exists($database, $flags)) throw new StorageBackupException('This backup connects to an unsupported database. No database was changed.');
                    $flags[$database] = true;
                    $explicit = true;
                } elseif (preg_match('/^\\\\(?:connect|c)\s/', trim($line))) {
                    throw new StorageBackupException('This backup uses an unsupported connection directive. No database was changed.');
                }
            }
            if (!feof($handle)) throw new StorageBackupException('The backup could not be read completely. No database was changed.');
        } finally { fclose($handle); }
    }
    if (!$explicit) {
        $flags['dwemer'] = preg_match('/herika|dwemer|chim/', $name) === 1;
        $flags['stobe'] = str_contains($name, 'stobe');
        $flags['dialectic'] = str_contains($name, 'dialectic');
        // Plain single-database dumps have no connection directives. Use the selected destination.
        if (!in_array(true, $flags, true)) $flags[$fallback === 'dialectic' ? 'dialectic' : 'dwemer'] = true;
        if ($inspect && count(array_filter($flags)) !== 1) {
            throw new StorageBackupException('This filename describes multiple databases, but the SQL does not identify them. No database was changed.');
        }
    }
    $labels = []; $slugs = [];
    foreach (['dwemer' => ['CHIM', 'herikaserver'], 'stobe' => ['STOBE', 'stobeserver'], 'dialectic' => ['DIALECTIC', 'dialecticserver']] as $key => $value) {
        if ($flags[$key]) { $labels[] = $value[0]; $slugs[] = $value[1]; }
    }
    $label = implode(' + ', $labels);
    return ['includes_dwemer' => $flags['dwemer'], 'includes_stobe' => $flags['stobe'], 'includes_dialectic' => $flags['dialectic'],
        'scope_slug' => implode('_', $slugs), 'scope_label' => $label . ($inspect ? '' : ' (from filename)'),
        'scope_short_label' => $label, 'badge_class' => count($labels) > 1 ? 'backup-scope-both' : 'backup-scope-herika',
        'explicit' => $explicit, 'verified' => $inspect];
}
