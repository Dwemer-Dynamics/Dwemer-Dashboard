<?php
require_once __DIR__ . '/data_manager.php';
require_once __DIR__ . '/storage_manager_actions.php';

// Resolve only the established backup directories; never accept paths from a browser.
function sm_backup_directory(string $mod, string $source): string
{
    if ($mod === 'stobe') {
        $root = dm_server_root('StobeServer');
        if (!$root) throw new RuntimeException('STOBE is not installed.');
        return match ($source) {
            'manual' => $root . '/ui/data/manualbackup',
            'db_backups' => $root . '/data/db_backups',
            default => throw new InvalidArgumentException('Unknown backup location.'),
        };
    }
    if ($mod !== 'all') throw new InvalidArgumentException('Use Distro for shared backups.');
    return match ($source) {
        'manual' => dirname(__DIR__) . '/data/manualbackup',
        'automatic' => dirname(__DIR__) . '/data/databasebackups',
        default => throw new InvalidArgumentException('Unknown backup location.'),
    };
}

function sm_backup_file(string $mod, string $source, string $name): string
{
    if ($name === '' || basename($name) !== $name || str_contains($name, '\\') || !preg_match('/\.sql(?:\.gz)?$/i', $name)) {
        throw new InvalidArgumentException('Choose a valid SQL backup.');
    }
    if ($mod === 'all' && (($source === 'automatic' && !str_starts_with($name, 'auto_backup_')) || str_ends_with(strtolower($name), '.gz'))) {
        throw new InvalidArgumentException('Unsupported shared backup.');
    }
    $directory = realpath(sm_backup_directory($mod, $source));
    $file = $directory ? realpath($directory . '/' . $name) : false;
    if (!$file || dirname($file) !== $directory || !is_file($file)) throw new InvalidArgumentException('The backup is no longer available.');
    return $file;
}

// Lists inspect filenames and sizes only, never SQL contents. Render a bounded page.
function sm_backup_list(string $mod, int $offset, string $search): array
{
    $files = [];
    foreach ($mod === 'stobe' ? ['db_backups', 'manual'] : ['automatic', 'manual'] as $source) {
        $directory = sm_backup_directory($mod, $source);
        foreach (is_dir($directory) ? new DirectoryIterator($directory) : [] as $entry) {
            if (!$entry->isFile() || $entry->isLink()) continue;
            $name = $entry->getFilename();
            if (!preg_match($mod === 'stobe' ? '/\.sql(?:\.gz)?$/i' : '/\.sql$/', $name)
                || ($mod === 'all' && $source === 'automatic' && !str_starts_with($name, 'auto_backup_'))
                || ($search !== '' && stripos($name, $search) === false)) continue;
            $files[] = ['filename' => $name, 'source' => $source, 'size' => $entry->getSize(), 'modified' => $entry->getMTime(),
                'scope' => $mod === 'stobe' ? 'STOBE' : sm_backup_scope('', $name)['scope_short_label'],
                'can_download' => $mod === 'stobe' || $source === 'automatic',
                'can_delete' => $mod === 'stobe' || $source === 'automatic'];
        }
    }
    usort($files, static fn($a, $b) => $b['modified'] <=> $a['modified']);
    return ['items' => array_slice($files, $offset, 50), 'total' => count($files), 'offset' => $offset, 'limit' => 50];
}

// Version rows remain product-scoped and read-only; reset handlers live with the servers.
function sm_version_list(string $mod, int $offset, string $search): array
{
    $product = dm_products()[$mod] ?? null;
    if (!$product || !($root = dm_server_root($product['dir']))) throw new InvalidArgumentException('Choose an installed mod.');
    $conn = dm_connect(dm_connection_settings($mod, $root));
    try {
        dm_query($conn, 'BEGIN READ ONLY');
        dm_query($conn, "SET LOCAL statement_timeout='2500ms'");
        $exists = pg_fetch_result(dm_query($conn, "SELECT to_regclass('public.database_versioning') IS NOT NULL"), 0, 0) === 't';
        $rows = []; $total = 0;
        if ($exists) {
            $filter = "strpos(lower(coalesce(to_jsonb(v)->>'tablename',to_jsonb(v)->>'table_name','')), lower($1)) > 0";
            $total = (int)pg_fetch_result(dm_query($conn, "SELECT count(*) FROM public.database_versioning v WHERE $filter", [$search]), 0, 0);
            $rows = pg_fetch_all(dm_query($conn, "SELECT coalesce(to_jsonb(v)->>'tablename',to_jsonb(v)->>'table_name','') AS name,
                coalesce(to_jsonb(v)->>'version',to_jsonb(v)->>'version_value','') AS version
                FROM public.database_versioning v WHERE $filter ORDER BY 1 LIMIT 50 OFFSET $2", [$search, $offset])) ?: [];
        }
        dm_query($conn, 'COMMIT');
        return ['items' => $rows, 'total' => $total, 'offset' => $offset, 'limit' => 50, 'available' => $exists];
    } finally { pg_close($conn); }
}
