<?php
session_start();

$resolveServerRoot = static function (array $candidates): string {
    foreach ($candidates as $candidate) {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        if (is_dir($normalized) && file_exists($normalized . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php')) {
            return realpath($normalized) ?: $normalized;
        }
    }
    return '';
};

$herikaRoot = $resolveServerRoot([
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'HerikaServer',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'HerikaServer',
    '/var/www/html/HerikaServer',
]);

if ($herikaRoot === '') {
    http_response_code(500);
    echo 'HerikaServer path not found.';
    exit;
}

// Build URL prefixes for dashboard/herika links.
$scriptPath = str_replace('\\', '/', strval($_SERVER['SCRIPT_NAME'] ?? '/Dwemer-Dashboard/database_manager.php'));
if (preg_match('#^([A-Za-z]:[\\\\/]|/mnt/)#', $scriptPath) === 1) {
    $scriptPath = '/Dwemer-Dashboard/database_manager.php';
}
$urlPrefix = preg_replace('#/Dwemer-Dashboard(?:/.*)?$#', '', $scriptPath);
if (!is_string($urlPrefix) || $urlPrefix === '/' || $urlPrefix === null) {
    $urlPrefix = '';
}
$urlPrefix = rtrim($urlPrefix, '/');
$dashboardWebRoot = ($urlPrefix !== '' ? $urlPrefix : '') . '/Dwemer-Dashboard';
$dashboardSuccessUrl = $dashboardWebRoot . '/';
$webRoot = ($urlPrefix !== '' ? $urlPrefix : '') . '/HerikaServer';
$dashboardDataPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR;
$manualBackupDir = $dashboardDataPath . 'manualbackup' . DIRECTORY_SEPARATOR;

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'herika_profile_bootstrap.php');
dashboardBootstrapHerikaProfile($herikaRoot);

$enginePath = $herikaRoot . DIRECTORY_SEPARATOR;

