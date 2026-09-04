<?php
// Read-only adapters: never bootstrap a server UI, run migrations, or load backups here.
function dm_products(): array
{
    return [
        'chim' => ['label' => 'CHIM', 'game' => 'Skyrim', 'dir' => 'HerikaServer', 'meta' => 'chim_meta'],
        'stobe' => ['label' => 'STOBE', 'game' => 'Kenshi', 'dir' => 'StobeServer', 'meta' => 'stobe_meta'],
        'dialectic' => ['label' => 'DIALECTIC', 'game' => 'Fallout: New Vegas', 'dir' => 'DialecticServer', 'meta' => 'dialectic_meta'],
    ];
}

function dm_server_root(string $directory): ?string
{
    foreach ([dirname(__DIR__, 2) . '/' . $directory, '/var/www/html/' . $directory] as $candidate) {
        if (is_file($candidate . '/debug/db_updates.php')) return realpath($candidate) ?: null;
    }
    return null;
}

// Follow each server's actual connection configuration, without its migration/bootstrap code.
function dm_connection_settings(string $mod, string $root): array
{
    $settings = ['host' => 'localhost', 'port' => '5432', 'dbname' => 'dwemer', 'user' => 'dwemer', 'password' => 'dwemer'];
    if ($mod === 'stobe') {
        $settings['dbname'] = 'stobe';
        foreach ($settings as $key => $default) {
            $envKey = 'STOBE_DB_' . ($key === 'dbname' ? 'NAME' : strtoupper($key));
            $settings[$key] = getenv($envKey) ?: $default;
        }
    } elseif ($mod === 'dialectic') {
        $config = (static function (string $serverRoot): array {
            foreach (['conf.sample.php', 'conf.php'] as $file) {
                if (is_file($serverRoot . '/conf/' . $file)) include $serverRoot . '/conf/' . $file;
            }
            return get_defined_vars();
        })($root);
        $settings['host'] = '127.0.0.1';
        $settings['dbname'] = 'dialectic';
        foreach ($settings as $key => $default) {
            $configKey = 'DIALECTIC_DB_' . ($key === 'dbname' ? 'NAME' : strtoupper($key));
            $envValue = getenv($configKey);
            $settings[$key] = ($envValue !== false && trim($envValue) !== '')
                ? trim($envValue) : (trim((string)($config[$configKey] ?? '')) ?: $default);
        }
    }
    return $settings;
}

function dm_connect(array $settings)
{
    $parts = ['connect_timeout=3'];
    foreach ($settings as $key => $value) {
        $parts[] = $key . "='" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    }
    $conn = @pg_connect(implode(' ', $parts), PGSQL_CONNECT_FORCE_NEW);
    if (!$conn) throw new RuntimeException('Database unavailable');
    return $conn;
}

function dm_query($conn, string $sql, array $params = [])
{
    $result = @pg_query_params($conn, $sql, $params);
    if (!$result) throw new RuntimeException('Data overview query failed');
    return $result;
}

// Only catalog metadata and a bounded page of saved snapshot metadata are read.
function dm_overview($conn, string $mod, int $offset, string $search): array
{
    $product = dm_products()[$mod];
    dm_query($conn, 'BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
    try {
        dm_query($conn, "SET LOCAL statement_timeout='2500ms'");
        dm_query($conn, "SET LOCAL lock_timeout='250ms'");
        $database = pg_fetch_assoc(dm_query($conn, 'SELECT pg_database_size(current_database()) AS bytes'));
        $tables = pg_fetch_all(dm_query($conn, "SELECT c.relname, pg_total_relation_size(c.oid) AS bytes, c.reltuples
            FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
            WHERE n.nspname='public' AND c.relkind IN ('r','m')")) ?: [];
        $categories = [
            'events' => ['key' => 'events', 'label' => 'Events', 'bytes' => 0, 'rows_estimate' => 0],
            'memory' => ['key' => 'memory', 'label' => 'Memories & knowledge', 'bytes' => 0, 'rows_estimate' => 0],
            'diagnostics' => ['key' => 'diagnostics', 'label' => 'Debug logs in the database', 'bytes' => 0, 'rows_estimate' => 0],
            'other' => ['key' => 'other', 'label' => 'Other live data', 'bytes' => 0, 'rows_estimate' => 0],
        ];
        foreach ($tables as $table) {
            $name = $table['relname'];
            $category = 'other';
            if ($name === 'eventlog') $category = 'events';
            elseif (in_array($name, ['log', 'audit_request', 'deliveredresponselog'], true)) $category = 'diagnostics';
            elseif (preg_match('/^(memory|memories|oghma|worldknowledge|diary|diaries)(_|$)/', $name)) $category = 'memory';
            $categories[$category]['bytes'] += (int)$table['bytes'];
            if ((float)$table['reltuples'] < 0) $categories[$category]['rows_estimate'] = null;
            elseif ($categories[$category]['rows_estimate'] !== null) $categories[$category]['rows_estimate'] += (int)round((float)$table['reltuples']);
        }
        $liveBytes = array_sum(array_column($categories, 'bytes'));
        $categories['stored'] = ['key' => 'stored', 'label' => 'Snapshots & other database storage',
            'bytes' => max(0, (int)$database['bytes'] - $liveBytes), 'rows_estimate' => null];
        $meta = $product['meta'];
        $exists = pg_fetch_assoc(dm_query($conn, 'SELECT to_regclass($1) IS NOT NULL AS present', [$meta . '.playthrough_profiles']));
        // Old Stobe installations used chim_meta. Reading it must not trigger the rename migration.
        if ($exists['present'] !== 't' && $mod === 'stobe') {
            $meta = 'chim_meta';
            $exists = pg_fetch_assoc(dm_query($conn, 'SELECT to_regclass($1) IS NOT NULL AS present', [$meta . '.playthrough_profiles']));
        }
        $snapshots = ['metadata_available' => $exists['present'] === 't', 'total' => 0, 'all_total' => 0, 'offset' => $offset, 'limit' => 50, 'items' => []];
        $loaded = null;
        if ($snapshots['metadata_available']) {
            $table = pg_escape_identifier($conn, $meta) . '.playthrough_profiles';
            // JSON access tolerates optional columns on older server versions; never read saved schemas.
            $filter = "strpos(lower(coalesce(to_jsonb(p)->>'name','') || ' ' || coalesce(to_jsonb(p)->>'player_name','')), lower($1)) > 0";
            $count = pg_fetch_assoc(dm_query($conn, "SELECT count(*) AS all_total, count(*) FILTER (WHERE $filter) AS total FROM $table p", [$search]));
            $snapshots['total'] = (int)$count['total'];
            $snapshots['all_total'] = (int)$count['all_total'];
            $active = pg_fetch_assoc(dm_query($conn, "SELECT to_jsonb(p)->>'name' AS name FROM $table p
                WHERE to_jsonb(p)->>'is_active'='true' ORDER BY p.id DESC LIMIT 1"));
            $loaded = $active ? $active['name'] : null;
            $rows = pg_fetch_all(dm_query($conn, "SELECT to_jsonb(p) AS metadata FROM $table p
                WHERE $filter ORDER BY p.id DESC LIMIT 50 OFFSET $2", [$search, $offset])) ?: [];
            foreach ($rows as $row) {
                $p = json_decode($row['metadata'], true, 512, JSON_THROW_ON_ERROR);
                $kind = 'Unclassified';
                if (in_array($p['retention_kind'] ?? '', ['manual', 'dragon_break'], true)) {
                    $kind = ['manual' => 'Manual', 'dragon_break' => 'Automatic rollback'][$p['retention_kind']];
                } elseif ((int)($p['rollback_delta_days'] ?? 0) > 0) $kind = 'Rollback';
                $snapshots['items'][] = [
                    'id' => (int)$p['id'], 'name' => (string)($p['name'] ?? ''),
                    'player' => $p['player_name'] ?? null, 'created_at' => $p['created_at'] ?? null,
                    'game_date' => null, 'gamets' => $p['last_gamets'] ?? null,
                    'size_bytes' => isset($p['size_bytes']) ? (int)$p['size_bytes'] : null,
                    'event_count' => isset($p['eventlog_count']) ? (int)$p['eventlog_count'] : null,
                    'loaded' => ($p['is_active'] ?? false) === true, 'kind' => $kind,
                    'pinned' => $p['retention_pinned'] ?? null,
                ];
            }
        }
        dm_query($conn, 'COMMIT');
        return ['live' => ['database_bytes' => (int)$database['bytes'], 'categories' => array_values($categories), 'loaded_snapshot' => $loaded], 'snapshots' => $snapshots];
    } catch (Throwable $e) {
        @pg_query($conn, 'ROLLBACK');
        throw $e;
    }
}

// Keep links on this origin; no request-supplied server addresses or mutation URLs.
function dm_tools(string $mod, string $root): array
{
    $product = dm_products()[$mod];
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $prefix = '';
    if (preg_match('#^((?:/[A-Za-z0-9_-]+)*)/Dwemer-Dashboard/#', $script, $match)) $prefix = $match[1];
    $serverUrl = $prefix . '/' . $product['dir'];
    $backup = $prefix . '/Dwemer-Dashboard/database_manager.php';
    $scope = 'Legacy backup tools can include CHIM and STOBE. Check the archive scope before restoring; these tools are not filtered by the mod selected here.';
    if ($mod === 'stobe') {
        $backup = is_file($root . '/ui/database_manager.php') ? $serverUrl . '/ui/database_manager.php' : null;
        $scope = 'STOBE database backups. Server files and game saves are separate.';
    } elseif ($mod === 'dialectic') {
        // The Dashboard legacy manager has mixed-mod actions even with server=dialectic.
        $backup = null;
        $scope = 'A safely scoped DIALECTIC backup workflow is not available on this page yet. The legacy Dashboard tools contain cross-mod actions.';
    }
    return [
        'snapshots' => is_file($root . '/ui/playthrough_manager.php') ? $serverUrl . '/ui/playthrough_manager.php' : null,
        'cleanup' => $mod === 'chim' && is_file($root . '/ui/api/playthrough_retention.php') ? $serverUrl . '/ui/playthrough_manager.php#retention-section' : null,
        'backup' => $backup, 'advanced' => $backup, 'backup_scope' => $scope,
    ];
}