require_once($enginePath . "conf" . DIRECTORY_SEPARATOR . "conf.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "{$GLOBALS["DBDRIVER"]}.class.php");
require_once($enginePath . "lib" . DIRECTORY_SEPARATOR . "logger.php");

$embedParam = strval($_GET['embed'] ?? $_POST['embed'] ?? '');
$isEmbed = ($embedParam === '1');
$debugPaneLink = false;

if (isset($_SESSION["PROFILE"])) {
    require_once($_SESSION["PROFILE"]);
}

$pattern = '/conf_([a-f0-9]+)\.php/';
preg_match($pattern, basename($_SESSION["PROFILE"]), $matches);
$hash = isset($matches[1]) ? $matches[1] : 'default';    

$db=new sql();
$GLOBALS["db"] = $db;
$res=$db->fetchAll("select max(gamets) as last_gamets from eventlog");
$last_gamets=$res[0]["last_gamets"]+1;

// Enable error reporting (for development purposes)
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Paths
$rootPath = $enginePath;
$configFilepath = $rootPath . "conf" . DIRECTORY_SEPARATOR;

// Database connection details
$host = 'localhost';
$port = '5432';
$dbname = 'dwemer';
$schema = 'public';
$username = 'dwemer';
$password = 'dwemer';

// Initialize message variable
$message = '';

// PHP function to format file sizes
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 Bytes';
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

function getStobePgConnection(string $host, string $port, string $username, string $password)
{
    if (!function_exists('pg_connect')) {
        return false;
    }
    $connectionString = "host={$host} port={$port} dbname=stobe user={$username} password={$password} connect_timeout=2";
    return @pg_connect($connectionString);
}

function getPgVersioningColumns($connection): array
{
    $columns = ['table' => '', 'version' => ''];
    $result = @pg_query($connection, "SELECT column_name FROM information_schema.columns WHERE table_schema='public' AND table_name='database_versioning'");
    if (!$result) {
        return $columns;
    }

    $available = [];
    while ($row = @pg_fetch_assoc($result)) {
        $columnName = strtolower(trim(strval($row['column_name'] ?? '')));
        if ($columnName !== '') {
            $available[] = $columnName;
        }
    }
    @pg_free_result($result);

    if (in_array('tablename', $available, true)) {
        $columns['table'] = 'tablename';
    } elseif (in_array('table_name', $available, true)) {
        $columns['table'] = 'table_name';
    }

    if (in_array('version', $available, true)) {
        $columns['version'] = 'version';
    } elseif (in_array('patch_version', $available, true)) {
        $columns['version'] = 'patch_version';
    }

    return $columns;
}

function formatVersionDate($version) {
    // Version format: YYYYMMDDNNN (e.g., 20251207001)
    $str = (string)$version;
    if (strlen($str) >= 8) {
        $year = substr($str, 0, 4);
        $month = substr($str, 4, 2);
        $day = substr($str, 6, 2);
        $revision = strlen($str) > 8 ? substr($str, 8) : '001';
        return "{$year}-{$month}-{$day} (rev {$revision})";
    }
    return $version;
}

function getDashboardBackupMarker(): string
{
    return '-- DWEMER_DASHBOARD_MULTI_DB_BACKUP_V1';
}

function getDashboardBackupDatabaseConfigs(bool $excludeDwemerSettings = false): array
{
    return [
        [
            'name' => 'dwemer',
            'exclude_tables' => $excludeDwemerSettings ? ['chim_meta.settings'] : [],
        ],
        [
            'name' => 'stobe',
            'exclude_tables' => [],
        ],
    ];
}

function getBackupScopeSlugFromFlags(bool $includesDwemer, bool $includesStobe): string
{
    if ($includesDwemer && $includesStobe) {
        return 'herikaserver_stobeserver';
    }
    if ($includesStobe) {
        return 'stobeserver';
    }
    return 'herikaserver';
}

function backupFileContainsDatabaseSection(string $backupPath, string $databaseName): bool
{
    $needleDatabase = '-- database: ' . strtolower($databaseName);
    $needleConnect = '\\connect ' . strtolower($databaseName);
    $handle = @fopen($backupPath, 'rb');
    if ($handle === false) {
        return false;
    }

    $carry = '';
    while (!feof($handle)) {
        $chunk = fread($handle, 65536);
        if ($chunk === false || $chunk === '') {
            continue;
        }
        $haystack = strtolower($carry . $chunk);
        if (strpos($haystack, $needleDatabase) !== false || strpos($haystack, $needleConnect) !== false) {
            fclose($handle);
            return true;
        }
        $carry = substr($haystack, -128);
    }

    fclose($handle);
    return false;
}

function inspectBackupScope(string $backupPath, ?string $filename = null): array
{
    $resolvedName = trim(strval($filename ?? basename($backupPath)));
    $lowerName = strtolower($resolvedName);
    $includesDwemer = false;
    $includesStobe = false;
    $explicit = false;

    $handle = @fopen($backupPath, 'rb');
    if ($handle !== false) {
        $lineCount = 0;
        while (($line = fgets($handle)) !== false && $lineCount < 400) {
            $lineCount++;
            $trimmed = trim($line);
            if ($trimmed === getDashboardBackupMarker()) {
                $explicit = true;
            }
            if (preg_match('/^-- DATABASE:\s*dwemer\b/i', $trimmed) === 1 || preg_match('/^\\\\connect\s+dwemer\b/i', $trimmed) === 1) {
                $includesDwemer = true;
                $explicit = true;
            }
            if (preg_match('/^-- DATABASE:\s*stobe\b/i', $trimmed) === 1 || preg_match('/^\\\\connect\s+stobe\b/i', $trimmed) === 1) {
                $includesStobe = true;
                $explicit = true;
            }
            if ($includesDwemer && $includesStobe) {
                break;
            }
        }
        fclose($handle);
    }

    if (!$includesDwemer && backupFileContainsDatabaseSection($backupPath, 'dwemer')) {
        $includesDwemer = true;
        $explicit = true;
    }
    if (!$includesStobe && backupFileContainsDatabaseSection($backupPath, 'stobe')) {
        $includesStobe = true;
        $explicit = true;
    }

    if (
        !$includesStobe &&
        (
            strpos($lowerName, 'stobe') !== false ||
            strpos($lowerName, 'stobeserver') !== false
        )
    ) {
        $includesStobe = true;
    }
    if (
        !$includesDwemer &&
        (
            strpos($lowerName, 'dwemer') !== false ||
            strpos($lowerName, 'herika') !== false ||
            strpos($lowerName, 'herikaserver') !== false ||
            strpos($lowerName, 'chim') !== false
        )
    ) {
        $includesDwemer = true;
    }

    if (!$includesDwemer && !$includesStobe) {
        $includesDwemer = true;
    }

    $scopeSlug = getBackupScopeSlugFromFlags($includesDwemer, $includesStobe);
    if ($includesDwemer && $includesStobe) {
        $scopeLabel = 'HerikaServer + StobeServer';
        $scopeShortLabel = 'HerikaServer + StobeServer';
        $badgeClass = 'backup-scope-both';
    } elseif ($includesStobe) {
        $scopeLabel = 'StobeServer only';
        $scopeShortLabel = 'StobeServer';
        $badgeClass = 'backup-scope-stobe';
    } else {
        $scopeLabel = $explicit ? 'HerikaServer only' : 'HerikaServer only (legacy)';
        $scopeShortLabel = 'HerikaServer';
        $badgeClass = 'backup-scope-herika';
    }

    return [
        'includes_dwemer' => $includesDwemer,
        'includes_stobe' => $includesStobe,
        'scope_slug' => $scopeSlug,
        'scope_label' => $scopeLabel,
        'scope_short_label' => $scopeShortLabel,
        'badge_class' => $badgeClass,
        'explicit' => $explicit,
    ];
}

function getBackupScopeSlugFromConfigs(array $databaseConfigs): string
{
    $includesDwemer = false;
    $includesStobe = false;
    foreach ($databaseConfigs as $config) {
        $dbName = strtolower(trim(strval($config['name'] ?? '')));
        if ($dbName === 'dwemer') {
            $includesDwemer = true;
        }
        if ($dbName === 'stobe') {
            $includesStobe = true;
        }
    }
    return getBackupScopeSlugFromFlags($includesDwemer, $includesStobe);
}

function getBackupRestoreSuccessMessage(array $scope): string
{
    $includesDwemer = !empty($scope['includes_dwemer']);
    $includesStobe = !empty($scope['includes_stobe']);

    if ($includesDwemer && $includesStobe) {
        return 'HerikaServer and STOBE databases restored successfully.';
    }
    if ($includesStobe) {
        return 'STOBE database restored successfully.';
    }
    return 'HerikaServer database restored successfully.';
}

function appendFileToExistingFile(string $sourcePath, string $destPath): bool
{
    $readHandle = @fopen($sourcePath, 'rb');
    if ($readHandle === false) {
        return false;
    }

    $writeHandle = @fopen($destPath, 'ab');
    if ($writeHandle === false) {
        fclose($readHandle);
        return false;
    }

    $copied = stream_copy_to_stream($readHandle, $writeHandle);
    fclose($readHandle);
    fclose($writeHandle);

    return $copied !== false;
}

function createCombinedDatabaseBackupFile(
    string $backupFile,
    string $host,
    string $port,
    string $username,
    array $databaseConfigs,
    string &$errorMessage = ''
): bool {
    $header = getDashboardBackupMarker() . PHP_EOL . "\\set ON_ERROR_STOP on" . PHP_EOL;
    if (@file_put_contents($backupFile, $header) === false) {
        $errorMessage = 'Failed to initialize combined backup file.';
        return false;
    }

    foreach ($databaseConfigs as $config) {
        $dbName = trim(strval($config['name'] ?? ''));
        if ($dbName === '') {
            continue;
        }

        $sectionHeader = PHP_EOL . "-- DATABASE: {$dbName}" . PHP_EOL . "\\connect {$dbName}" . PHP_EOL;
        if (@file_put_contents($backupFile, $sectionHeader, FILE_APPEND) === false) {
            @unlink($backupFile);
            $errorMessage = "Failed to write {$dbName} section header.";
            return false;
        }

        $tmpFile = $backupFile . '.' . $dbName . '.tmp';
        $excludeArgs = '';
        $excludeTables = is_array($config['exclude_tables'] ?? null) ? $config['exclude_tables'] : [];
        foreach ($excludeTables as $tableName) {
            $tableName = trim(strval($tableName));
            if ($tableName !== '') {
                $excludeArgs .= ' -T ' . escapeshellarg($tableName);
            }
        }

        $command = "HOME=/tmp pg_dump -h " . escapeshellarg($host)
            . " -p " . escapeshellarg($port)
            . " -U " . escapeshellarg($username)
            . " -d " . escapeshellarg($dbName)
            . $excludeArgs
            . " > " . escapeshellarg($tmpFile) . " 2>&1";
        $result = shell_exec($command);

        if (!file_exists($tmpFile) || filesize($tmpFile) <= 0) {
            @unlink($tmpFile);
            @unlink($backupFile);
            $errorMessage = "Backup creation failed for {$dbName}.";
            if (is_string($result) && trim($result) !== '') {
                $errorMessage .= ' ' . trim(substr($result, 0, 500));
            }
            return false;
        }

        $firstChunk = strval(@file_get_contents($tmpFile, false, null, 0, 256));
        if (strpos($firstChunk, 'pg_dump: error:') !== false || strpos($firstChunk, 'FATAL:') !== false) {
            @unlink($tmpFile);
            @unlink($backupFile);
            $errorMessage = "Backup creation failed for {$dbName}: " . trim(substr($firstChunk, 0, 500));
            return false;
        }

        if (!appendFileToExistingFile($tmpFile, $backupFile)) {
            @unlink($tmpFile);
            @unlink($backupFile);
            $errorMessage = "Failed to append {$dbName} dump into combined backup.";
            return false;
        }

        @unlink($tmpFile);
    }

    return true;
}

function quotePgIdentifierForRestore(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function resetDatabaseForRestore(
    string $dbName,
    string $host,
    string $port,
    string $username,
    string $password,
    string &$errorMessage = ''
): bool {
    $conn = @pg_connect("host={$host} port={$port} dbname={$dbName} user={$username} password={$password}");
    if (!$conn) {
        $errorMessage = "Failed to connect to {$dbName} database.";
        return false;
    }

    $schemaResult = @pg_query(
        $conn,
        "SELECT schema_name
         FROM information_schema.schemata
         WHERE schema_name <> 'information_schema'
           AND schema_name NOT LIKE 'pg_%'
         ORDER BY CASE WHEN schema_name = 'public' THEN 1 ELSE 0 END, schema_name"
    );

    if (!$schemaResult) {
        $errorMessage = "Failed to enumerate schemas for {$dbName}: " . trim(strval(@pg_last_error($conn)));
        @pg_close($conn);
        return false;
    }

    $schemas = [];
    while ($row = @pg_fetch_assoc($schemaResult)) {
        $schemaName = trim(strval($row['schema_name'] ?? ''));
        if ($schemaName !== '') {
            $schemas[] = $schemaName;
        }
    }
    @pg_free_result($schemaResult);

    foreach ($schemas as $schemaName) {
        $dropSql = 'DROP SCHEMA IF EXISTS ' . quotePgIdentifierForRestore($schemaName) . ' CASCADE';
        if (!@pg_query($conn, $dropSql)) {
            $errorMessage = "Failed to drop schema {$schemaName} in {$dbName}: " . trim(strval(@pg_last_error($conn)));
            @pg_close($conn);
            return false;
        }
    }

    if (!@pg_query($conn, 'CREATE SCHEMA public')) {
        $errorMessage = "Failed to recreate public schema in {$dbName}: " . trim(strval(@pg_last_error($conn)));
        @pg_close($conn);
        return false;
    }

    @pg_close($conn);
    return true;
}

function restoreDatabaseBackupFile(
    string $backupPath,
    string $host,
    string $port,
    string $username,
    string $password,
    string &$errorMessage = ''
): bool {
    $scope = inspectBackupScope($backupPath);
    $restoreTargets = [];
    if (!empty($scope['includes_dwemer'])) {
        $restoreTargets[] = 'dwemer';
    }
    if (!empty($scope['includes_stobe'])) {
        $restoreTargets[] = 'stobe';
    }
    if (empty($restoreTargets)) {
        $restoreTargets[] = 'dwemer';
    }

    foreach ($restoreTargets as $targetDb) {
        if (!resetDatabaseForRestore($targetDb, $host, $port, $username, $password, $errorMessage)) {
            return false;
        }
    }

    $primaryDb = !empty($scope['includes_dwemer']) ? 'dwemer' : 'stobe';

    $psqlCommand = "PGPASSWORD=" . escapeshellarg($password)
        . " psql -h " . escapeshellarg($host)
        . " -p " . escapeshellarg($port)
        . " -U " . escapeshellarg($username)
        . " -d " . escapeshellarg($primaryDb)
        . " -v ON_ERROR_STOP=1 -f " . escapeshellarg($backupPath);

    $output = [];
    $returnVar = 0;
    exec($psqlCommand, $output, $returnVar);

    if ($returnVar !== 0) {
        $errorMessage = implode("\n", $output);
        return false;
    }

    return true;
}

function runDatabaseMaintenanceCommand(
    string $dbName,
    string $host,
    string $port,
    string $username,
    string $password,
    string &$outputText = ''
): bool {
    $command = "PGPASSWORD=" . escapeshellarg($password)
        . " psql -h " . escapeshellarg($host)
        . " -p " . escapeshellarg($port)
        . " -U " . escapeshellarg($username)
        . " -d " . escapeshellarg($dbName)
        . " -v ON_ERROR_STOP=1 -c "
        . escapeshellarg("VACUUM FULL ANALYZE;");

    $output = [];
    $returnVar = 0;
    exec($command . " 2>&1", $output, $returnVar);
    $outputText = trim(implode(PHP_EOL, $output));
    return $returnVar === 0;
}

function renderDatabaseMaintenanceResultsAndExit(array $results): void
{
    $allSuccessful = true;
    foreach ($results as $result) {
        if (empty($result['ok'])) {
            $allSuccessful = false;
            break;
        }
    }

    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Database Maintenance</title>";
    echo "<style>
        body{font-family:Arial,sans-serif;background:#1f1f1f;color:#f5f5f5;padding:24px;}
        .wrap{max-width:900px;margin:0 auto;}
        .card{background:#2a2a2a;border:1px solid #3a3a3a;border-radius:10px;padding:18px;margin-bottom:16px;}
        .ok{color:#4ade80;}
        .err{color:#f87171;}
        pre{background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto;white-space:pre-wrap;}
        .actions{margin-top:20px;display:flex;gap:12px;flex-wrap:wrap;}
        button{background:#2563eb;color:white;border:none;border-radius:8px;padding:10px 14px;cursor:pointer;}
    </style></head><body><div class='wrap'>";
    echo "<h2>Database Maintenance Complete</h2>";
    echo "<p>" . ($allSuccessful ? "VACUUM FULL ANALYZE finished for all selected databases." : "One or more maintenance operations failed.") . "</p>";

    foreach ($results as $result) {
        $dbLabel = htmlspecialchars(strval($result['label'] ?? $result['db'] ?? 'Database'));
        $statusClass = !empty($result['ok']) ? 'ok' : 'err';
        $statusText = !empty($result['ok']) ? 'Success' : 'Failed';
        echo "<div class='card'>";
        echo "<h3>{$dbLabel}</h3>";
        echo "<p class='{$statusClass}'><strong>{$statusText}</strong></p>";
        $outputText = trim(strval($result['output'] ?? ''));
        if ($outputText !== '') {
            echo "<pre>" . htmlspecialchars($outputText) . "</pre>";
        }
        echo "</div>";
    }

    echo "<div class='actions'><button onclick='window.close()'>Close</button></div>";
    echo "</div></body></html>";
    exit;
}

function resetDatabaseSchemaToPublic(
    string $dbName,
    string $host,
    string $port,
    string $username,
    string $password,
    string &$outputText = ''
): bool {
    $command = "PGPASSWORD=" . escapeshellarg($password)
        . " psql -h " . escapeshellarg($host)
        . " -p " . escapeshellarg($port)
        . " -U " . escapeshellarg($username)
        . " -d " . escapeshellarg($dbName)
        . " -v ON_ERROR_STOP=1 -c "
        . escapeshellarg("DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;");

    $output = [];
    $returnVar = 0;
    exec($command . " 2>&1", $output, $returnVar);
    $outputText = trim(implode(PHP_EOL, $output));
    return $returnVar === 0;
}

function runPhpScriptAndCapture(string $scriptPath, string &$outputText = ''): bool
{
    $phpCandidates = [];
    $phpBinary = trim(strval(PHP_BINARY ?? ''));
    if ($phpBinary !== '') {
        $phpCandidates[] = $phpBinary;
    }
    $phpCandidates[] = '/usr/bin/php';
    $phpCandidates[] = '/usr/local/bin/php';
    $phpCandidates[] = 'php';
    $phpCandidates = array_values(array_unique(array_filter($phpCandidates, static function ($candidate) {
        return trim(strval($candidate)) !== '';
    })));

    foreach ($phpCandidates as $candidate) {
        $output = [];
        $returnVar = 0;
        $command = escapeshellarg($candidate) . ' ' . escapeshellarg($scriptPath) . ' 2>&1';
        exec($command, $output, $returnVar);
        $outputText = trim(implode(PHP_EOL, $output));
        if ($returnVar === 0) {
            return true;
        }
    }

    return false;
}

function renderFactoryResetResultsAndExit(array $results): void
{
    $allSuccessful = true;
    foreach ($results as $result) {
        if (empty($result['ok'])) {
            $allSuccessful = false;
            break;
        }
    }

    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Factory Reset</title>";
    echo "<style>
        body{font-family:Arial,sans-serif;background:#1f1f1f;color:#f5f5f5;padding:24px;}
        .wrap{max-width:980px;margin:0 auto;}
        .card{background:#2a2a2a;border:1px solid #3a3a3a;border-radius:10px;padding:18px;margin-bottom:16px;}
        .ok{color:#4ade80;}
        .err{color:#f87171;}
        pre{background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto;white-space:pre-wrap;}
        button{background:#2563eb;color:white;border:none;border-radius:8px;padding:10px 14px;cursor:pointer;}
    </style></head><body><div class='wrap'>";
    echo "<h2>Factory Reset Complete</h2>";
    echo "<p>" . ($allSuccessful ? "HerikaServer and StobeServer databases were reset and rebuilt." : "One or more reset steps failed.") . "</p>";

    foreach ($results as $result) {
        $label = htmlspecialchars(strval($result['label'] ?? 'Step'));
        $statusClass = !empty($result['ok']) ? 'ok' : 'err';
        $statusText = !empty($result['ok']) ? 'Success' : 'Failed';
        echo "<div class='card'>";
        echo "<h3>{$label}</h3>";
        echo "<p class='{$statusClass}'><strong>{$statusText}</strong></p>";
        $outputText = trim(strval($result['output'] ?? ''));
        if ($outputText !== '') {
            echo "<pre>" . htmlspecialchars($outputText) . "</pre>";
        }
        echo "</div>";
    }

    echo "<div><button onclick='window.close()'>Close</button></div>";
    echo "</div></body></html>";
    exit;
}



// Include dashboard-owned automatic backup management
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'automatic_backup.php');

if (function_exists('deferredDashboardAutomaticBackupInit')) {
    deferredDashboardAutomaticBackupInit();
}

if (isset($_GET['action']) && $_GET['action'] === 'maintenance') {
    $maintenanceResults = [];
    foreach (
        [
            ['db' => 'dwemer', 'label' => 'HerikaServer'],
            ['db' => 'stobe', 'label' => 'StobeServer'],
        ] as $target
    ) {
        $maintenanceOutput = '';
        $ok = runDatabaseMaintenanceCommand(
            strval($target['db']),
            $host,
            $port,
            $username,
            $password,
            $maintenanceOutput
        );
        $maintenanceResults[] = [
            'db' => $target['db'],
            'label' => $target['label'],
            'ok' => $ok,
            'output' => $maintenanceOutput,
        ];
    }
    renderDatabaseMaintenanceResultsAndExit($maintenanceResults);
}

if (isset($_GET['action']) && $_GET['action'] === 'factory_reset') {
    $factoryResults = [];
    $factoryResetTarget = strtolower(trim(strval($_GET['target'] ?? 'all')));
    $targets = [];
    if ($factoryResetTarget === 'herika') {
        $targets[] = [
            'db' => 'dwemer',
            'label' => 'HerikaServer',
            'runner' => $herikaRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'apply_db_updates.php',
        ];
    } elseif ($factoryResetTarget === 'stobe') {
        $targets[] = [
            'db' => 'stobe',
            'label' => 'StobeServer',
            'runner' => dirname($herikaRoot) . DIRECTORY_SEPARATOR . 'StobeServer' . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'run_db_updates.php',
        ];
    } else {
        $targets[] = [
            'db' => 'dwemer',
            'label' => 'HerikaServer',
            'runner' => $herikaRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'apply_db_updates.php',
        ];
        $targets[] = [
            'db' => 'stobe',
            'label' => 'StobeServer',
            'runner' => dirname($herikaRoot) . DIRECTORY_SEPARATOR . 'StobeServer' . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'run_db_updates.php',
        ];
    }

    foreach ($targets as $target) {
        $resetOutput = '';
        $resetOk = resetDatabaseSchemaToPublic(
            strval($target['db']),
            $host,
            $port,
            $username,
            $password,
            $resetOutput
        );
        if ($resetOk) {
            $resetOk = runPhpScriptAndCapture(strval($target['runner']), $resetOutput);
        }
        $factoryResults[] = [
            'label' => $target['label'],
            'ok' => $resetOk,
            'output' => $resetOutput,
        ];
    }

    renderFactoryResetResultsAndExit($factoryResults);
}

// Handle Automatic Backup settings (enable/disable and retention) BEFORE rendering (PRG pattern)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_auto_backup_settings') {
    try {
        $enabled = isset($_POST['auto_enabled']) ? 'true' : 'false';
        $maxKeep = max(1, min(10, intval($_POST['auto_max'] ?? 5)));
        dashboardWriteSettingValue($db, 'AUTOMATIC_DATABASE_BACKUPS', $enabled);
        dashboardWriteSettingValue($db, 'AUTOMATIC_BACKUP_MAX_COUNT', (string)$maxKeep);
    } catch (Throwable $e) {
        // swallow and continue to redirect, errors will be visible in logs
    }
    // Redirect back to the same page (preserve query string like ?embed=1)
    // Preserve ?embed=1 so navbar stays hidden in config hub
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'database_manager.php') . ($qs ? ('?' . $qs) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Load live auto-backup settings once for consistent rendering
$autoEnabled = false;
$currentMax = 5;
try {
    $dbMeta = new sql();
    $rowEn = dashboardReadSettingValue($dbMeta, 'AUTOMATIC_DATABASE_BACKUPS');
    if ($rowEn !== null) {
        $val = strtolower(trim($rowEn));
        $autoEnabled = in_array($val, ['true','1','yes','on'], true);
    }
    $rowMax = dashboardReadSettingValue($dbMeta, 'AUTOMATIC_BACKUP_MAX_COUNT');
    if ($rowMax !== null) {
        $v = intval(trim($rowMax));
        if ($v >= 1 && $v <= 10) { $currentMax = $v; }
    }
} catch (Throwable $e) {}

// Handle tile actions with PRG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_auto_enabled') {
    try {
        $dbMeta2 = new sql();
        // Read current value and invert
        $cur = 'false';
        $rowEn2 = dashboardReadSettingValue($dbMeta2, 'AUTOMATIC_DATABASE_BACKUPS');
        if ($rowEn2 !== null) {
            $val2 = strtolower(trim($rowEn2));
            $cur = (in_array($val2, ['true','1','yes','on'], true)) ? 'true' : 'false';
        }
        $new = ($cur === 'true') ? 'false' : 'true';
        dashboardWriteSettingValue($dbMeta2, 'AUTOMATIC_DATABASE_BACKUPS', $new);
    } catch (Throwable $e) {}
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'database_manager.php') . ($qs ? ('?' . $qs) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_auto_max') {
    try {
        $maxKeep = max(1, min(10, intval($_POST['auto_max'] ?? 5)));
        $dbMeta3 = new sql();
        dashboardWriteSettingValue($dbMeta3, 'AUTOMATIC_BACKUP_MAX_COUNT', (string)$maxKeep);
    } catch (Throwable $e) {}
    $redirectUrl = $_SERVER['REQUEST_URI'] ?? ($_SERVER['PHP_SELF'] ?? 'database_manager.php');
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle reset database versioning entry (single entry)
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'reset_db_version'
) {
    $versionTarget = strtolower(trim(strval($_POST['version_target'] ?? 'herika')));
    try {
        $tablename = trim(strval($_POST['tablename'] ?? ''));
        if ($tablename !== '') {
            if ($versionTarget === 'stobe') {
                $stobeConn = getStobePgConnection($host, $port, $username, $password);
                if (!$stobeConn) {
                    throw new RuntimeException('Failed to connect to Stobe database.');
                }
                $stobeColumns = getPgVersioningColumns($stobeConn);
                if ($stobeColumns['table'] === '') {
                    @pg_close($stobeConn);
                    throw new RuntimeException('Stobe database_versioning table was not found or is incompatible.');
                }
                $query = "DELETE FROM public.database_versioning WHERE {$stobeColumns['table']} = $1";
                $deleteResult = @pg_query_params($stobeConn, $query, [$tablename]);
                if (!$deleteResult) {
                    $errorText = trim(strval(@pg_last_error($stobeConn)));
                    @pg_close($stobeConn);
                    throw new RuntimeException($errorText !== '' ? $errorText : 'Unknown Stobe delete error.');
                }
                @pg_free_result($deleteResult);
                @pg_close($stobeConn);
            } else {
                $db->execQuery("DELETE FROM public.database_versioning WHERE tablename = " . $db->quote($tablename));
            }
            $message = "<p><strong>Database version reset successfully!</strong></p>";
            $message .= "<p>Table: <strong>" . htmlspecialchars($tablename) . "</strong></p>";
            $message .= "<p>Target: <strong>" . ($versionTarget === 'stobe' ? 'STOBE' : 'CHIM') . "</strong></p>";
            $message .= "<p>This update will be re-applied on the next server restart.</p>";
        } else {
            $message = "<p><strong>Error:</strong> Invalid table name.</p>";
        }
    } catch (Throwable $e) {
        $message = "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'database_manager.php') . ($qs ? ('?' . $qs) : '');
    header('Location: ' . $redirectUrl);
    exit;
}

// Handle reset all database versioning entries
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action']) &&
    $_POST['action'] === 'reset_all_db_versions'
) {
    $versionTarget = strtolower(trim(strval($_POST['version_target'] ?? 'herika')));
    try {
        if ($versionTarget === 'stobe') {
            $stobeConn = getStobePgConnection($host, $port, $username, $password);
            if (!$stobeConn) {
                throw new RuntimeException('Failed to connect to Stobe database.');
            }
            $stobeColumns = getPgVersioningColumns($stobeConn);
            if ($stobeColumns['table'] === '') {
                @pg_close($stobeConn);
                throw new RuntimeException('Stobe database_versioning table was not found or is incompatible.');
            }
            $countResult = @pg_query($stobeConn, "SELECT COUNT(*) AS count FROM public.database_versioning");
            $count = 0;
            if ($countResult) {
                $countRow = @pg_fetch_assoc($countResult);
                $count = intval($countRow['count'] ?? 0);
                @pg_free_result($countResult);
            }
            $deleteResult = @pg_query($stobeConn, "DELETE FROM public.database_versioning");
            if (!$deleteResult) {
                $errorText = trim(strval(@pg_last_error($stobeConn)));
                @pg_close($stobeConn);
                throw new RuntimeException($errorText !== '' ? $errorText : 'Unknown Stobe delete-all error.');
            }
            @pg_free_result($deleteResult);
            @pg_close($stobeConn);
        } else {
            $result = $db->fetchOne("SELECT COUNT(*) as count FROM public.database_versioning");
            $count = intval($result['count'] ?? 0);
            $db->execQuery("DELETE FROM public.database_versioning");
        }

        $message = "<p><strong>All database versions reset successfully!</strong></p>";
        $message .= "<p>Reset <strong>{$count}</strong> version entries.</p>";
        $message .= "<p>Target: <strong>" . ($versionTarget === 'stobe' ? 'STOBE' : 'CHIM') . "</strong></p>";
        $message .= "<p><strong>Important:</strong> All database updates will be re-applied on the next server restart. This may take several minutes.</p>";
    } catch (Throwable $e) {
        $message = "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
    }

    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $redirectUrl = ($_SERVER['PHP_SELF'] ?? 'database_manager.php') . ($qs ? ('?' . $qs) : '');
    header('Location: ' . $redirectUrl);
    exit;
}
// Handle download automatic backup
if (isset($_GET['action']) && $_GET['action'] === 'download_auto' && isset($_GET['filename'])) {
    $autoBackup = new DashboardAutomaticBackup();
    $filename = $_GET['filename'];
    
    // Security check
    if (strpos($filename, 'auto_backup_') === 0 && substr($filename, -4) === '.sql') {
        $backups = $autoBackup->getBackups();
        $validFile = false;
        
        foreach ($backups as $backup) {
            if ($backup['filename'] === $filename) {
                $validFile = true;
                $backupPath = $backup['filepath'];
                break;
            }
        }
        
        if ($validFile && file_exists($backupPath)) {
            // Force download of the backup file (streamed to avoid memory usage)
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . filesize($backupPath));
            header('Cache-Control: must-revalidate');
            header('Pragma: public');

            // Fully clear output buffers before streaming large files
            while (ob_get_level() > 0) { ob_end_clean(); }

            // Stream the file in chunks
            $fh = fopen($backupPath, 'rb');
            if ($fh !== false) {
                set_time_limit(0);
                while (!feof($fh)) {
                    echo fread($fh, 8192);
                    flush();
                }
                fclose($fh);
            }
            exit();
        } else {
            $message = "<p><strong>Error:</strong> Backup file not found.</p>";
        }
    } else {
        $message = "<p><strong>Error:</strong> Invalid filename format.</p>";
    }
}

// Handle delete automatic backup
if (isset($_GET['action']) && $_GET['action'] === 'delete_auto' && isset($_GET['filename'])) {
    $autoBackup = new DashboardAutomaticBackup();
    $filename = $_GET['filename'];
    
    // Security check
    if (strpos($filename, 'auto_backup_') === 0 && substr($filename, -4) === '.sql') {
        if ($autoBackup->deleteBackup($filename)) {
            $message = "<p><strong>✅ Automatic backup deleted successfully!</strong></p>";
            $message .= "<p>Deleted: <strong>$filename</strong></p>";
        } else {
            $message = "<p><strong>Error:</strong> Failed to delete backup file.</p>";
        }
    } else {
        $message = "<p><strong>Error:</strong> Invalid filename format.</p>";
    }
}

// Handle restore from automatic backup
if (isset($_GET['action']) && $_GET['action'] === 'restore_auto' && isset($_GET['filename'])) {
    $autoBackup = new DashboardAutomaticBackup();
    $filename = $_GET['filename'];
    
    // Security check
    if (strpos($filename, 'auto_backup_') === 0 && substr($filename, -4) === '.sql') {
        $backups = $autoBackup->getBackups();
        $validFile = false;
        
        foreach ($backups as $backup) {
            if ($backup['filename'] === $filename) {
                $validFile = true;
                $backupPath = $backup['filepath'];
                break;
            }
        }
        
        if ($validFile && file_exists($backupPath)) {
            $backupScope = inspectBackupScope($backupPath, $filename);
            $restoreError = '';
            if (!restoreDatabaseBackupFile($backupPath, $host, $port, $username, $password, $restoreError)) {
                $message .= "<p><strong>Error:</strong> Failed to restore from automatic backup.</p>";
                if ($restoreError !== '') {
                    $message .= '<pre>' . htmlspecialchars($restoreError) . '</pre>';
                }
            } else {
                $successMessage = getBackupRestoreSuccessMessage($backupScope);
                echo "<script type='text/javascript'>\n".
                     "  try {\n".
                     "    const msg = " . json_encode($successMessage) . ";\n".
                     "    if (window.top && window.top !== window) {\n".
                     "      window.top.postMessage({type:'toast', message: msg}, '*');\n".
                     "      setTimeout(function(){ window.top.location.href = '".$dashboardSuccessUrl."'; }, 1200);\n".
                     "    } else {\n".
                     "      alert(msg);\n".
                     "      setTimeout(function(){ window.location.href = '".$dashboardSuccessUrl."'; }, 1200);\n".
                     "    }\n".
                     "  } catch(e) { window.location.href = '".$dashboardSuccessUrl."'; }\n".
                     "</script>";
                exit;
            }
        } else {
            $message = "<p><strong>Error:</strong> Invalid backup file specified.</p>";
        }
    } else {
        $message = "<p><strong>Error:</strong> Invalid filename format.</p>";
    }
}

// Handle import from server-side file
if (isset($_POST['action']) && $_POST['action'] === 'import_from_server' && isset($_POST['server_file'])) {
    $serverFile = $_POST['server_file'];
    $uploadsDir = $manualBackupDir;
    $fullPath = realpath($uploadsDir . basename($serverFile));
    
    // Security: ensure file is within uploads directory and has .sql extension
    if ($fullPath && strpos($fullPath, realpath($uploadsDir)) === 0 && pathinfo($fullPath, PATHINFO_EXTENSION) === 'sql' && file_exists($fullPath)) {
        $backupScope = inspectBackupScope($fullPath, $serverFile);
        $restoreError = '';
        if (!restoreDatabaseBackupFile($fullPath, $host, $port, $username, $password, $restoreError)) {
            $message .= "<p><strong>Error:</strong> Failed to import SQL file.</p>";
            if ($restoreError !== '') {
                $message .= '<pre>' . htmlspecialchars($restoreError) . '</pre>';
            }
        } else {
            $successMessage = getBackupRestoreSuccessMessage($backupScope);
            echo "<script type='text/javascript'>\n".
                 "  try {\n".
                 "    const msg = " . json_encode($successMessage) . ";\n".
                 "    if (window.top && window.top !== window) {\n".
                 "      window.top.postMessage({type:'toast', message: msg}, '*');\n".
                 "      setTimeout(function(){ window.top.location.href = '".$dashboardSuccessUrl."'; }, 1200);\n".
                 "    } else {\n".
                 "      alert(msg);\n".
                 "      setTimeout(function(){ window.location.href = '".$dashboardSuccessUrl."'; }, 1200);\n".
                 "    }\n".
                 "  } catch(e) { window.location.href = '".$dashboardSuccessUrl."'; }\n".
                 "</script>";
            exit;
        }
    } else {
        $message = "<p><strong>Error:</strong> Invalid file selected or file does not exist.</p>";
    }
}

// Handle backup database request
if (isset($_GET['action']) && $_GET['action'] === 'backup') {
    try {
        // Create authentication setup (same as AutomaticBackup class)
        $pgpassResult = shell_exec('echo "localhost:5432:*:dwemer:dwemer" > /tmp/.pgpass; echo $?');
        $chmodResult = shell_exec('chmod 600 /tmp/.pgpass; echo $?');
        
        $generatedScopeSlug = getBackupScopeSlugFromConfigs(getDashboardBackupDatabaseConfigs(false));
        $filename = "manual_backup_" . $generatedScopeSlug . "_" . date("Y-m-d_H-i-s") . ".sql";
        if (!is_dir($dashboardDataPath)) {
            mkdir($dashboardDataPath, 0755, true);
        }
        $backupFile = $dashboardDataPath . 'export_' . $filename;

        $backupError = '';
        $backupCreated = createCombinedDatabaseBackupFile(
            $backupFile,
            $host,
            $port,
            $username,
            getDashboardBackupDatabaseConfigs(false),
            $backupError
        );

        if ($backupCreated && file_exists($backupFile) && filesize($backupFile) > 0) {
            clearstatcache(true, $backupFile);
            $fileSize = filesize($backupFile);
            $generatedScope = inspectBackupScope($backupFile, $filename);
            
            // Check if the file contains error messages instead of actual backup data
            $firstLine = file_get_contents($backupFile, false, null, 0, 100);
            if (strpos($firstLine, 'pg_dump: error:') !== false || strpos($firstLine, 'FATAL:') !== false) {
                $message = "<p><strong>Error:</strong> Database backup failed.</p>";
                $message .= "<pre>" . htmlspecialchars(substr($firstLine, 0, 500)) . "</pre>";
                if (file_exists($backupFile)) {
                    unlink($backupFile);
                }
            } elseif (empty($generatedScope['includes_dwemer']) || empty($generatedScope['includes_stobe'])) {
                $message = "<p><strong>Error:</strong> Manual backup validation failed.</p>";
                $message .= "<p>Expected a combined HerikaServer + StobeServer backup, but detected: <strong>" . htmlspecialchars(strval($generatedScope['scope_label'] ?? 'unknown')) . "</strong>.</p>";
                $message .= "<p>The generated SQL file was not downloaded.</p>";
            } else {
                // Successful backup - force download (streamed)
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . $fileSize);
                header('Cache-Control: must-revalidate');
                header('Pragma: public');

                // Fully clear output buffers before streaming large files
                while (ob_get_level() > 0) { ob_end_clean(); }

                // Stream the file in chunks to avoid memory exhaustion
                $fh = fopen($backupFile, 'rb');
                if ($fh !== false) {
                    set_time_limit(0);
                    while (!feof($fh)) {
                        echo fread($fh, 8192);
                        flush();
                    }
                    fclose($fh);
                }

                // Clean up - delete the temporary file
                unlink($backupFile);

                exit();
            }
        } else {
            $message = "<p><strong>Error:</strong> Backup creation failed or file is empty.</p>";
            if ($backupError !== '') {
                $message .= "<pre>" . htmlspecialchars(substr($backupError, 0, 1000)) . "</pre>";
            }
        }
        
    } catch (Exception $e) {
        $message = "<p><strong>Error:</strong> Exception during backup creation: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if a file was uploaded without errors
    if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        // Validate the uploaded file
        $fileTmpPath = $_FILES['sql_file']['tmp_name'];
        $fileName = $_FILES['sql_file']['name'];
        $fileSize = $_FILES['sql_file']['size'];
        $fileType = $_FILES['sql_file']['type'];

        // Allowed file extensions
        $allowedfileExtensions = array('sql');

        // Get file extension
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (in_array($fileExtension, $allowedfileExtensions)) {
            // Directory where the uploaded file will be moved
            $uploadFileDir = $rootPath . 'data' . DIRECTORY_SEPARATOR;
            $destPath = $uploadFileDir . 'dwemer.sql';

            // Ensure the upload directory exists
            if (!file_exists($uploadFileDir)) {
                Logger::info("Creating $uploadFileDir");
                mkdir($uploadFileDir, 0755, true);
            }

            // Move the file to the destination directory with the new name
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $backupScope = inspectBackupScope($destPath, $fileName);
                $restoreError = '';
                if (!restoreDatabaseBackupFile($destPath, $host, $port, $username, $password, $restoreError)) {
                    $message .= "<p>Failed to import SQL file.</p>";
                    if ($restoreError !== '') {
                        $message .= '<pre>' . htmlspecialchars($restoreError) . '</pre>';
                    }
                } else {
                    $message .= "<p>" . htmlspecialchars(getBackupRestoreSuccessMessage($backupScope)) . "</p>";
                    $message .= "<p>Import completed.</p>";
                    $message .= "<script type='text/javascript'>\n".
                                "  const msg = " . json_encode(getBackupRestoreSuccessMessage($backupScope)) . ";\n".
                                "  if (window.top && window.top !== window) {\n".
                                "      window.top.postMessage({type:'toast', message: msg}, '*');\n".
                                "      setTimeout(function(){ window.top.location.href = '".$dashboardSuccessUrl."'; }, 1200);\n".
                                "  } else {\n".
                                "      alert(msg);\n".
                                "      setTimeout(function(){ window.location.href = '".$dashboardSuccessUrl."'; }, 1200);\n".
                                "  }\n".
                                "</script>";
                }
            } else {
                $message .= '<p>There was an error moving the uploaded file.</p>';
            }
        } else {
            $message .= '<p>Upload failed. Allowed file types: ' . implode(',', $allowedfileExtensions) . '</p>';
        }
    } else {
        $message .= '<p>No file uploaded or there was an upload error.</p>';
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($webRoot, ENT_QUOTES, 'UTF-8'); ?>/ui/css/main.css">
    <title>Database Manager</title>
    <style>
        /* Database Manager - Modern styling */
        body {
            font-family: 'Futura CondensedLight', Arial, sans-serif;
            background-color: #2c2c2c;
            color: #f8f9fa;
            font-size: 18px;
            min-height: 100vh;
        }

        h1, h2, h3, h4, h5, h6 {
            color: #ffffff !important;
            font-family: 'Futura CondensedLight', Arial, sans-serif;
            margin-bottom: 15px;
        }

        h1 {
            font-size: 32px;
            font-family: 'Futura CondensedLight', Arial, sans-serif;
            font-weight: normal;
            letter-spacing: 0.5px;
            word-spacing: 8px;
        }

        label {
            font-weight: bold;
            color: #f8f9fa;
        }

        .message {
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .message:hover {
            border-color: rgba(242, 124, 17, 0.3);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px rgba(255, 255, 255, 0.05);
        }

        .message p {
            margin: 0 0 10px 0;
            line-height: 150%;
            font-size: 16px;
        }
        
        /* Page header styling */
        .page-header {
            background: linear-gradient(180deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            padding: 20px 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }

        .page-header-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        
        .page-header h1 {
            margin: 0 0 8px 0;
            font-size: 32px;
            color: #ffffff;
            font-family: 'Futura CondensedLight', Arial, sans-serif;
            font-weight: normal;
            letter-spacing: 0.5px;
            word-spacing: 8px;
        }
        
        .page-subtitle {
            color: #9fb1c9;
            font-size: 16px;
            margin: 0;
            font-family: 'Futura CondensedLight', Arial, sans-serif;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border-radius: 8px;
            border: 1px solid rgba(138, 155, 182, 0.36);
            background: rgba(30, 35, 45, 0.88);
            color: #fff;
            text-decoration: none;
            padding: 8px 14px;
            white-space: nowrap;
            transition: all 0.2s ease-in-out;
            font-size: 15px;
        }

        .back-link:hover {
            border-color: rgba(230, 183, 108, 0.52);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.28);
            color: #fff;
            text-decoration: none;
        }
        
        /* Grid container */
        .grid-container {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 20px;
            align-items: stretch;
        }

        .manager-sections {
            display: flex;
            flex-direction: column;
            gap: 32px;
            margin-bottom: 24px;
        }

        .manager-section {
            width: 100%;
        }

        .tools-grid {
            margin-bottom: 16px;
        }

        .backup-restore-grid {
            grid-template-columns: 1fr 1fr;
            margin-top: 10px;
            margin-bottom: 0;
            align-items: start;
        }

        .backup-restore-grid > .message {
            margin-bottom: 0;
            min-width: 0;
            height: 100%;
        }

        .section-divider {
            height: 1px;
            background: #343a46;
            opacity: 0.95;
            margin: 0 0 20px 0;
        }
        
        @media (max-width: 1400px) {
            .grid-container {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .grid-container {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 1100px) {
            .backup-restore-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Card styling */
        .card-tile {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            height: 100%;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            padding: 20px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15), inset 0 1px rgba(255, 255, 255, 0.03);
        }
        
        .card-tile:hover {
            border-color: rgba(242, 124, 17, 0.3);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25), inset 0 1px rgba(255, 255, 255, 0.05);
        }
        
        .card-content {
            flex-grow: 1;
        }
        
        .card-actions {
            margin-top: auto;
        }
        
        /* Stats grid styling */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        
        .stat-tile {
            background: rgba(26, 26, 26, 0.8);
            border: 1px solid #3a3a3a;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        
        .stat-tile:hover {
            border-color: rgba(242, 124, 17, 0.3);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .stat-tile h5 {
            margin: 0 0 5px 0;
            color: #f8f9fa;
            font-size: 14px;
        }
        
        .stat-value {
            margin: 0;
            font-size: 16px;
            font-weight: bold;
            color: #f8f9fa;
        }

        .response-container {
            margin-top: 20px;
        }

        .indent {
            padding-left: 10ch;
        }

        .indent5 {
            padding-left: 5ch;
            padding-right: 20px;
        }

        .button {
            padding: 10px 20px;
            color: #ffffff;
            background: linear-gradient(135deg, rgba(42, 42, 42, 0.95), rgba(34, 34, 34, 0.98));
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            cursor: pointer;
            font-size: 15px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            margin: 5px;
            font-weight: 500;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.05);
            box-sizing: border-box;
            max-width: 100%;
        }

        .card-actions .button {
            width: 100% !important;
            margin: 0;
        }

        .button:hover {
            transform: translateY(-1px);
            border-color: rgba(242, 124, 17, 0.5);
            color: rgb(242, 124, 17);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3), inset 0 1px rgba(255, 255, 255, 0.1);
            text-decoration: none;
        }

        .button:active {
            transform: translateY(1px);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2), inset 0 1px rgba(255, 255, 255, 0.05);
        }

        /* Form elements using modern styling */
        input[type="text"],
        input[type="file"],
        select {
            background: rgba(26, 26, 26, 0.8);
            color: #f8f9fa;
            padding: 10px 12px;
            border-radius: 6px;
            border: 1px solid #3a3a3a;
            cursor: pointer;
            width: auto;
            transition: border-color 0.3s ease, box-shadow 0.3s ease, background 0.3s ease;
        }
        
        input[type="text"]:focus,
        input[type="file"]:focus,
        select:focus {
            outline: none;
            border-color: rgb(242, 124, 17);
            box-shadow: 0 0 0 3px rgba(242, 124, 17, 0.1);
            background: rgba(26, 26, 26, 0.95);
        }

        input[type="file"]::-webkit-file-upload-button {
            background-color: #6c757d;
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
            transition: background-color 0.3s ease;
            font-size: 14px;
        }

        input[type="file"]::-webkit-file-upload-button:hover {
            background-color: #5a6268;
        }

        pre {
            background-color: #2c2c2c;
            padding: 10px;
            border: 1px solid #4a4a4a;
            border-radius: 8px;
            color: #f8f9fa;
            overflow: auto;
        }

        /* Progress bar styling */
        #progressBar {
            background: linear-gradient(90deg, #007bff 0%, #0056b3 100%);
            border: 1px solid #4a4a4a;
        }

        /* Backup list container */
        .backup-list {
            background: rgba(26, 26, 26, 0.8);
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            padding: 0;
            margin: 0;
        }

        .backup-item {
            padding: 12px;
            border-bottom: 1px solid #3a3a3a;
            transition: all 0.3s ease;
        }

        .backup-item:hover {
            background: rgba(42, 42, 42, 0.5);
        }
        
        .backup-info {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .backup-details {
            flex-grow: 1;
            min-width: 0;
        }
        
        .backup-filename {
            font-weight: bold;
            font-size: 13px;
            margin-bottom: 4px;
            word-break: break-all;
            color: #f8f9fa;
        }
        
        .backup-meta {
            font-size: 11px;
            color: #ccc;
            display: flex;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }

        .backup-badges {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-bottom: 6px;
        }

        .backup-scope-badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.02em;
            border: 1px solid transparent;
        }

        .backup-scope-herika {
            background: rgba(23, 101, 41, 0.18);
            color: #9df0b5;
            border-color: rgba(74, 222, 128, 0.35);
        }

        .backup-scope-stobe {
            background: rgba(1, 53, 166, 0.18);
            color: #9dc4ff;
            border-color: rgba(125, 163, 255, 0.35);
        }

        .backup-scope-both {
            background: rgba(156, 163, 175, 0.16);
            color: #f3f4f6;
            border-color: rgba(209, 213, 219, 0.35);
        }

        .server-file-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }

        .server-file-option {
            display: block;
            cursor: pointer;
        }

        .server-file-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .server-file-card {
            border: 1px solid #3a3a3a;
            border-radius: 10px;
            padding: 12px;
            background: rgba(26, 26, 26, 0.8);
            transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
        }

        .server-file-option:hover .server-file-card {
            border-color: #5a5a5a;
            background: rgba(36, 36, 36, 0.9);
            transform: translateY(-1px);
        }

        .server-file-option input[type="radio"]:checked + .server-file-card {
            border-color: #4ade80;
            box-shadow: 0 0 0 1px rgba(74, 222, 128, 0.35);
            background: rgba(23, 101, 41, 0.12);
        }

        .server-file-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 8px;
        }

        .server-file-radio-indicator {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            border: 2px solid #7a7a7a;
            flex-shrink: 0;
            margin-top: 2px;
            position: relative;
        }

        .server-file-option input[type="radio"]:checked + .server-file-card .server-file-radio-indicator {
            border-color: #4ade80;
        }

        .server-file-option input[type="radio"]:checked + .server-file-card .server-file-radio-indicator::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 8px;
            top: 2px;
            left: 2px;
            border-radius: 50%;
            background: #4ade80;
        }

        .server-file-notes {
            font-size: 11px;
            color: #a3a3a3;
            margin-top: 6px;
        }
        
        .backup-actions {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }
        
        .backup-btn {
            color: white;
            padding: 4px 8px;
            border: none;
            border-radius: 3px;
            font-size: 11px;
            cursor: pointer;
            flex: 1;
            min-width: 70px;
            margin: 0;
        }
        
        /* Instruction box styling */
        .instruction-box {
            background: rgba(23, 101, 41, 0.1);
            border: 1px solid #176529;
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
        }
        
        code {
            background-color: #000000;
            padding: 2px 6px;
            border-radius: 3px;
            color: #f8f9fa;
        }
        
        /* Empty state styling */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #888;
            font-style: italic;
            background: rgba(26, 26, 26, 0.8);
            border-radius: 8px;
            border: 1px dashed #3a3a3a;
            margin: 15px 0;
        }
        
        .empty-state-icon {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        /* Version table styling */
        .version-table-container {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #3a3a3a;
            border-radius: 8px;
            background: rgba(26, 26, 26, 0.8);
        }
        
        .version-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .version-table thead {
            position: sticky;
            top: 0;
            background: rgba(26, 26, 26, 0.95);
            border-bottom: 2px solid rgba(242, 124, 17, 0.3);
            z-index: 1;
        }
        
        .version-table th {
            padding: 12px;
            font-weight: bold;
            color: rgb(242, 124, 17);
            border-bottom: 1px solid #3a3a3a;
        }
        
        .version-table tbody tr {
            border-bottom: 1px solid #3a3a3a;
            transition: background-color 0.3s ease;
        }
        
        .version-table tbody tr:hover {
            background: rgba(242, 124, 17, 0.05);
        }
        
        .version-table td {
            padding: 10px;
            color: #f8f9fa;
        }

        .versioning-manager {
            margin-top: 0;
        }

        .versioning-panels {
            margin-top: 12px;
        }

        .versioning-panel {
            display: none;
        }

        .versioning-panel.active {
            display: block;
        }

        .version-panel-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .versioning-tabs {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #343a46;
        }

        .version-tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 220px;
            min-height: 56px;
            border: 1px solid #4b5668;
            border-radius: 8px;
            background: rgba(40, 44, 52, 0.92);
            color: #fff;
            padding: 11px 20px;
            font-size: 18px;
            font-weight: 700;
            letter-spacing: 0.2px;
            cursor: pointer;
            transition: all 0.2s ease-in-out;
        }

        .version-tab-icon {
            width: 24px;
            height: 24px;
            object-fit: contain;
            border-radius: 3px;
            flex: 0 0 auto;
        }

        .version-tab-logo {
            height: 30px;
            width: auto;
            object-fit: contain;
            display: block;
        }

        .version-tab[data-version-tab="chim"] {
            background-color: rgb(242, 124, 17);
            border-color: rgba(242, 124, 17, 0.95);
            color: #fff;
        }

        .version-tab[data-version-tab="chim"]:hover {
            background-color: rgb(221, 106, 6);
            border-color: rgba(221, 106, 6, 0.95);
            color: #fff;
        }

        .version-tab[data-version-tab="stobe"] {
            background-color: #e6b76c;
            border-color: #e6b76c;
            color: #fff;
        }

        .version-tab[data-version-tab="stobe"]:hover {
            background-color: #d2a45a;
            border-color: #d2a45a;
            color: #fff;
        }

        .version-tab:hover {
            border-color: rgba(230, 183, 108, 0.5);
            color: #f1c88c;
        }

        .version-tab.active {
            border-color: #e6b76c;
            color: #fff;
            background-color: rgba(59, 66, 78, 0.95);
            box-shadow: inset 0 1px rgba(255, 255, 255, 0.12);
        }

        .version-tab[data-version-tab="chim"].active,
        .version-tab[data-version-tab="stobe"].active {
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.14), inset 0 1px rgba(255, 255, 255, 0.18);
            filter: saturate(1.03);
        }

        .version-tab[data-version-tab="chim"].active {
            background-color: rgb(242, 124, 17);
            border-color: rgba(242, 124, 17, 0.95);
        }

        .version-tab[data-version-tab="stobe"].active {
            background-color: #e6b76c;
            border-color: #e6b76c;
        }

        /* Loading overlay */
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.85);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-content {
            text-align: center;
            color: #f8f9fa;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 6px solid rgba(138, 155, 182, 0.3);
            border-top: 6px solid #3b82f6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            font-size: 24px;
            margin-bottom: 10px;
            font-weight: bold;
        }

        .loading-subtext {
            font-size: 14px;
            color: #ccc;
            max-width: 400px;
            line-height: 1.5;
        }

        .loading-bar-container {
            width: 400px;
            height: 6px;
            background-color: rgba(138, 155, 182, 0.3);
            border-radius: 3px;
            margin: 20px auto 10px;
            overflow: hidden;
        }

        .loading-bar {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #60a5fa);
            animation: progress 2s ease-in-out infinite;
        }

        @keyframes progress {
            0% { width: 0%; }
            50% { width: 70%; }
            100% { width: 100%; }
        }
    </style>
</head>
<body>
    <!-- Loading Overlay -->
    <div id="importLoadingOverlay" class="loading-overlay">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <div class="loading-text">Importing Database...</div>
            <div class="loading-bar-container">
                <div class="loading-bar"></div>
            </div>
            <div class="loading-subtext">
                This may take several minutes for large databases.<br>
                Please do not close or refresh this page.
            </div>
        </div>
    </div>
<?php if ($isEmbed): ?>
<style> main { padding-top: 20px; } </style>
<?php endif; ?>
<div class="indent5">
    <div class="page-header">
        <div class="page-header-top">
            <h1>Database Manager</h1>
            <?php if (!$isEmbed): ?>
            <a class="back-link" href="<?php echo htmlspecialchars($dashboardWebRoot . '/index.php', ENT_QUOTES, 'UTF-8'); ?>">Back to Dashboard</a>
            <?php endif; ?>
        </div>
        <div class="page-subtitle">Manage database backups, imports, exports, and maintenance operations</div>
    </div>

    <div class="manager-sections">
    <!-- Main Grid Container -->
    <div class="manager-section">
    <div class="grid-container tools-grid">
        
        <!-- Database Manager Section -->
        <div class="card-tile">
            <div class="card-content">
                <h3>🗄️ Database Access</h3>
                <p>Access the pgAdmin database manager for advanced database management.</p>
                <p><strong>Login:</strong> username = dwemer & password = dwemer</p>
            </div>
            <div class="card-actions">
                <a href="/pgAdmin/" target="_blank" class="button" style="background-color: rgb(1 53 166 / 90%); color: white; width: 100%; text-align: center;">
                    Open Database Manager
                </a>
            </div>
        </div>
        
        <!-- Backup Section -->
        <div class="card-tile">
            <div class="card-content">
                <h3>📦 Manual Backup</h3>
                <p>Create a backup of your current CHIM and STOBE databases. This will generate one SQL file you can download.</p>
                <p style="color: #ccc; font-size: 14px;">Creates a one-time downloadable combined backup file.</p>
            </div>
            <div class="card-actions">
                <a href="?action=backup" class="button" style="background-color: #176529; color: white; width: 100%; text-align: center;">
                    Create Backup
                </a>
            </div>
        </div>
        
        <!-- Maintenance Section -->
        <div class="card-tile">
            <div class="card-content">
                <h3>🔧 Database Maintenance</h3>
                <p>Optimize and clean both HerikaServer and StobeServer databases. This will compact the databases and reclaim unused space.</p>
                <p><strong>⚠️ Important:</strong> Make sure Skyrim is stopped before running maintenance.</p>
            </div>
            <div class="card-actions">
                <button onclick="if (confirm('Database maintenance will optimize and compact the HerikaServer and StobeServer databases.\n\n- Make sure Skyrim game is stopped\n- To reclaim unused space, free temporary space is required\n- During this operation tables will be locked, do not interrupt\n- This could take some time, please wait until you see the confirmation\n\nContinue?')) { window.open('?action=maintenance', 'Database_maintenance', 'resizable=yes,scrollbars=yes,titlebar=no,width=900,height=700'); return false; }" 
                        class="button" style="background-color: #fd7e14; color: white; width: 100%;">
                    Run Database Maintenance
                </button>
            </div>
        </div>
        
        <!-- Factory Reset Section -->
        <div class="card-tile" style="border-color: #dc3545;">
            <div class="card-content">
                <h3>💥 Factory Reset Database</h3>
                <p>Completely wipe and reinstall either the HerikaServer or StobeServer database to its default configuration.</p>
                <p><strong>⚠️ DANGER:</strong> Each reset permanently deletes data for the selected database.</p>
            </div>
            <div class="card-actions">
                <div style="display: flex; gap: 10px; width: 100%;">
                    <button onclick="if (confirm('⚠️ FACTORY RESET HERIKASERVER\n\nThis will wipe and reinstall the HerikaServer database to its default configuration.\n\n❌ ALL HERIKASERVER DATA WILL BE PERMANENTLY LOST:\n- All event logs\n- All diaries and memories\n- All custom Oghma and NPC Biography management profiles\n\n✅ HerikaServer will be reset to fresh installation state\n\nThis action CANNOT be undone!\n\nAre you absolutely sure you want to continue?')) { window.open('?action=factory_reset&target=herika', 'Database_factory_reset', 'resizable=yes,scrollbars=yes,titlebar=no,width=980,height=720'); return false; }"
                            class="button" style="background-color: #dc3545; color: white; width: 100%;">
                        Factory Reset HerikaServer
                    </button>
                    <button onclick="if (confirm('⚠️ FACTORY RESET STOBESERVER\n\nThis will wipe and reinstall the StobeServer database to its default configuration.\n\n❌ ALL STOBESERVER DATA WILL BE PERMANENTLY LOST\n\n✅ StobeServer will be reset to fresh installation state\n\nThis action CANNOT be undone!\n\nAre you absolutely sure you want to continue?')) { window.open('?action=factory_reset&target=stobe', 'Database_factory_reset', 'resizable=yes,scrollbars=yes,titlebar=no,width=980,height=720'); return false; }"
                            class="button" style="background-color: #b91c1c; color: white; width: 100%;">
                        Factory Reset StobeServer
                    </button>
                </div>
            </div>
        </div>
        
    </div>
    </div>
    
    <!-- Second Row - Automatic Backups and Manual Restore Side by Side -->
    <?php
    $autoBackup = new DashboardAutomaticBackup();
    $automaticBackups = $autoBackup->getBackups();
    $totalBackupsSize = 0;
    foreach ($automaticBackups as &$backup) {
        $totalBackupsSize += $backup['size'];
        $backup['scope'] = inspectBackupScope($backup['filepath'], $backup['filename']);
    }
    unset($backup);
    // Load current retention from chim_meta.settings (fallback 5)
    $currentMax = 5;
    $autoEnabled = false;
    try {
        $dbTmp = new sql();
        $rowEn = dashboardReadSettingValue($dbTmp, 'AUTOMATIC_DATABASE_BACKUPS');
        if ($rowEn !== null) {
            $val = strtolower(trim($rowEn));
            $autoEnabled = in_array($val, ['true','1','yes','on'], true);
        }
        $rowMax = dashboardReadSettingValue($dbTmp, 'AUTOMATIC_BACKUP_MAX_COUNT');
        if ($rowMax !== null) {
            $v = intval($rowMax);
            if ($v > 0) {
                $currentMax = $v;
            }
        }
    } catch (Throwable $e) {}
    ?>
    <div class="manager-section">
    <div class="grid-container backup-restore-grid">
        
        <!-- Left Column: Automatic Backups -->
        <div class="message">
            <h3>🤖 Automatic Backup System</h3>
            <p>System-generated backups created automatically every time the server starts up. Keeps a maximum of <?php echo (int)$currentMax; ?> backups, automatically deleting the oldest when the limit is reached.</p>
            
            <div class="stats-grid">
                <div class="stat-tile">
                    <h5>Status</h5>
                    <form method="post" style="margin:0;">
                        <input type="hidden" name="action" value="toggle_auto_enabled">
                        <input type="hidden" name="embed" value="1">
                        <button type="submit" class="button" style="background-color: <?php echo ($autoEnabled ? '#176529' : '#6c757d'); ?>; color: #fff; padding: 6px 12px; font-size: 14px;">
                            <?php echo ($autoEnabled ? '✅ On' : '❌ Off'); ?>
                        </button>
                    </form>
                </div>
                
                <div class="stat-tile">
                    <h5>Available</h5>
                    <form method="post" style="margin:0; display:flex; gap:6px; justify-content:center; align-items:center;">
                        <input type="hidden" name="action" value="update_auto_max">
                        <input type="hidden" name="embed" value="1">
                        <span style="font-size: 16px; font-weight: bold; color: #f8f9fa;"><?php echo count($automaticBackups); ?> / </span>
                        <select name="auto_max" onchange="this.form.submit()">
                            <?php for ($i=1; $i<=10; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ((int)$currentMax === $i ? 'selected' : ''); ?>><?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                </div>
                
                <div class="stat-tile">
                    <h5>Total Size</h5>
                    <p class="stat-value" style="color: #eaee05;">
                        <?php echo DashboardAutomaticBackup::formatFileSize($totalBackupsSize); ?>
                    </p>
                </div>
            </div>
            

            <h4 style="margin: 15px 0 10px 0;">📂 Backup Management</h4>
            
            <?php if (!empty($automaticBackups)): ?>
                <div class="backup-list">
                    <?php foreach ($automaticBackups as $index => $backup): ?>
                        <div class="backup-item" style="<?php echo $index === count($automaticBackups) - 1 ? 'border-bottom: none;' : ''; ?>">
                            <div class="backup-info">
                                <div class="backup-details">
                                    <div class="backup-filename">
                                        <?php echo htmlspecialchars($backup['filename']); ?>
                                    </div>
                                    <div class="backup-badges">
                                        <span class="backup-scope-badge <?php echo htmlspecialchars(strval($backup['scope']['badge_class'] ?? 'backup-scope-herika')); ?>">
                                            <?php echo htmlspecialchars(strval($backup['scope']['scope_label'] ?? 'HerikaServer only')); ?>
                                        </span>
                                    </div>
                                    <div class="backup-meta">
                                        <span>📁 <?php echo DashboardAutomaticBackup::formatFileSize($backup['size']); ?></span>
                                    </div>
                                    <div class="backup-meta">
                                        <span>Modified <?php echo htmlspecialchars(strval($backup['formatted_date'] ?? '')); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="backup-actions">
                                <button onclick="window.location.href='?action=download_auto&filename=<?php echo urlencode($backup['filename']); ?>'" 
                                        class="button backup-btn" style="background-color: #176529;" 
                                        title="Download backup file">
                                    📥
                                </button>
                                <button onclick="if (confirm('⚠️ RESTORE DATABASES\n\nRestore from: <?php echo htmlspecialchars($backup['filename']); ?>\n\nBackup scope: <?php echo htmlspecialchars(strval($backup['scope']['scope_label'] ?? 'HerikaServer only')); ?>\n\nThis will COMPLETELY REPLACE the databases included in this backup.\n\n❌ Current data will be lost!\n✅ Databases will be restored to backup state\n\nAre you absolutely sure you want to continue?')) { window.location.href='?action=restore_auto&filename=<?php echo urlencode($backup['filename']); ?>'; }" 
                                        class="button backup-btn" style="background-color: rgb(1 53 166 / 90%);" 
                                        title="Restore database from this backup">
                                    🔄
                                </button>
                                <button onclick="if (confirm('⚠️ DELETE BACKUP\n\nDelete: <?php echo htmlspecialchars($backup['filename']); ?>\n\nThis action cannot be undone!\n\nAre you sure you want to permanently delete this backup?')) { window.location.href='?action=delete_auto&filename=<?php echo urlencode($backup['filename']); ?>'; }" 
                                        class="button backup-btn" style="background-color: rgba(166, 53, 63, 0.9);" 
                                        title="Delete this backup file">
                                    🗑️
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📂</div>
                    <p style="margin: 0;">No automatic backups available yet.</p>
                    <?php if ($autoEnabled): ?>
                        <small style="color: #ffffff; display: block; margin-top: 8px;">Backups will be created on server restart.</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Column: Server-Side File Import -->
        <div class="message">
            <h3>💾 Restore Manual Backup</h3>
            <p>Restore manual backup files from the server filesystem.</p>
            
            <div class="instruction-box">
                <h4 style="color: #4ade80; margin: 0 0 10px 0;">Instructions</h4>
                <ol style="color: #f8f9fa; margin: 0; padding-left: 20px; font-size: 14px;">
                    <li>Click [Open Server Folder] in DwemerDistro.exe</li>
                    <li>Navigate to: Dwemer-Dashboard/data/manualbackup</li>
                    <li>Copy your <code>backup.sql</code> backup file there</li>
                    <li>Refresh the page and select it from the list below and click Import. It may take a while to import so please don't refresh the page.</li>
                </ol>
                <p style="margin: 10px 0 0 0; font-size: 13px; color: #ccc;">This bypasses PHP upload limits and handles files of any size.</p>
            </div>
            
            <?php
            // Scan for SQL files in the manual backup directory
            $uploadsDir = $manualBackupDir;
            if (!file_exists($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }
            $sqlFiles = glob($uploadsDir . '*.sql');
            $sqlFileEntries = [];
            foreach ($sqlFiles as $sqlFile) {
                $filename = basename($sqlFile);
                $scope = inspectBackupScope($sqlFile, $filename);
                $filesize = filesize($sqlFile);
                $sqlFileEntries[] = [
                    'filename' => $filename,
                    'formatted_size' => formatFileSize($filesize),
                    'modified' => date('Y-m-d H:i:s', filemtime($sqlFile)),
                    'scope' => $scope,
                ];
            }
            ?>
            
            <?php if (!empty($sqlFileEntries)): ?>
                <form id="importForm" method="post" onsubmit="return handleImportSubmit(event);">
                    <input type="hidden" name="action" value="import_from_server">
                    <label style="color: #f8f9fa; font-weight: bold; display: block; margin-bottom: 8px;">Available SQL files on server:</label>
                    <div class="server-file-list">
                        <?php foreach ($sqlFileEntries as $index => $entry): ?>
                            <label class="server-file-option">
                                <input type="radio"
                                       name="server_file"
                                       value="<?php echo htmlspecialchars($entry['filename']); ?>"
                                       <?php echo $index === 0 ? 'checked' : ''; ?>
                                       data-scope-label="<?php echo htmlspecialchars(strval($entry['scope']['scope_label'] ?? 'HerikaServer only'), ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="server-file-card">
                                    <div class="server-file-card-header">
                                        <div class="backup-details">
                                            <div class="backup-filename"><?php echo htmlspecialchars($entry['filename']); ?></div>
                                            <div class="backup-badges">
                                                <span class="backup-scope-badge <?php echo htmlspecialchars(strval($entry['scope']['badge_class'] ?? 'backup-scope-herika')); ?>">
                                                    <?php echo htmlspecialchars(strval($entry['scope']['scope_label'] ?? 'HerikaServer only')); ?>
                                                </span>
                                            </div>
                                            <div class="backup-meta">
                                                <span>Size <?php echo htmlspecialchars($entry['formatted_size']); ?></span>
                                                <span>Modified <?php echo htmlspecialchars($entry['modified']); ?></span>
                                            </div>
                                            <?php if (empty($entry['scope']['explicit'])): ?>
                                                <div class="server-file-notes">Scope inferred from filename or legacy format.</div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="server-file-radio-indicator" aria-hidden="true"></span>
                                    </div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <input type="submit" class="button" value="🚀 Import from Server" 
                           style="background-color: #176529; color: white; padding: 12px 24px; margin-top: 10px; width: 100%; font-size: 16px;">
                </form>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📁</div>
                    <p style="margin: 0; color: #ccc;">No SQL files found in manual backup directory.</p>
                    <small style="color: #888; display: block; margin-top: 5px;">Place .sql files in Dwemer-Dashboard/data/manualbackup to import them.</small>
                </div>
            <?php endif; ?>
        </div>
        
    </div>
    </div>
    </div>

    <div class="section-divider"></div>

    <!-- Database Versioning Manager Section -->
    <?php
    $chimDbVersions = [];
    try {
        $chimDbVersions = $db->fetchAll("SELECT tablename, version FROM public.database_versioning ORDER BY tablename ASC");
    } catch (Throwable $e) {
        $chimDbVersions = [];
    }

    $stobeDbVersions = [];
    $stobeVersioningAvailable = false;
    $stobeVersioningError = '';
    try {
        $stobeConn = getStobePgConnection($host, $port, $username, $password);
        if ($stobeConn) {
            $stobeColumns = getPgVersioningColumns($stobeConn);
            if ($stobeColumns['table'] !== '') {
                $versionSelect = $stobeColumns['version'] !== ''
                    ? ", {$stobeColumns['version']} AS version_value"
                    : ", ''::text AS version_value";
                $stobeQuery = "SELECT {$stobeColumns['table']} AS table_name{$versionSelect} FROM public.database_versioning ORDER BY {$stobeColumns['table']} ASC";
                $stobeResult = @pg_query($stobeConn, $stobeQuery);
                if ($stobeResult) {
                    while ($row = @pg_fetch_assoc($stobeResult)) {
                        $stobeDbVersions[] = [
                            'tablename' => strval($row['table_name'] ?? ''),
                            'version' => strval($row['version_value'] ?? ''),
                        ];
                    }
                    @pg_free_result($stobeResult);
                    $stobeVersioningAvailable = true;
                } else {
                    $stobeVersioningError = trim(strval(@pg_last_error($stobeConn)));
                }
            } else {
                $stobeVersioningError = 'database_versioning table was not found in Stobe.';
            }
            @pg_close($stobeConn);
        } else {
            $stobeVersioningError = 'Failed to connect to Stobe database.';
        }
    } catch (Throwable $e) {
        $stobeVersioningError = $e->getMessage();
    }

    $activeVersionTab = strtolower(trim(strval($_GET['version_tab'] ?? 'chim')));
    if (!in_array($activeVersionTab, ['chim', 'stobe'], true)) {
        $activeVersionTab = 'chim';
    }
    ?>

    <div class="message versioning-manager">
        <h3>Database Versioning Manager</h3>
        <p>This table tracks which database updates have been applied. Resetting an entry will cause that specific update to be re-applied on the next server restart.</p>

        <div class="instruction-box">
            <h4 style="margin: 0 0 10px 0;">How It Works</h4>
            <ul style="color: #f8f9fa; margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.6;">
                <li>Each entry represents a database update that has been applied.</li>
                <li><strong>Reset Individual Entry:</strong> Deletes one entry in the selected tab.</li>
                <li><strong>Reset All:</strong> Deletes all entries in the selected tab.</li>
                <li><strong>Important:</strong> Changes take effect only after restarting the server.</li>
            </ul>
        </div>

        <div class="versioning-tabs">
            <button type="button" class="version-tab <?php echo $activeVersionTab === 'chim' ? 'active' : ''; ?>" data-version-tab="chim">
                <img class="version-tab-icon" src="images/chim-icon.png" alt="" aria-hidden="true">
                <img class="version-tab-logo" src="images/chim-logo.png" alt="CHIM">
            </button>
            <button type="button" class="version-tab <?php echo $activeVersionTab === 'stobe' ? 'active' : ''; ?>" data-version-tab="stobe">
                <img class="version-tab-icon" src="images/stobe-icon.png" alt="" aria-hidden="true">
                <img class="version-tab-logo" src="images/stobe-logo.png" alt="STOBE">
            </button>
        </div>

        <div class="versioning-panels">
            <div class="versioning-panel <?php echo $activeVersionTab === 'chim' ? 'active' : ''; ?>" data-version-panel="chim">
                <?php if (!empty($chimDbVersions)): ?>
                    <div class="version-panel-title-row">
                        <h4 style="margin: 0;">CHIM Version Entries (<?php echo count($chimDbVersions); ?> total)</h4>
                        <form method="post" style="margin: 0;" onsubmit="return confirm('Reset ALL CHIM database version entries? This will cause all CHIM DB updates to re-apply on restart.');">
                            <input type="hidden" name="action" value="reset_all_db_versions">
                            <input type="hidden" name="version_target" value="herika">
                            <button type="submit" class="button" style="background-color: #dc3545; color: white; padding: 8px 16px; font-size: 14px;">
                                Reset All Versions
                            </button>
                        </form>
                    </div>

                    <div class="version-table-container">
                        <table class="version-table">
                            <thead>
                                <tr>
                                    <th style="text-align: left;">Table/Feature Name</th>
                                    <th style="text-align: left;">Version</th>
                                    <th style="text-align: center; width: 120px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($chimDbVersions as $entry): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?></td>
                                        <td style="font-size: 12px; color: #ccc;"><?php echo htmlspecialchars(formatVersionDate(strval($entry['version'] ?? ''))); ?></td>
                                        <td style="text-align: center;">
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Reset CHIM version entry for <?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?>?');">
                                                <input type="hidden" name="action" value="reset_db_version">
                                                <input type="hidden" name="version_target" value="herika">
                                                <input type="hidden" name="tablename" value="<?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?>">
                                                <button type="submit" class="button" style="background-color: #fd7e14; color: white; padding: 4px 12px; font-size: 12px;">
                                                    Reset
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">DB</div>
                        <p style="margin: 0;">No CHIM database versioning entries found.</p>
                        <small style="color: #666; display: block; margin-top: 8px;">The CHIM database_versioning table is empty or does not exist.</small>
                    </div>
                <?php endif; ?>
            </div>

            <div class="versioning-panel <?php echo $activeVersionTab === 'stobe' ? 'active' : ''; ?>" data-version-panel="stobe">
                <?php if ($stobeVersioningAvailable && !empty($stobeDbVersions)): ?>
                    <div class="version-panel-title-row">
                        <h4 style="margin: 0;">STOBE Version Entries (<?php echo count($stobeDbVersions); ?> total)</h4>
                        <form method="post" style="margin: 0;" onsubmit="return confirm('Reset ALL STOBE database version entries? This will cause all STOBE DB updates to re-apply on restart.');">
                            <input type="hidden" name="action" value="reset_all_db_versions">
                            <input type="hidden" name="version_target" value="stobe">
                            <button type="submit" class="button" style="background-color: #dc3545; color: white; padding: 8px 16px; font-size: 14px;">
                                Reset All Versions
                            </button>
                        </form>
                    </div>

                    <div class="version-table-container">
                        <table class="version-table">
                            <thead>
                                <tr>
                                    <th style="text-align: left;">Table/Feature Name</th>
                                    <th style="text-align: left;">Version</th>
                                    <th style="text-align: center; width: 120px;">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stobeDbVersions as $entry): ?>
                                    <tr>
                                        <td style="font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?></td>
                                        <td style="font-size: 12px; color: #ccc;"><?php echo htmlspecialchars(formatVersionDate(strval($entry['version'] ?? ''))); ?></td>
                                        <td style="text-align: center;">
                                            <form method="post" style="margin: 0;" onsubmit="return confirm('Reset STOBE version entry for <?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?>?');">
                                                <input type="hidden" name="action" value="reset_db_version">
                                                <input type="hidden" name="version_target" value="stobe">
                                                <input type="hidden" name="tablename" value="<?php echo htmlspecialchars(strval($entry['tablename'] ?? '')); ?>">
                                                <button type="submit" class="button" style="background-color: #fd7e14; color: white; padding: 4px 12px; font-size: 12px;">
                                                    Reset
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">DB</div>
                        <p style="margin: 0;">No STOBE database versioning entries available.</p>
                        <small style="color: #666; display: block; margin-top: 8px;">
                            <?php echo htmlspecialchars($stobeVersioningError !== '' ? $stobeVersioningError : 'The STOBE database_versioning table is empty.'); ?>
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <?php
    if (!empty($message)) {
        echo '<div class="message">';
        echo $message;
        echo '</div>';
    }
    ?>
</div>

<script>
function handleImportSubmit(event) {
    const selectedFile = document.querySelector('input[name="server_file"]:checked');
    const scopeLabel = selectedFile ? (selectedFile.getAttribute('data-scope-label') || 'HerikaServer only') : 'HerikaServer only';
    const confirmed = confirm('⚠️ RESTORE DATABASES\n\nSelected backup scope: ' + scopeLabel + '\n\nThis will COMPLETELY REPLACE the databases included in the selected backup.\n\n❌ Current data will be lost!\n✅ Databases will be restored to backup state\n\nAre you absolutely sure?');
    
    if (confirmed) {
        // Show loading overlay
        document.getElementById('importLoadingOverlay').classList.add('active');
        return true; // Allow form submission
    }
    
    return false; // Cancel form submission
}

function initVersioningTabs() {
    const tabButtons = Array.from(document.querySelectorAll('[data-version-tab]'));
    const tabPanels = Array.from(document.querySelectorAll('[data-version-panel]'));
    if (tabButtons.length === 0 || tabPanels.length === 0) {
        return;
    }

    const applyTab = (targetTab, updateUrl) => {
        const normalized = (targetTab === 'stobe') ? 'stobe' : 'chim';

        tabButtons.forEach((button) => {
            const isActive = button.getAttribute('data-version-tab') === normalized;
            button.classList.toggle('active', isActive);
        });

        tabPanels.forEach((panel) => {
            const isActive = panel.getAttribute('data-version-panel') === normalized;
            panel.classList.toggle('active', isActive);
        });

        if (updateUrl && window.history && typeof window.history.replaceState === 'function') {
            const url = new URL(window.location.href);
            url.searchParams.set('version_tab', normalized);
            window.history.replaceState({}, '', url.toString());
        }
    };

    tabButtons.forEach((button) => {
        button.addEventListener('click', () => {
            applyTab(button.getAttribute('data-version-tab') || 'chim', true);
        });
    });

    const initialTab = new URL(window.location.href).searchParams.get('version_tab') || 'chim';
    applyTab(initialTab, false);
}

document.addEventListener('DOMContentLoaded', initVersioningTabs);
</script>

</body>
</html>



