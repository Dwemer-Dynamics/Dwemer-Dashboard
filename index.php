<?php
declare(strict_types=1);

error_reporting(E_ERROR);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/lib/api_keys_access.php';
// Only the browser on this PC receives an API Keys token; other clients see the rail as unavailable.
$apiKeysCsrf = api_keys_local_request($_SERVER, false) ? api_keys_csrf() : '';

$TITLE = 'DwemerDashboard';

$resolveServerRoot = static function (array $candidates): string {
    foreach ($candidates as $candidate) {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
        $dbUpdatePath = $normalized . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php';
        if (is_dir($normalized) && file_exists($dbUpdatePath)) {
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

$stobeRoot = $resolveServerRoot([
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'StobeServer',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'StobeServer',
    '/var/www/html/StobeServer',
]);

$dialecticRoot = $resolveServerRoot([
    __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'DialecticServer',
    dirname(__DIR__) . DIRECTORY_SEPARATOR . 'DialecticServer',
    '/var/www/html/DialecticServer',
]);

$herikaUpdateStatus = 'unavailable';
$herikaUpdateDetail = 'HerikaServer path not found, DB update was not triggered.';
$stobeUpdateStatus = 'unavailable';
$stobeUpdateDetail = 'StobeServer path not found, DB update was not triggered.';
$dialecticUpdateStatus = 'unavailable';
$dialecticUpdateDetail = 'DialecticServer path not found, DB update was not triggered.';
$hasCustomBackground = file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'background.jpg');

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'herika_profile_bootstrap.php');

if ($herikaRoot !== '') {
    try {
        $herikaRunner = $herikaRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'apply_db_updates.php';
        $disableFunctions = strtolower(strval(ini_get('disable_functions') ?: ''));
        $canExec = function_exists('exec') && strpos($disableFunctions, 'exec') === false;
        $runHerikaDbUpdatesInline = static function () use ($herikaRoot): void {
            dashboardBootstrapHerikaProfile($herikaRoot);
            $dbDriver = trim(strval($GLOBALS['DBDRIVER'] ?? ''));
            if ($dbDriver === '') {
                throw new RuntimeException('DBDRIVER not set after dashboard bootstrap');
            }
            require_once($herikaRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . $dbDriver . '.class.php');
            if (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
                $GLOBALS['db'] = new sql();
            }
            $db = $GLOBALS['db'];
            require_once($herikaRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php');
            require_once($herikaRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'npc_removal.php');
        };

        if ($canExec && is_file($herikaRunner)) {
            $cliCandidates = [];
            $pushCandidate = static function (string $candidate) use (&$cliCandidates): void {
                $value = trim($candidate);
                if ($value === '') {
                    return;
                }
                if (!in_array($value, $cliCandidates, true)) {
                    $cliCandidates[] = $value;
                }
            };

            $phpCliBinary = trim(strval(PHP_BINARY ?? ''));
            $phpCliBasename = strtolower(basename($phpCliBinary));
            if (
                $phpCliBinary !== '' &&
                strpos($phpCliBasename, 'php') !== false &&
                strpos($phpCliBasename, 'apache') === false
            ) {
                $pushCandidate($phpCliBinary);
            }
            $pushCandidate('/usr/bin/php');
            $pushCandidate('/usr/local/bin/php');
            $pushCandidate('php');

            $ranCli = false;
            $lastCliError = '';
            foreach ($cliCandidates as $candidate) {
                $output = [];
                $exitCode = 0;
                $command = escapeshellarg($candidate) . ' ' . escapeshellarg($herikaRunner) . ' 2>&1';
                exec($command, $output, $exitCode);
                if ($exitCode === 0) {
                    $ranCli = true;
                    break;
                }
                $cliError = trim(implode("\n", $output));
                if ($cliError === '') {
                    $cliError = 'unknown error';
                }
                $lastCliError = '[php=' . $candidate . '] ' . $cliError;
            }

            if (!$ranCli) {
                error_log('[DwemerDashboard] Herika DB update CLI runner failed: ' . $lastCliError);
                $runHerikaDbUpdatesInline();
            }
        } else {
            $runHerikaDbUpdatesInline();
        }

        $herikaUpdateStatus = 'ok';
        $herikaUpdateDetail = 'HerikaServer database versioning check completed.';
    } catch (Throwable $e) {
        $errorSummary = trim($e->getMessage());
        $errorSummary = preg_replace('/\s+/', ' ', $errorSummary) ?? $errorSummary;
        if ($errorSummary === '') {
            $errorSummary = 'unknown error';
        }
        if (strlen($errorSummary) > 180) {
            $errorSummary = substr($errorSummary, 0, 180) . '...';
        }
        $herikaUpdateStatus = 'error';
        $herikaUpdateDetail = 'HerikaServer DB update trigger failed: ' . $errorSummary;
        error_log('[DwemerDashboard] Herika DB update trigger failed: ' . $e->getMessage());
    }
}

if ($stobeRoot !== '') {
    try {
        $stobeRunner = $stobeRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'run_db_updates.php';
        $disableFunctions = strtolower(strval(ini_get('disable_functions') ?: ''));
        $canExec = function_exists('exec') && strpos($disableFunctions, 'exec') === false;
        $runStobeDbUpdatesInline = static function () use ($stobeRoot): void {
            // Fallback when CLI execution is unavailable or fails: run updates inline.
            if (
                (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) &&
                file_exists($stobeRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php')
            ) {
                require_once($stobeRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php');
            }
            if (!function_exists('stobeLogInfo')) {
                function stobeLogInfo(string $message, array $context = []): void {}
            }
            if (!function_exists('stobeLogWarn')) {
                function stobeLogWarn(string $message, array $context = []): void {}
            }
            if (!function_exists('stobeLogException')) {
                function stobeLogException(Throwable $exception, string $message = '', array $context = []): void {}
            }
            require_once($stobeRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php');
        };

        if ($canExec && is_file($stobeRunner)) {
            $cliCandidates = [];
            $pushCandidate = static function (string $candidate) use (&$cliCandidates): void {
                $value = trim($candidate);
                if ($value === '') {
                    return;
                }
                if (!in_array($value, $cliCandidates, true)) {
                    $cliCandidates[] = $value;
                }
            };

            $phpCliBinary = trim(strval(PHP_BINARY ?? ''));
            $phpCliBasename = strtolower(basename($phpCliBinary));
            if (
                $phpCliBinary !== '' &&
                strpos($phpCliBasename, 'php') !== false &&
                strpos($phpCliBasename, 'apache') === false
            ) {
                $pushCandidate($phpCliBinary);
            }
            $pushCandidate('/usr/bin/php');
            $pushCandidate('/usr/local/bin/php');
            $pushCandidate('php');

            $ranCli = false;
            $lastCliError = '';
            foreach ($cliCandidates as $candidate) {
                $output = [];
                $exitCode = 0;
                $command = escapeshellarg($candidate) . ' ' . escapeshellarg($stobeRunner) . ' 2>&1';
                exec($command, $output, $exitCode);
                if ($exitCode === 0) {
                    $ranCli = true;
                    break;
                }
                $cliError = trim(implode("\n", $output));
                if ($cliError === '') {
                    $cliError = 'unknown error';
                }
                $lastCliError = '[php=' . $candidate . '] ' . $cliError;
            }

            if (!$ranCli) {
                error_log('[DwemerDashboard] Stobe DB update CLI runner failed: ' . $lastCliError);
                $runStobeDbUpdatesInline();
            }
        } else {
            $runStobeDbUpdatesInline();
        }

        $stobeUpdateStatus = 'ok';
        $stobeUpdateDetail = 'StobeServer database versioning check completed.';
    } catch (Throwable $e) {
        $errorSummary = trim($e->getMessage());
        $errorSummary = preg_replace('/\s+/', ' ', $errorSummary) ?? $errorSummary;
        if ($errorSummary === '') {
            $errorSummary = 'unknown error';
        }
        if (strlen($errorSummary) > 180) {
            $errorSummary = substr($errorSummary, 0, 180) . '...';
        }
        $stobeUpdateStatus = 'error';
        $stobeUpdateDetail = 'StobeServer DB update trigger failed: ' . $errorSummary;
        error_log('[DwemerDashboard] Stobe DB update trigger failed: ' . $e->getMessage());
    }
}

if ($dialecticRoot !== '') {
    try {
        $dialecticRunner = $dialecticRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'bootstrap-database.php';
        $disableFunctions = strtolower(strval(ini_get('disable_functions') ?: ''));
        $canExec = function_exists('exec') && strpos($disableFunctions, 'exec') === false;
        if (!$canExec || !is_file($dialecticRunner)) {
            throw new RuntimeException('Dialectic database bootstrap CLI runner is unavailable');
        }

        $cliCandidates = array_values(array_unique(array_filter([
            trim(strval(PHP_BINARY ?? '')),
            '/usr/bin/php',
            '/usr/local/bin/php',
            'php',
        ])));
        $ranCli = false;
        $lastCliError = '';
        foreach ($cliCandidates as $candidate) {
            $basename = strtolower(basename($candidate));
            if ($candidate === trim(strval(PHP_BINARY ?? '')) && strpos($basename, 'apache') !== false) {
                continue;
            }
            $output = [];
            $exitCode = 0;
            exec(escapeshellarg($candidate) . ' ' . escapeshellarg($dialecticRunner) . ' 2>&1', $output, $exitCode);
            if ($exitCode === 0) {
                $ranCli = true;
                break;
            }
            $lastCliError = trim(implode("\n", $output)) ?: 'unknown error';
        }
        if (!$ranCli) {
            throw new RuntimeException($lastCliError);
        }

        $dialecticUpdateStatus = 'ok';
        $dialecticUpdateDetail = 'DialecticServer database versioning check completed.';
    } catch (Throwable $e) {
        $errorSummary = preg_replace('/\s+/', ' ', trim($e->getMessage())) ?: 'unknown error';
        if (strlen($errorSummary) > 180) {
            $errorSummary = substr($errorSummary, 0, 180) . '...';
        }
        $dialecticUpdateStatus = 'error';
        $dialecticUpdateDetail = 'DialecticServer DB update trigger failed: ' . $errorSummary;
        error_log('[DwemerDashboard] Dialectic DB update trigger failed: ' . $e->getMessage());
    }
}

if (
    $herikaRoot !== '' &&
    (
        !isset($GLOBALS['DBDRIVER']) ||
        trim(strval($GLOBALS['DBDRIVER'])) === '' ||
        !class_exists('sql')
    )
) {
    dashboardBootstrapHerikaProfile($herikaRoot);
    $dbDriver = trim(strval($GLOBALS['DBDRIVER'] ?? ''));
    if ($dbDriver !== '') {
        require_once($herikaRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . $dbDriver . '.class.php');
    }
}

if ($herikaRoot !== '' && class_exists('sql') && (!isset($GLOBALS['db']) || !is_object($GLOBALS['db']))) {
    $GLOBALS['db'] = new sql();
}

require_once(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'automatic_backup.php');
if (function_exists('deferredDashboardAutomaticBackupInit')) {
    deferredDashboardAutomaticBackupInit();
}

// Reign applies migrations before accepting requests; inspect its managed runtime, never launch a second server.
$reignUpdateStatus = 'unavailable';
$reignUpdateDetail = 'ReignServer is not installed; database versioning was not checked.';
if ((is_file('/var/www/html/ReignServer/runtime/current/ReignServer') || is_file('/var/www/html/ReignServer/runtime/current/ReignBetaServer'))) {
    $reignUpdateDetail = 'ReignServer is stopped or unavailable; database versioning will be checked when it starts.';
    $reignHealthJson = @file_get_contents('http://127.0.0.1:5101/health', false,
        stream_context_create(['http' => ['timeout' => 2, 'follow_location' => 0]]), 0, 16384);
    $reignHealth = $reignHealthJson === false ? null : json_decode($reignHealthJson, true);
    if (is_array($reignHealth) && ($reignHealth['service'] ?? '') === 'BannerlordReignServer') {
        $reignDbVersion = $reignHealth['databaseSchemaVersion'] ?? null;
        if (($reignHealth['ok'] ?? false) === true && is_int($reignDbVersion) && $reignDbVersion > 0
            && $reignDbVersion === ($reignHealth['requiredDatabaseSchemaVersion'] ?? null)
            && ($reignHealth['databaseSchemaUpToDate'] ?? false) === true) {
            $reignUpdateStatus = 'ok';
            $reignUpdateDetail = 'ReignServer database versioning check completed.';
        } else {
            $reignUpdateStatus = 'error';
            $reignUpdateDetail = 'ReignServer database version could not be verified. Update or repair Reign in the launcher.';
        }
    }
}

$lorkhanRoot = is_file('/var/www/html/LorkhanServer/ui/home.php') ? '/var/www/html/LorkhanServer' : '';
$lorkhanUpdateStatus = 'unavailable';
$lorkhanUpdateDetail = 'LorkhanServer is not installed; database versioning was not checked.';
if ($lorkhanRoot !== '') {
    try {
        // Use the server's checksum/ledger validation without initializing or applying migrations.
        $lorkhanCurrent = (static function (string $root): bool {
            require_once $root . '/lib/Autoload.php';
            $configFile = getenv('LORKHAN_CONFIG') ?: '/etc/lorkhanserver/server.php';
            if (!is_file($configFile)) throw new RuntimeException('Lorkhan configuration unavailable.');
            $config = require $configFile;
            if (!is_array($config)) throw new RuntimeException('Lorkhan configuration invalid.');
            $config['database_password'] = getenv('LORKHAN_DATABASE_PASSWORD') ?: (string) ($config['database_password'] ?? '');
            $config['database_dsn'] = rtrim((string) ($config['database_dsn'] ?? ''), ';') . ';connect_timeout=2';
            $connection = \LorkhanServer\Infrastructure\Connection::open($config);
            $connection->exec("SET statement_timeout='2000ms'");
            $connection->exec('SET default_transaction_read_only=on');
            $rows = (new \LorkhanServer\Infrastructure\MigrationRunner($connection, $root . '/data/migrations'))->status(false);
            return $rows !== [] && count(array_filter($rows, static fn(array $row): bool => !$row['applied'])) === 0;
        })($lorkhanRoot);
        $lorkhanUpdateStatus = $lorkhanCurrent ? 'ok' : 'error';
        $lorkhanUpdateDetail = $lorkhanCurrent ? 'LorkhanServer database versioning check completed.'
            : 'LorkhanServer has pending database updates. Update or repair Lorkhan in the launcher.';
    } catch (Throwable) {
        $lorkhanUpdateStatus = 'error';
        $lorkhanUpdateDetail = 'LorkhanServer database version could not be verified. Start, update or repair Lorkhan in the launcher.';
    }
}

// Only report installed servers (same presence checks as the mod cards); unavailable checks stay silent.
$dbUpdateLines = array_values(array_filter([
    ['status' => $herikaUpdateStatus, 'detail' => $herikaUpdateDetail, 'installed' => $herikaRoot !== '' && is_file($herikaRoot . '/ui/home.php')],
    ['status' => $lorkhanUpdateStatus, 'detail' => $lorkhanUpdateDetail, 'installed' => $lorkhanRoot !== '' && is_file($lorkhanRoot . '/ui/home.php')],
    ['status' => $dialecticUpdateStatus, 'detail' => $dialecticUpdateDetail, 'installed' => $dialecticRoot !== '' && is_file($dialecticRoot . '/ui/home.php')],
    ['status' => $stobeUpdateStatus, 'detail' => $stobeUpdateDetail, 'installed' => $stobeRoot !== '' && is_file($stobeRoot . '/ui/home.php')],
    ['status' => $reignUpdateStatus, 'detail' => $reignUpdateDetail,
        'installed' => is_file('/var/www/html/ReignServer/runtime/current/ReignServer') || is_file('/var/www/html/ReignServer/runtime/current/ReignBetaServer')],
], static fn(array $line): bool => $line['installed'] && (string) $line['status'] !== 'unavailable'));

$patreonCampaignUrl = 'https://www.patreon.com/DwemerDynamics';

$requestHostRaw = trim((string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost')));
$requestScheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$parsedHost = parse_url((str_contains($requestHostRaw, '://') ? $requestHostRaw : ('http://' . $requestHostRaw)), PHP_URL_HOST);
$dashboardHost = $parsedHost ?: preg_replace('/:\d+$/', '', $requestHostRaw);
if ($dashboardHost === '' || $dashboardHost === null) {
    $dashboardHost = 'localhost';
}
$stobeHostForUrl = $dashboardHost;
if (str_contains($stobeHostForUrl, ':') && !str_starts_with($stobeHostForUrl, '[')) {
    $stobeHostForUrl = '[' . $stobeHostForUrl . ']';
}
$chimUrl = sprintf('%s://%s:8081/HerikaServer/ui/home.php', $requestScheme, $stobeHostForUrl);
$stobeUrl = sprintf('%s://%s:8083/StobeServer/ui/home.php', $requestScheme, $stobeHostForUrl);
$dialecticUrl = sprintf('%s://%s:8088/DialecticServer/ui/home.php', $requestScheme, $stobeHostForUrl);
$reignUrl = sprintf('%s://%s:8091/', $requestScheme, $stobeHostForUrl);
// Keep an existing launcher route; otherwise use Lorkhan's native server port.
$requestPort = parse_url('http://' . $requestHostRaw, PHP_URL_PORT);
$lorkhanPort = $requestPort === 7514 ? 7514 : 8090;
$lorkhanUrl = sprintf('%s://%s:%d/LorkhanServer/ui/home.php', $requestScheme, $stobeHostForUrl, $lorkhanPort);
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'server_version.php');
// versionFiles: each server's own installed release metadata, in that server's read order.
$modCards = [
    ['name' => 'CHIM', 'game' => 'Skyrim / Skyrim VR', 'image' => 'chim-rail.jpg', 'url' => $chimUrl, 'root' => $herikaRoot,
        'versionFiles' => ['.version_number.txt']],
    ['name' => 'LORKHAN', 'game' => 'Morrowind / OpenMW', 'image' => 'lorkhan-rail.jpg', 'url' => $lorkhanUrl, 'root' => $lorkhanRoot,
        'versionFiles' => ['version.txt']],
    ['name' => 'DIALECTIC', 'game' => 'Fallout: New Vegas / TTW', 'image' => 'dialectic-rail.jpg', 'url' => $dialecticUrl, 'root' => $dialecticRoot,
        'versionFiles' => ['.version_number.txt']],
    ['name' => 'STOBE', 'game' => 'Kenshi', 'image' => 'stobe-rail.jpg', 'url' => $stobeUrl, 'root' => $stobeRoot,
        'versionFiles' => ['versionnumber.txt', 'version.txt', '.version_number.txt']],
    ['name' => 'REIGN', 'game' => 'Mount & Blade II: Bannerlord', 'image' => 'reign-logo.png', 'url' => $reignUrl,
        'versionFiles' => ['.version_number.txt'],
        'root' => (is_file('/var/www/html/ReignServer/runtime/current/ReignServer') || is_file('/var/www/html/ReignServer/runtime/current/ReignBetaServer')) ? '/var/www/html/ReignServer/runtime/current' : ''],
];
$distroDebuggerUrl = 'distro_debugger.php';
$databaseManagerUrl = 'data_manager.php?mod=all&view=playthroughs';
$databaseManagerLabel = 'Playthrough Saves';
// Sizes load in the browser after page load from the read-only Playthrough Saves APIs.
$storageMods = [
    ['key' => 'chim', 'label' => 'CHIM', 'installed' => $herikaRoot !== ''],
    ['key' => 'dialectic', 'label' => 'DIALECTIC', 'installed' => $dialecticRoot !== ''],
    ['key' => 'stobe', 'label' => 'STOBE', 'installed' => $stobeRoot !== ''],
];
require_once(__DIR__ . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'custom_mods.php');
$customMods = dashboard_custom_mods(DASHBOARD_CUSTOM_MOD_REGISTRY);

$normalizePatronName = static function (string $name): string {
    $normalized = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    return $normalized;
};
$normalizeTierTitle = static function (string $name): string {
    $normalized = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    return $normalized;
};
$extractPatronNameAndTier = static function ($member) use ($normalizePatronName, $normalizeTierTitle): array {
    $name = '';
    $tier = '';

    if (is_array($member)) {
        if (isset($member['name']) && is_string($member['name'])) {
            $name = $normalizePatronName($member['name']);
        }
        if (isset($member['tier']) && is_string($member['tier'])) {
            $tier = $normalizeTierTitle($member['tier']);
        }
    } elseif (is_string($member)) {
        $rawMember = trim($member);
        if ($rawMember !== '') {
            $separatorAt = strrpos($rawMember, ' - ');
            if ($separatorAt !== false) {
                $candidateName = $normalizePatronName(substr($rawMember, 0, $separatorAt));
                $candidateTier = $normalizeTierTitle(substr($rawMember, $separatorAt + 3));
                if ($candidateName !== '' && $candidateTier !== '') {
                    $name = $candidateName;
                    $tier = $candidateTier;
                }
            }

            if ($name === '') {
                $name = $normalizePatronName($rawMember);
            }
        }
    }

    return [
        'name' => $name,
        'tier' => $tier,
    ];
};
$classifyPatronTier = static function (string $tierTitle): string {
    $normalizedTier = strtolower(trim($tierTitle));
    if ($normalizedTier === '') {
        return '';
    }
    if (str_contains($normalizedTier, 'gold')) {
        return 'Gold';
    }
    if (str_contains($normalizedTier, 'silver')) {
        return 'Silver';
    }
    if (str_contains($normalizedTier, 'bronze')) {
        return 'Bronze';
    }
    return '';
};

$staticPatronMembers = [];
$pushStaticPatronMember = static function (string $name, string $tier = '') use (&$staticPatronMembers, $normalizePatronName, $normalizeTierTitle): void {
    $normalizedName = $normalizePatronName($name);
    if ($normalizedName === '') {
        return;
    }
    $normalizedTier = $normalizeTierTitle($tier);
    $displayValue = $normalizedName;
    if ($normalizedTier !== '') {
        $displayValue .= ' - ' . $normalizedTier;
    }
    $staticPatronMembers[] = $displayValue;
};

$generatedPatronFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'patron_members.generated.json';
$loadedGeneratedPatrons = false;
if (is_file($generatedPatronFile)) {
    $rawGeneratedPatrons = file_get_contents($generatedPatronFile);
    if ($rawGeneratedPatrons !== false) {
        $decodedGeneratedPatrons = json_decode($rawGeneratedPatrons, true);
        if (is_array($decodedGeneratedPatrons)) {
            $generatedRows = $decodedGeneratedPatrons;
            if (isset($decodedGeneratedPatrons['members']) && is_array($decodedGeneratedPatrons['members'])) {
                $generatedRows = $decodedGeneratedPatrons['members'];
            }

            foreach ($generatedRows as $generatedRow) {
                if (is_string($generatedRow)) {
                    $pushStaticPatronMember($generatedRow);
                    continue;
                }
                if (!is_array($generatedRow) || !isset($generatedRow['name']) || !is_string($generatedRow['name'])) {
                    continue;
                }
                $generatedTier = isset($generatedRow['tier']) && is_string($generatedRow['tier'])
                    ? $generatedRow['tier']
                    : '';
                $pushStaticPatronMember($generatedRow['name'], $generatedTier);
            }
        }
    }
}

if ($staticPatronMembers !== []) {
    $loadedGeneratedPatrons = true;
}

if (!$loadedGeneratedPatrons) {
    $dashboardPatronFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'patron_members.json';
    if (is_file($dashboardPatronFile)) {
        $rawDashboardPatrons = file_get_contents($dashboardPatronFile);
        if ($rawDashboardPatrons !== false) {
            $decodedDashboardPatrons = json_decode($rawDashboardPatrons, true);
            if (is_array($decodedDashboardPatrons)) {
                foreach ($decodedDashboardPatrons as $patronEntry) {
                    if (is_string($patronEntry)) {
                        $pushStaticPatronMember($patronEntry);
                        continue;
                    }
                    if (!is_array($patronEntry) || !isset($patronEntry['name']) || !is_string($patronEntry['name'])) {
                        continue;
                    }
                    $fallbackTier = isset($patronEntry['tier']) && is_string($patronEntry['tier'])
                        ? $patronEntry['tier']
                        : '';
                    $pushStaticPatronMember($patronEntry['name'], $fallbackTier);
                }
            }
        }
    }
}

$staticPatronMembers = array_values(array_unique($staticPatronMembers));
natcasesort($staticPatronMembers);
$staticPatronMembers = array_values($staticPatronMembers);

$httpGetJson = static function (string $url, array $headers, int $timeoutSeconds = 10): array {
    $statusCode = 0;
    $body = '';

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Failed to initialize cURL');
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, min(5, $timeoutSeconds));
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_USERAGENT, 'DwemerDashboard/1.0');
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 2);

        $result = curl_exec($curl);
        if ($result === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException('HTTP request failed: ' . $error);
        }

        $body = (string)$result;
        $statusCode = intval(curl_getinfo($curl, CURLINFO_HTTP_CODE));
        curl_close($curl);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
        ]);

        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            throw new RuntimeException('HTTP request failed via file_get_contents');
        }
        $body = (string)$result;

        $responseHeaders = $http_response_header ?? [];
        if (isset($responseHeaders[0]) && preg_match('/\s(\d{3})\s/', $responseHeaders[0], $matches) === 1) {
            $statusCode = intval($matches[1]);
        }
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('HTTP response was not valid JSON');
    }

    return ['statusCode' => $statusCode, 'payload' => $decoded];
};

$extractApiError = static function (array $payload): string {
    if (isset($payload['errors']) && is_array($payload['errors']) && isset($payload['errors'][0]) && is_array($payload['errors'][0])) {
        $firstError = $payload['errors'][0];
        $detail = trim((string)($firstError['detail'] ?? ''));
        if ($detail !== '') {
            return $detail;
        }
        $title = trim((string)($firstError['title'] ?? ''));
        if ($title !== '') {
            return $title;
        }
    }

    $message = trim((string)($payload['message'] ?? ''));
    if ($message !== '') {
        return $message;
    }

    return 'unknown error';
};

$extractCursorFromUrl = static function (string $url): string {
    $query = parse_url($url, PHP_URL_QUERY);
    if (!is_string($query) || $query === '') {
        return '';
    }

    $queryParams = [];
    parse_str($query, $queryParams);
    $rawCursor = $queryParams['page']['cursor'] ?? '';
    return is_string($rawCursor) ? trim($rawCursor) : '';
};

$readLivePatronCache = static function (string $cachePath) use ($normalizePatronName): ?array {
    if (!is_file($cachePath)) {
        return null;
    }
    $rawCache = file_get_contents($cachePath);
    if ($rawCache === false) {
        return null;
    }
    $decodedCache = json_decode($rawCache, true);
    if (!is_array($decodedCache) || !isset($decodedCache['members']) || !is_array($decodedCache['members'])) {
        return null;
    }

    $members = [];
    foreach ($decodedCache['members'] as $member) {
        if (!is_string($member)) {
            continue;
        }
        $normalized = $normalizePatronName($member);
        if ($normalized !== '') {
            $members[] = $normalized;
        }
    }
    $members = array_values(array_unique($members));
    natcasesort($members);
    $members = array_values($members);

    $fetchedAt = intval($decodedCache['fetched_at'] ?? 0);
    return ['members' => $members, 'fetched_at' => $fetchedAt];
};

$writeLivePatronCache = static function (string $cachePath, array $members): void {
    $payload = [
        'fetched_at' => time(),
        'members' => array_values($members),
    ];
    @file_put_contents($cachePath, json_encode($payload, JSON_PRETTY_PRINT), LOCK_EX);
};

$patronMembers = $staticPatronMembers;
$patronSyncNote = '';

$liveConfigPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'patron_live_config.json';
$liveConfigLocalPath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'patron_live_config.local.json';
$liveCachePath = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'patron_live_cache.json';
$liveConfig = [];
if (is_file($liveConfigPath)) {
    $rawLiveConfig = file_get_contents($liveConfigPath);
    if ($rawLiveConfig !== false) {
        $decodedLiveConfig = json_decode($rawLiveConfig, true);
        if (is_array($decodedLiveConfig)) {
            $liveConfig = $decodedLiveConfig;
        }
    }
}
if (is_file($liveConfigLocalPath)) {
    $rawLiveConfigLocal = file_get_contents($liveConfigLocalPath);
    if ($rawLiveConfigLocal !== false) {
        $decodedLiveConfigLocal = json_decode($rawLiveConfigLocal, true);
        if (is_array($decodedLiveConfigLocal)) {
            $liveConfig = array_merge($liveConfig, $decodedLiveConfigLocal);
        }
    }
}

$liveEnabled = boolval($liveConfig['enabled'] ?? false);
$liveProvider = strtolower(trim((string)($liveConfig['provider'] ?? 'patreon_members')));
$liveCacheTtlSeconds = intval($liveConfig['cache_ttl_seconds'] ?? 300);
$liveCacheTtlSeconds = max(60, min(3600, $liveCacheTtlSeconds));

$patreonAccessTokenEnv = trim((string)($liveConfig['patreon_access_token_env'] ?? 'DWEMER_PATREON_ACCESS_TOKEN'));
$patreonAccessToken = '';
if ($patreonAccessTokenEnv !== '') {
    $tokenFromEnv = getenv($patreonAccessTokenEnv);
    if ($tokenFromEnv === false && isset($_SERVER[$patreonAccessTokenEnv])) {
        $tokenFromEnv = (string)$_SERVER[$patreonAccessTokenEnv];
    }
    if (is_string($tokenFromEnv)) {
        $patreonAccessToken = trim($tokenFromEnv);
    }
}
if ($patreonAccessToken === '') {
    $patreonAccessToken = trim((string)($liveConfig['patreon_access_token'] ?? ''));
}
$patreonCampaignId = trim((string)($liveConfig['patreon_campaign_id'] ?? ''));
$patreonIncludeDeclined = boolval($liveConfig['patreon_include_declined'] ?? false);
$patreonIncludeFormer = boolval($liveConfig['patreon_include_former'] ?? false);

if ($liveEnabled && $liveProvider === 'patreon_members') {
    $cachedLiveData = $readLivePatronCache($liveCachePath);
    $hasFreshCache = (
        is_array($cachedLiveData) &&
        isset($cachedLiveData['fetched_at']) &&
        isset($cachedLiveData['members']) &&
        (time() - intval($cachedLiveData['fetched_at'])) <= $liveCacheTtlSeconds
    );

    if ($hasFreshCache) {
        $patronMembers = array_values($cachedLiveData['members']);
    } else {
        try {
            if ($patreonAccessToken === '') {
                throw new RuntimeException('Missing Patreon access token (set env var from patreon_access_token_env or local override file)');
            }

            $patreonApiBase = 'https://www.patreon.com/api/oauth2/v2';
            $apiHeaders = [
                'Authorization: Bearer ' . $patreonAccessToken,
                'User-Agent: DwemerDashboard Patreon Sync',
            ];

            if ($patreonCampaignId === '') {
                $campaignsUrl = $patreonApiBase . '/campaigns?' . http_build_query([
                    'fields[campaign]' => 'creation_name',
                    'page[count]' => 10,
                ], '', '&', PHP_QUERY_RFC3986);

                $campaignsResponse = $httpGetJson($campaignsUrl, $apiHeaders, 12);
                if (intval($campaignsResponse['statusCode']) >= 400) {
                    $errorMessage = $extractApiError($campaignsResponse['payload']);
                    throw new RuntimeException('Patreon campaigns lookup failed: ' . $errorMessage);
                }

                $campaigns = $campaignsResponse['payload']['data'] ?? [];
                if (!is_array($campaigns) || !isset($campaigns[0]['id'])) {
                    throw new RuntimeException('Could not resolve campaign id; set patreon_campaign_id explicitly');
                }
                $patreonCampaignId = trim((string)$campaigns[0]['id']);
                if ($patreonCampaignId === '') {
                    throw new RuntimeException('Resolved empty Patreon campaign id');
                }
            }

            $liveMembers = [];
            $cursor = '';
            for ($page = 0; $page < 200; $page++) {
                $queryParams = [
                    'include' => 'currently_entitled_tiers,user',
                    'fields[member]' => 'full_name,patron_status,last_charge_status',
                    'fields[user]' => 'full_name,vanity',
                    'fields[tier]' => 'title',
                    'page[count]' => 1000,
                ];
                if ($cursor !== '') {
                    $queryParams['page[cursor]'] = $cursor;
                }

                $membersUrl = $patreonApiBase . '/campaigns/' . rawurlencode($patreonCampaignId) . '/members?' .
                    http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

                $membersResponse = $httpGetJson($membersUrl, $apiHeaders, 15);
                if (intval($membersResponse['statusCode']) >= 400) {
                    $errorMessage = $extractApiError($membersResponse['payload']);
                    throw new RuntimeException('Patreon members lookup failed: ' . $errorMessage);
                }

                $payload = $membersResponse['payload'];
                $membersData = $payload['data'] ?? [];
                if (!is_array($membersData)) {
                    throw new RuntimeException('Invalid Patreon members response payload');
                }

                $includedUsers = [];
                $includedTiers = [];
                $includedRows = $payload['included'] ?? [];
                if (is_array($includedRows)) {
                    foreach ($includedRows as $includedRow) {
                        if (!is_array($includedRow)) {
                            continue;
                        }
                        $includedType = trim((string)($includedRow['type'] ?? ''));
                        if ($includedType === 'user') {
                            $includedUserId = trim((string)($includedRow['id'] ?? ''));
                            if ($includedUserId === '') {
                                continue;
                            }
                            $includedUserName = trim((string)($includedRow['attributes']['full_name'] ?? ''));
                            if ($includedUserName === '') {
                                $includedUserName = trim((string)($includedRow['attributes']['vanity'] ?? ''));
                            }
                            if ($includedUserName !== '') {
                                $includedUsers[$includedUserId] = $includedUserName;
                            }
                            continue;
                        }
                        if ($includedType === 'tier') {
                            $includedTierId = trim((string)($includedRow['id'] ?? ''));
                            if ($includedTierId === '') {
                                continue;
                            }
                            $tierTitle = $normalizeTierTitle((string)($includedRow['attributes']['title'] ?? ''));
                            if ($tierTitle !== '') {
                                $includedTiers[$includedTierId] = $tierTitle;
                            }
                        }
                    }
                }

                foreach ($membersData as $memberRow) {
                    if (!is_array($memberRow)) {
                        continue;
                    }

                    $attributes = $memberRow['attributes'] ?? [];
                    if (!is_array($attributes)) {
                        continue;
                    }
                    $patronStatus = strtolower(trim((string)($attributes['patron_status'] ?? '')));

                    $includeMember = ($patronStatus === 'active_patron');
                    if (!$includeMember && $patreonIncludeDeclined && $patronStatus === 'declined_patron') {
                        $includeMember = true;
                    }
                    if (!$includeMember && $patreonIncludeFormer && $patronStatus === 'former_patron') {
                        $includeMember = true;
                    }
                    if (!$includeMember) {
                        continue;
                    }

                    $name = trim((string)($attributes['full_name'] ?? ''));
                    if ($name === '') {
                        $userId = trim((string)($memberRow['relationships']['user']['data']['id'] ?? ''));
                        if ($userId !== '' && isset($includedUsers[$userId])) {
                            $name = $includedUsers[$userId];
                        }
                    }
                    $normalizedName = $normalizePatronName($name);
                    if ($normalizedName !== '') {
                        $tierTitles = [];
                        $memberTierRows = $memberRow['relationships']['currently_entitled_tiers']['data'] ?? [];
                        if (is_array($memberTierRows)) {
                            foreach ($memberTierRows as $memberTierRow) {
                                if (!is_array($memberTierRow)) {
                                    continue;
                                }
                                $tierId = trim((string)($memberTierRow['id'] ?? ''));
                                if ($tierId !== '' && isset($includedTiers[$tierId])) {
                                    $tierTitles[] = $includedTiers[$tierId];
                                }
                            }
                        }
                        $tierTitles = array_values(array_unique(array_filter($tierTitles, static function ($tierTitle): bool {
                            return is_string($tierTitle) && trim($tierTitle) !== '';
                        })));
                        natcasesort($tierTitles);
                        $tierTitles = array_values($tierTitles);

                        if ($tierTitles !== []) {
                            $liveMembers[] = $normalizedName . ' - ' . implode(', ', $tierTitles);
                        } else {
                            $liveMembers[] = $normalizedName;
                        }
                    }
                }

                $nextCursor = '';
                if (
                    isset($payload['meta']) &&
                    is_array($payload['meta']) &&
                    isset($payload['meta']['pagination']) &&
                    is_array($payload['meta']['pagination']) &&
                    isset($payload['meta']['pagination']['cursors']) &&
                    is_array($payload['meta']['pagination']['cursors']) &&
                    isset($payload['meta']['pagination']['cursors']['next']) &&
                    is_string($payload['meta']['pagination']['cursors']['next'])
                ) {
                    $nextCursor = trim($payload['meta']['pagination']['cursors']['next']);
                }
                if ($nextCursor === '' && isset($payload['links']['next']) && is_string($payload['links']['next'])) {
                    $nextCursor = $extractCursorFromUrl($payload['links']['next']);
                }

                if ($nextCursor === '') {
                    break;
                }
                $cursor = $nextCursor;
            }

            $liveMembers = array_values(array_unique($liveMembers));
            natcasesort($liveMembers);
            $liveMembers = array_values($liveMembers);

            $patronMembers = $liveMembers;
            $writeLivePatronCache($liveCachePath, $patronMembers);
        } catch (Throwable $e) {
            error_log('[DwemerDashboard] Live patron sync failed: ' . $e->getMessage());
            if (is_array($cachedLiveData) && isset($cachedLiveData['members']) && is_array($cachedLiveData['members'])) {
                $patronMembers = array_values($cachedLiveData['members']);
            } else {
                $patronMembers = $staticPatronMembers;
            }
            $syncErrorSummary = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? $e->getMessage());
            if ($syncErrorSummary === '') {
                $syncErrorSummary = 'unknown error';
            }
            if (strlen($syncErrorSummary) > 220) {
                $syncErrorSummary = substr($syncErrorSummary, 0, 220) . '...';
            }
            $patronSyncNote = 'Live sync issue: ' . $syncErrorSummary;
        }
    }
} elseif ($liveEnabled && $liveProvider !== 'patreon_members') {
    $patronSyncNote = 'Unsupported live provider in patron_live_config.json';
}

$patronTierOrder = ['Gold', 'Silver', 'Bronze'];
$patronTierDisplayLabels = ['Gold' => 'HURASUM', 'Silver' => 'SARPU', 'Bronze' => 'SIPARRU'];
$patronTierIconPaths = ['Gold' => 'images/centurioncore.png', 'Silver' => 'images/dwemergyro.png', 'Bronze' => 'images/dwemerpuzzlebox.png'];
$patronTierRank = ['Bronze' => 1, 'Silver' => 2, 'Gold' => 3];
$patronTierMembers = ['Gold' => [], 'Silver' => [], 'Bronze' => []];
$patronBestTierByName = [];
foreach ($patronMembers as $rawPatronMember) {
    $parsedMember = $extractPatronNameAndTier($rawPatronMember);
    $parsedName = $parsedMember['name'];
    if ($parsedName === '') {
        continue;
    }

    $classifiedTier = $classifyPatronTier($parsedMember['tier']);
    if ($classifiedTier === '') {
        $classifiedTier = 'Bronze';
    }

    $existingTier = $patronBestTierByName[$parsedName] ?? null;
    if (!is_string($existingTier) || $patronTierRank[$classifiedTier] > ($patronTierRank[$existingTier] ?? 0)) {
        $patronBestTierByName[$parsedName] = $classifiedTier;
    }
}
foreach ($patronBestTierByName as $memberName => $memberTier) {
    if (!isset($patronTierMembers[$memberTier])) {
        continue;
    }
    $patronTierMembers[$memberTier][] = $memberName;
}
foreach ($patronTierOrder as $tierLabel) {
    $tierMembers = array_values(array_unique($patronTierMembers[$tierLabel]));
    natcasesort($tierMembers);
    $patronTierMembers[$tierLabel] = array_values($tierMembers);
}

$patronActiveCount = 0;
foreach ($patronTierOrder as $tierLabel) {
    $patronActiveCount += count($patronTierMembers[$tierLabel]);
}
$patronScrollDurationSeconds = max(100, min(350, intval(round(($patronActiveCount * 5) / 1.2))));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($TITLE, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/main.css">
    <link rel="stylesheet" href="css/mod_card_effects.css?v=<?= (int) filemtime(__DIR__ . '/css/mod_card_effects.css') ?>">
    <script src="js/home_storage.js?v=<?= (int) filemtime(__DIR__ . '/js/home_storage.js') ?>" defer></script>
    <script src="js/mod_card_effects.js?v=<?= (int) filemtime(__DIR__ . '/js/mod_card_effects.js') ?>" defer></script>
    <script src="js/home_api_keys.js?v=<?= (int) filemtime(__DIR__ . '/js/home_api_keys.js') ?>" defer></script>
    <script src="js/custom_mod_cards.js?v=<?= (int) filemtime(__DIR__ . '/js/custom_mod_cards.js') ?>" defer></script>
    <style>
        @font-face {
            font-family: 'MagicCards';
            src: url('css/font/MagicCardsNormal.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        @font-face {
            font-family: 'Pelagiad';
            src: url('css/font/Pelagiad.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'Rye';
            src: url('css/font/Rye-Regular.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: 'Share Tech Mono';
            src: url('css/font/ShareTechMono-Regular.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        body {
            margin: 0;
            min-height: 100vh;
            <?php if ($hasCustomBackground): ?>
            background:
                linear-gradient(180deg, rgba(24, 28, 35, 0.55) 0%, rgba(15, 17, 22, 0.75) 100%),
                url('images/background.jpg') center center / cover no-repeat fixed;
            <?php else: ?>
            background: linear-gradient(180deg, #23272f 0%, #161a20 100%);
            <?php endif; ?>
        }

        /* Page frame: dashboard, then Global API Keys and Patreon bands; from 1680px they become rails matching the dashboard height. */
        .dashboard-layout {
            --dashboard-gutter: 24px;
            width: min(1200px, calc(100% - 2 * var(--dashboard-gutter)));
            box-sizing: border-box;
            margin: 28px auto 48px;
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            grid-template-areas: "main" "keys" "patron";
            gap: 20px;
            /* Card glows may bleed into the gutter but never widen the page. */
            overflow-x: clip;
            overflow-clip-margin: var(--dashboard-gutter);
        }

        .dashboard-shell {
            min-width: 0;
            box-sizing: border-box;
            background: rgba(24, 28, 35, 0.95);
            border: 1px solid rgba(138, 155, 182, 0.25);
            border-radius: 14px;
            padding: 32px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.4);
            text-align: left;
        }

        .home-panel {
            border: 1px solid rgba(138, 155, 182, 0.2);
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(34, 40, 52, 0.72), rgba(24, 28, 37, 0.72));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
        }

        .dashboard-shell { grid-area: main; }
        .keys-shell { grid-area: keys; }
        .patron-shell { grid-area: patron; }

        .keys-shell {
            min-width: 0;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            padding: 16px 22px 18px;
            background: rgba(20, 23, 30, 0.9);
            color: #f2f5f9;
        }

        .keys-note { margin: 4px 0 0; color: #c7d0dd; font-size: 13px; line-height: 1.4; }
        .keys-note.error { color: #f4c47a; }
        .keys-list-scroll {
            max-height: 300px;
            margin-top: 10px;
            min-height: 0;
            /* Keep buttons and focus outlines clear of classic and overlay scrollbars. */
            box-sizing: border-box;
            margin-left: -4px;
            padding: 0 14px 0 4px;
            overflow-y: auto;
            scrollbar-gutter: stable;
            scrollbar-width: thin;
            scrollbar-color: #4a5466 transparent;
        }
        .keys-list-scroll[hidden] { display: none; }
        .keys-group { margin: 0 0 14px; min-width: 0; }
        .keys-group-title {
            margin: 0 0 6px;
            padding-bottom: 5px;
            border-bottom: 1px solid rgba(138, 155, 182, 0.45);
            color: #d8e3f2;
            font: normal 18px/1.1 'MagicCards', serif;
            letter-spacing: 1px;
            overflow-wrap: anywhere;
        }
        .keys-items { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
        .keys-row { min-width: 0; }
        .keys-row-head { display: flex; align-items: center; gap: 10px; }
        .keys-row-text { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .keys-name { color: #e7ecf2; font-size: 13.5px; line-height: 1.35; overflow-wrap: anywhere; }
        .keys-preview { align-self: flex-start; max-width: 100%; margin: 0; padding: 0; border: 0; background: none; color: #9cadc3; font: 12px/1.3 ui-monospace, Consolas, monospace; overflow-wrap: anywhere; }
        .keys-button {
            flex: none;
            min-height: 28px;
            padding: 3px 10px;
            border: 1px solid rgba(138, 155, 182, 0.45);
            border-radius: 6px;
            background: rgba(44, 51, 64, 0.9);
            color: #e7ecf2;
            font-size: 12.5px;
            cursor: pointer;
        }
        .keys-button:hover:not(:disabled) { border-color: #d9823d; }
        .keys-button:focus-visible { outline: 2px solid #d9823d; outline-offset: 2px; }
        .keys-input:focus-visible { outline: 2px solid #f0cf75; outline-offset: 2px; }
        .keys-button:disabled { opacity: 0.55; cursor: default; }
        .keys-button.primary { background: #3a4a63; }
        .keys-button[hidden], .keys-field[hidden] { display: none; }
        .keys-list-scroll:focus { outline: none; }
        .keys-category-title {
            margin: 4px 0 8px;
            color: #f3c9a2;
            font-size: 11px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
        }
        .keys-category-title[hidden] { display: none; }
        .keys-category:has(.keys-group) + .keys-category .keys-category-title:not([hidden]) { padding-top: 4px; border-top: 1px solid rgba(217, 130, 61, 0.35); }
        .keys-form { display: flex; flex-direction: column; gap: 10px; }
        .keys-field { display: flex; flex-direction: column; gap: 4px; color: #c7d0dd; font-size: 12.5px; }
        .keys-form .keys-input {
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
            padding: 7px 9px;
            border: 1px solid rgba(138, 155, 182, 0.4);
            border-radius: 6px;
            background: #12151b;
            color: #f2f5f9;
            font-size: 14px;
        }
        .keys-form .keys-input:focus-visible { outline-color: #d9823d; }
        .keys-form .keys-input:read-only { opacity: 0.7; }
        .keys-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .keys-state { margin: 3px 0 0; font-size: 12px; line-height: 1.35; color: #c7d0dd; }
        .keys-state:empty { display: none; }
        .keys-state.ok { color: #a9dca0; }
        .keys-state.error { color: #f4c47a; }
        .keys-unset { color: #8f9bad; font-size: 13px; font-style: italic; }
        .keys-button.keys-add { border-color: rgba(217, 130, 61, 0.75); color: #f3c9a2; }
        .keys-button.keys-add.primary { background: rgba(122, 66, 30, 0.85); color: #fbe7d4; }
        .keys-button.keys-add:hover:not(:disabled) { border-color: #f0a35e; }
        /* Shared Edit / Add key dialog: solid panel, internal scroll on short viewports. */
        .keys-dialog {
            box-sizing: border-box;
            width: min(480px, calc(100vw - 24px));
            max-width: none;
            max-height: calc(100dvh - 24px);
            margin: auto;
            padding: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
            border: 1px solid rgba(217, 130, 61, 0.6);
            border-radius: 12px;
            background: #161a22;
            color: #f2f5f9;
            box-shadow: 0 18px 48px rgba(0, 0, 0, 0.6), inset 0 1px 0 rgba(255, 255, 255, 0.05);
            scrollbar-width: thin;
            scrollbar-color: #4a5466 transparent;
        }
        .keys-dialog::backdrop { background: rgba(6, 8, 12, 0.72); }
        .keys-dialog-body { padding: 20px 22px 22px; }
        .keys-dialog-title {
            margin: 0;
            color: #ffffff;
            font: normal 24px/1.2 'MagicCards', serif;
            word-spacing: 6px;
            letter-spacing: 0.4px;
            text-shadow: -1px -1px 0 #000, 1px -1px 0 #000, -1px 1px 0 #000, 1px 1px 0 #000;
        }
        .keys-dialog-provider {
            margin: -4px 0 4px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(217, 130, 61, 0.45);
            color: #f3c9a2;
            font-size: 13px;
            overflow-wrap: anywhere;
        }
        .keys-dialog .keys-state { margin: 0; font-size: 13px; }
        .keys-dialog .keys-actions { margin-top: 4px; }
        .keys-dialog .keys-button { min-height: 34px; padding: 5px 16px; font-size: 13.5px; }
        @media (max-width: 640px) {
            .keys-dialog { width: calc(100vw - 16px); max-height: calc(100dvh - 16px); }
            .keys-dialog-body { padding: 16px 14px 18px; }
            .keys-dialog .keys-actions .keys-button { flex: 1 1 auto; }
        }
        .keys-help { color: #9cadc3; font-size: 11.5px; line-height: 1.35; }

        .patron-shell {
            min-width: 0;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            padding: 16px 22px 18px;
            background: rgba(20, 23, 30, 0.9);
            color: #f2f5f9;
        }

        .patron-head {
            display: flex;
            flex-wrap: wrap;
            align-items: baseline;
            justify-content: space-between;
            gap: 6px 16px;
        }

        .patron-title {
            margin: 0;
            font-size: 24px;
            color: #ffffff;
            line-height: 1.2;
            font-family: 'MagicCards', serif;
            font-weight: normal;
            letter-spacing: 0.4px;
            word-spacing: 6px;
            text-shadow:
                -1px -1px 0 #000,
                 1px -1px 0 #000,
                -1px  1px 0 #000,
                 1px  1px 0 #000;
        }

        .patron-sync-note {
            margin: 0;
            color: #ef6b6b;
            font-size: 12px;
            line-height: 1.4;
        }

        .patron-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .patron-list-scroll {
            height: 260px;
            margin-top: 10px;
            overflow: hidden;
            min-height: 0;
            /* Soften where the looping list enters and leaves. */
            -webkit-mask-image: linear-gradient(transparent, #000 14px, #000 calc(100% - 14px), transparent);
            mask-image: linear-gradient(transparent, #000 14px, #000 calc(100% - 14px), transparent);
        }

        .patron-list-track {
            display: flex;
            flex-direction: column;
            animation: patron-list-scroll-loop <?= intval($patronScrollDurationSeconds) ?>s linear infinite;
            will-change: transform;
        }

        /* Band: each loop cycle shows the three tiers side by side. */
        .patron-list-cycle {
            flex: 0 0 auto;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            column-gap: 28px;
            align-items: start;
        }

        @keyframes patron-list-scroll-loop {
            0% {
                transform: translateY(0);
            }
            100% {
                transform: translateY(-50%);
            }
        }

        .patron-tier-group {
            margin: 0;
            padding: 0 0 16px;
            min-width: 0;
        }

        .patron-tier-title {
            margin: 0 0 7px;
            padding-bottom: 5px;
            border-bottom: 1px solid rgba(138, 155, 182, 0.45);
            color: #d8e3f2;
            font-size: 18px;
            letter-spacing: 1px;
            text-transform: none;
            font-weight: normal;
            font-family: 'MagicCards', serif;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .patron-tier-icon {
            width: 38px;
            height: 38px;
            object-fit: contain;
            display: block;
            flex: 0 0 38px;
        }

        .patron-tier-title-label {
            line-height: 1;
            font-family: 'MagicCards', serif;
            font-weight: normal;
            letter-spacing: inherit;
        }

        .patron-tier-gold .patron-tier-title {
            color: #f0cf75;
            border-bottom-color: rgba(221, 184, 86, 0.7);
        }

        .patron-tier-silver .patron-tier-title {
            color: #d2d8e0;
            border-bottom-color: rgba(170, 180, 193, 0.72);
        }

        .patron-tier-bronze .patron-tier-title {
            color: #d9a47c;
            border-bottom-color: rgba(191, 130, 93, 0.72);
        }

        .patron-list-item {
            color: #e7ecf2;
            font-size: 13.5px;
            padding: 0;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .patron-title-link {
            color: inherit;
            text-decoration: none;
            font-family: 'MagicCards', serif;
            font-size: inherit;
            font-weight: inherit;
            letter-spacing: inherit;
            word-spacing: inherit;
            line-height: inherit;
            text-transform: inherit;
            display: inline-block;
        }

        .patron-title-link:hover,
        .patron-title-link:focus-visible {
            color: #f0cf75;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .patron-title-link:focus-visible { outline: 2px solid #f0cf75; outline-offset: 3px; }

        .patron-empty {
            margin: 10px 0 0;
            color: #c7d0dd;
            font-size: 14px;
            line-height: 1.5;
        }

        .patron-tier-empty {
            margin: 0;
            color: #9cadc3;
            font-size: 13px;
            line-height: 1.4;
        }

        /* Two bands side by side below the dashboard while each keeps a useful card width. */
        @media (min-width: 960px) {
            .dashboard-layout { grid-template-columns: repeat(2, minmax(0, 1fr)); grid-template-areas: "main main" "keys patron"; }
            .patron-list-cycle { column-gap: 18px; }
        }

        @media (min-width: 1680px) {
            .dashboard-layout {
                --dashboard-gutter: 32px;
                width: min(1840px, calc(100% - 2 * var(--dashboard-gutter)));
                grid-template-columns: 280px minmax(1008px, 1fr) 280px;
                grid-template-areas: "keys main patron";
                justify-content: center;
                align-items: stretch;
                gap: 24px;
            }

            .dashboard-shell { padding: 28px; }

            /* Rails: stretched to the dashboard's top and bottom; their lists fill the remaining height. */
            .patron-shell, .keys-shell { padding: 18px 18px 20px; }

            .patron-head { flex-direction: column; align-items: flex-start; gap: 4px; }
            /* Size containment keeps the lists from driving the row height. */
            .patron-list-scroll, .keys-list-scroll { flex: 1 1 0; height: auto; max-height: none; contain: size; }
            .patron-list-cycle { grid-template-columns: minmax(0, 1fr); }
        }

        @media (max-width: 640px) {
            .dashboard-layout { --dashboard-gutter: 10px; margin: 12px auto 32px; gap: 14px; }
            .dashboard-shell { padding: 20px 14px; border-radius: 12px; }
            .patron-shell, .keys-shell { padding: 14px 14px 16px; }
            .patron-list-scroll { height: 300px; }
            .patron-list-cycle { grid-template-columns: minmax(0, 1fr); }
            .patron-tier-icon { width: 32px; height: 32px; flex-basis: 32px; }
        }

        /* Without motion every member stays reachable by scrolling; the duplicate loop copy is dropped. */
        @media (prefers-reduced-motion: reduce) {
            .patron-list-scroll { overflow-y: auto; scrollbar-width: thin; scrollbar-color: #4a5466 transparent; }
            .patron-list-scroll { -webkit-mask-image: none; mask-image: none; }
            .patron-list-track { animation: none; }
            .patron-list-cycle[aria-hidden="true"] { display: none; }
        }

        .dashboard-title {
            margin-bottom: 10px;
            font-size: 42px;
            font-family: 'MagicCards', serif;
            color: #ffffff;
            letter-spacing: 0.6px;
            word-spacing: 8px;
            line-height: 1.05;
            text-shadow:
                -1px -1px 0 #000,
                 1px -1px 0 #000,
                -1px  1px 0 #000,
                 1px  1px 0 #000;
        }

        .dashboard-header {
            position: relative;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 14px 24px;
            padding-bottom: 20px;
            text-align: left;
        }

        /* Brass hairline that fades into Ploofy's cyan line toward its panel. */
        .dashboard-header::after {
            content: "";
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            height: 1px;
            background: linear-gradient(90deg, rgba(230, 183, 108, 0.6), rgba(138, 155, 182, 0.18) 55%, rgba(0, 229, 255, 0.22));
        }

        .dashboard-header .dashboard-title { margin: 0; min-width: 0; }

        /* Stacks the "Free Alpha!" label centred above the Ploofy panel without changing the header row. */
        .dashboard-ploofy-wrap {
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }

        .dashboard-ploofy-tag {
            color: #fff;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.2;
            text-align: center;
        }

        /* Ploofy.ai panel: tokens copied from ploofy.ai (.hero-logo frame, --cyan palette); the whole panel is the link. */
        .dashboard-ploofy {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            min-height: 44px;
            padding: 6px;
            border-radius: 14px;
            background: #0f0e0e;
            box-shadow: 0 0 0 1px rgba(0, 229, 255, 0.25), 0 0 32px rgba(0, 229, 255, 0.12), 0 12px 28px rgba(0, 0, 0, 0.45);
            color: #f0f3f5;
            text-decoration: none;
            transition: box-shadow 0.15s;
        }

        .dashboard-ploofy:hover,
        .dashboard-ploofy:focus-visible {
            box-shadow: 0 0 0 1px rgba(0, 229, 255, 0.5), 0 0 40px rgba(0, 229, 255, 0.22), 0 12px 28px rgba(0, 0, 0, 0.45);
        }

        .dashboard-ploofy:focus-visible { outline: 2px solid #00e5ff; outline-offset: 3px; }

        .dashboard-ploofy-logo {
            display: block;
            flex: none;
            width: auto;
            height: 50px;
            /* The logo's near-black ground merges with the panel and its glow. */
            mix-blend-mode: lighten;
        }

        @media (max-width: 640px) {
            .dashboard-header { gap: 14px; padding-bottom: 16px; }
            .dashboard-header .dashboard-title { font-size: 34px; word-spacing: 7px; }
            .dashboard-ploofy-wrap,
            .dashboard-ploofy { width: 100%; }
            .dashboard-ploofy-logo { height: 44px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-ploofy { transition: none; }
        }

        .dashboard-subtitle {
            color: #aeb8c5;
            margin-bottom: 28px;
        }

        .dashboard-mods {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 14px;
            margin-top: 22px;
        }

        .dashboard-mods-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-top: 22px; }
        .dashboard-mods-tools { display: flex; flex-wrap: wrap; gap: 8px; }
        .dashboard-mods-heading .dashboard-mods-title { margin: 0; text-align: left; }
        .dashboard-mods-heading + .dashboard-mods { margin-top: 10px; }
        .mod-card-effects-toggle {
            padding: 6px 10px; border: 1px solid #68717d; border-radius: 999px;
            color: #e2e8f0; background: #141a24; font: inherit; font-size: 12px; cursor: pointer;
        }
        .mod-card-effects-toggle[hidden] { display: none; }
        .mod-card-effects-toggle:disabled { cursor: default; opacity: .7; }
        .mod-card-effects-toggle:focus-visible { outline: 2px solid #ffad61; outline-offset: 3px; }

        .mod-card {
            grid-column: span 2;
            position: relative;
            display: flex;
            align-items: flex-end;
            min-height: 180px;
            overflow: hidden;
            border: 1px solid var(--mod-card-accent, #68717d);
            border-radius: 10px;
            background: #080807;
            flex-direction: column;
            color: #fff;
            text-align: left;
            text-decoration: none;
        }

        .mod-card-art {
            position: relative;
            width: 100%;
            height: auto;
            aspect-ratio: 16 / 9;
            object-fit: contain;
        }
        .mod-card-open {
            display: flex; flex-direction: column; flex: 1; width: 100%;
            color: inherit; text-decoration: none;
        }
        .mod-card-open:hover { color: inherit; text-decoration: none; }

        .mod-card-label {
            margin-top: auto;
            position: relative;
            width: 100%;
            min-height: 60px;
            box-sizing: border-box;
            padding: 10px 14px;
            background: rgba(0, 0, 0, 0.78);
        }

        .mod-card-chim { --mod-card-accent: #f27c11; }
        .mod-card-stobe { --mod-card-accent: #e6b76c; }
        .mod-card-dialectic { --mod-card-accent: #ffb641; }
        .mod-card-lorkhan { --mod-card-accent: #bc9d5a; }
        .mod-card-reign { --mod-card-accent: #c9a227; }
        .mod-card-reign .mod-card-name { color: #f5f6f8; font-family: "Times New Roman", Times, serif; font-weight: normal; }
        .mod-card-chim .mod-card-name { font-family: 'Pelagiad', serif; font-weight: normal; }
        .mod-card-stobe .mod-card-name { font-family: 'Rye', serif; font-weight: normal; }
        .mod-card-dialectic .mod-card-name { font-family: 'Share Tech Mono', monospace; font-weight: normal; }

        .mod-card-name,
        .mod-card-game,
        .mod-card-version,
        .mod-card-status {
            display: block;
        }

        .mod-card-name { font-size: 23px; line-height: 1.2; }
        .mod-card-game { margin-top: 0; font-size: 13.5px; font-weight: 700; color: #ececec; }
        .mod-card-version { margin-top: 1px; font-size: 12px; line-height: 1.3; color: #b4b4b4; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
        .mod-card-status { margin-top: 4px; font-size: 13px; color: #e0e0e0; }

        .mod-card:has(.mod-card-open[href]):hover {
            border-color: var(--mod-card-accent, #68717d);
            box-shadow: 0 0 0 1px var(--mod-card-accent, #68717d);
            color: #fff;
            text-decoration: none;
        }

        .mod-card:focus-within {
            outline: 3px solid var(--mod-card-accent, #68717d);
            outline-offset: 4px;
        }

        .mod-card-open[aria-disabled="true"] { cursor: not-allowed; }
        .mod-card-open[aria-disabled="true"] .mod-card-art { filter: grayscale(1) brightness(0.45); }

        @media (min-width: 1001px) {
            .mod-card:nth-child(4):nth-last-child(2) { grid-column: 2 / span 2; }
        }

        @media (max-width: 1000px) {
            .dashboard-mods { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .mod-card { grid-column: auto; }
        }

        @media (max-width: 640px) {
            .dashboard-mods { grid-template-columns: minmax(0, 1fr); }
            .dashboard-mods-heading { margin-top: 18px; }
            /* Compact label strips keep the 16:9 artwork dominant on phones. */
            .mod-card-label { min-height: 48px; padding: 7px 12px; display: flex; flex-direction: column; justify-content: center; }
        }

        .chim-brand-icon {
            width: 36px;
            height: 36px;
            min-width: 36px;
            min-height: 36px;
            object-fit: contain;
            display: block;
            border-radius: 4px;
        }

        .kagrenac-brand-icon {
            width: 36px;
            height: 36px;
            min-width: 36px;
            min-height: 36px;
            object-fit: cover;
            display: block;
            border-radius: 50%;
            border: 1px solid rgba(242, 124, 17, 0.75);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.28);
        }

        .home-storage-title {
            margin: 0;
            font-size: 11px;
            font-weight: 700;
            color: #e6b76c;
            letter-spacing: 1.2px;
            text-transform: uppercase;
        }

        /* Tools and storage: two adjacent panels of matching weight; they stack below 860px. */
        .home-split {
            display: grid;
            grid-template-columns: minmax(0, 5fr) minmax(0, 7fr);
            gap: 14px;
            align-items: stretch;
            margin-top: 16px;
        }

        .home-split > .home-panel {
            box-sizing: border-box;
            min-width: 0;
            display: flex;
            flex-direction: column;
            padding: 14px 16px;
            text-align: left;
            color: #c7d0dd;
            font-size: 14px;
        }

        .home-split-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 6px 16px;
            min-height: 34px;
            margin-bottom: 6px;
        }

        .home-tool-list { list-style: none; margin: 0; padding: 0; display: grid; }
        .home-tool-list > li + li { border-top: 1px solid rgba(138, 155, 182, 0.12); }

        .home-tool {
            display: grid;
            grid-template-columns: auto minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
            box-sizing: border-box;
            min-height: 56px;
            padding: 8px;
            border-radius: 8px;
            color: #ffffff;
            text-decoration: none;
            transition: background-color 0.15s ease;
        }

        .home-tool-name { font-size: 15.5px; font-weight: 700; letter-spacing: 0.5px; text-transform: uppercase; line-height: 1.2; overflow-wrap: anywhere; }
        .home-tool-chev { color: #9cadc3; font-size: 20px; line-height: 1; transition: color 0.15s ease; }
        .home-tool:hover { background: rgba(255, 173, 97, 0.1); color: #ffffff; text-decoration: none; }
        .home-tool:hover .home-tool-chev,
        .home-tool:focus-visible .home-tool-chev { color: #ffad61; }
        .home-tool:focus-visible { outline: 2px solid #ffad61; outline-offset: 2px; }

        .home-storage { display: flex; flex-direction: column; min-width: 0; }

        .home-storage-total {
            font-size: 24px;
            line-height: 1.15;
            font-weight: 700;
            color: #ffffff;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .home-storage-summary { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 12px; margin-bottom: 10px; }
        .home-storage[aria-busy="true"] .home-storage-total,
        .home-storage[aria-busy="true"] .home-storage-mod-size { color: #8391a6; }

        .home-storage-note {
            color: #9cadc3;
            font-size: 12px;
        }

        .home-storage-note:empty { display: none; }
        .home-storage > noscript { display: block; margin-top: 8px; }

        .home-storage-mods { list-style: none; margin: 0 0 12px; padding: 0; display: grid; gap: 10px; }

        .home-storage-mod {
            --storage-mod-accent: #8a9bb6;
            display: grid;
            grid-template-columns: 104px minmax(0, 1fr) minmax(84px, auto);
            align-items: center;
            gap: 12px;
        }

        .home-storage-mod-chim { --storage-mod-accent: #f27c11; }
        .home-storage-mod-dialectic { --storage-mod-accent: #ffb641; }
        .home-storage-mod-stobe { --storage-mod-accent: #e6b76c; }
        .home-storage-mod.is-absent { opacity: 0.55; }

        .home-storage-mod-name {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            font-size: 13px;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: 1px;
        }

        .home-storage-mod-dot {
            flex: none;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--storage-mod-accent);
        }

        .home-storage-mod.is-absent .home-storage-mod-dot { background: transparent; box-shadow: inset 0 0 0 1px #8a9bb6; }

        /* Bar width is each mod's share of the measured total, set by home_storage.js; empty while loading. */
        .home-storage-mod-meter { height: 6px; border-radius: 999px; background: rgba(138, 155, 182, 0.14); overflow: hidden; }
        .home-storage-mod-bar { display: block; width: 0; height: 100%; border-radius: inherit; background: var(--storage-mod-accent); transition: width 0.4s ease; }
        .home-storage-mod.is-absent .home-storage-mod-meter { background: transparent; box-shadow: inset 0 0 0 1px rgba(138, 155, 182, 0.22); }

        .home-storage-mod-size {
            font-size: 14px;
            font-weight: 600;
            color: #f2f5f9;
            font-variant-numeric: tabular-nums;
            text-align: right;
            white-space: nowrap;
        }

        .home-storage-mod.is-absent .home-storage-mod-size { font-size: 13px; font-weight: 400; color: #9cadc3; }
        .home-storage-mod.is-error .home-storage-mod-size { color: #ef6b6b; }

        .home-storage-manage {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border: 1px solid rgba(255, 173, 97, 0.35);
            border-radius: 8px;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            white-space: nowrap;
            transition: background-color 0.15s ease, border-color 0.15s ease;
        }

        .home-storage-manage:hover { color: #ffffff; border-color: #ffad61; background: rgba(255, 173, 97, 0.1); text-decoration: none; }
        .home-storage-manage:focus-visible { outline: 2px solid #ffad61; outline-offset: 2px; }

        @media (max-width: 860px) {
            .home-split { grid-template-columns: minmax(0, 1fr); }
        }

        @media (max-width: 720px) {
            .home-split > .home-panel { padding: 12px; }
        }

        @media (max-width: 420px) {
            .home-storage-mod { grid-template-columns: minmax(0, 1fr) auto; gap: 4px 10px; }
            .home-storage-mod-meter { grid-column: 1 / -1; grid-row: 2; }
            .home-storage-total { font-size: 22px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .home-tool, .home-tool-chev, .home-storage-manage, .home-storage-mod-bar { transition: none; }
        }

        .home-custom-mods {
            margin-top: 18px;
            padding: 12px 18px 14px;
            text-align: left;
        }

        .home-custom-mods-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 12px; }

        /* Section heading in the dashboard title face, overriding the small uppercase label style. */
        .dashboard-mods-title,
        #home-custom-mods-title {
            font-family: 'MagicCards', serif;
            font-size: 28px;
            font-weight: normal;
            line-height: 1.1;
            letter-spacing: 0.4px;
            word-spacing: 4px;
            text-transform: none;
            color: #fff;
        }

        @media (max-width: 640px) {
            .dashboard-mods-title,
            #home-custom-mods-title { font-size: 24px; }
        }

        /* KAGRENAC panel: mirrors .dashboard-ploofy's dark rounded frame with an amber glow; the whole panel is the link. */
        .home-kagrenac-link {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            box-sizing: border-box;
            min-height: 44px;
            max-width: 100%;
            padding: 8px 18px 8px 8px;
            border-radius: 14px;
            background: #0f0e0e;
            box-shadow: 0 0 0 1px rgba(255, 173, 97, 0.28), 0 0 28px rgba(255, 173, 97, 0.1), 0 10px 24px rgba(0, 0, 0, 0.4);
            color: #ffd2a6;
            text-decoration: none;
            transition: box-shadow 0.15s;
        }

        .home-kagrenac-link .kagrenac-brand-icon { width: 42px; height: 42px; min-width: 42px; min-height: 42px; }
        .home-kagrenac-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; line-height: 1.2; }
        .home-kagrenac-name { font-size: 15px; font-weight: 700; letter-spacing: 2px; }
        .home-kagrenac-tagline { color: #b9c3d0; font-size: 12px; }

        .home-kagrenac-link:hover,
        .home-kagrenac-link:focus-visible {
            box-shadow: 0 0 0 1px rgba(255, 173, 97, 0.55), 0 0 36px rgba(255, 173, 97, 0.2), 0 10px 24px rgba(0, 0, 0, 0.4);
            text-decoration: none;
        }

        .home-kagrenac-link:hover .home-kagrenac-name,
        .home-kagrenac-link:focus-visible .home-kagrenac-name { color: #ffffff; }
        .home-kagrenac-link:focus-visible { outline: 2px solid #ffad61; outline-offset: 3px; }

        @media (max-width: 640px) {
            .home-kagrenac-link { width: 100%; }
        }

        @media (prefers-reduced-motion: reduce) {
            .home-kagrenac-link { transition: none; }
        }

        .home-custom-mods .home-storage-note { display: block; margin: 6px 0 0; }

        /* Custom mod cards: the official card frame and 16:9 art area in their own 3/2/1 grid. Every card shares
           one dark orange accent; nothing here is derived from a mod's id, and official card effects never apply. */
        .custom-mod-cards {
            list-style: none;
            margin: 10px 0 0;
            padding: 0;
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .custom-mod-cards > li { display: flex; min-width: 0; }

        .custom-mod-card {
            --custom-mod-accent: #d2691e;
            display: flex;
            flex-direction: column;
            width: 100%;
            min-width: 0;
            overflow: hidden;
            border: 1px solid #68717d;
            border-radius: 10px;
            background: #080807;
            color: #fff;
            text-align: left;
            text-decoration: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .custom-mod-card:hover,
        .custom-mod-card:focus-visible {
            border-color: var(--custom-mod-accent);
            box-shadow: 0 0 0 1px var(--custom-mod-accent);
            color: #fff;
            text-decoration: none;
        }

        .custom-mod-card:focus-visible { outline: 3px solid var(--custom-mod-accent); outline-offset: 4px; }

        .custom-mod-card-art {
            position: relative;
            display: block;
            aspect-ratio: 16 / 9;
            overflow: hidden;
            container-type: inline-size;
            background: radial-gradient(ellipse at 50% 45%, #2b1e14 0%, #120e0b 55%, #080807 100%);
        }

        /* Banners are contained, never cropped, so text drawn into the artwork stays whole. */
        .custom-mod-card-banner {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
            background: #080807;
        }

        .custom-mod-card-icon,
        .custom-mod-card-monogram {
            position: absolute;
            top: 50%;
            left: 50%;
            box-sizing: border-box;
            width: 30cqi;
            height: 30cqi;
            transform: translate(-50%, -50%);
        }

        .custom-mod-card-icon { object-fit: contain; filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.6)); }

        .custom-mod-card-monogram {
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid rgba(210, 105, 30, 0.7);
            border-radius: 50%;
            background: rgba(8, 8, 7, 0.55);
            box-shadow: 0 0 24px rgba(210, 105, 30, 0.18);
            color: #f4d9bf;
            font: normal 15cqi/1 'MagicCards', serif;
        }

        /* Fallback order: banner, then icon emblem, then monogram. A failed image is removed by
           custom_mod_cards.js, which lets the next layer show; a hidden icon is never fetched. */
        .custom-mod-card-art:has(> .custom-mod-card-banner) .custom-mod-card-icon,
        .custom-mod-card-art:has(> img) .custom-mod-card-monogram { display: none; }

        .custom-mod-card-label {
            display: flex;
            flex: 1;
            flex-direction: column;
            gap: 6px;
            min-height: 64px;
            box-sizing: border-box;
            padding: 12px 16px 14px;
            background: rgba(0, 0, 0, 0.78);
        }

        .custom-mod-card-name {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            overflow: hidden;
            overflow-wrap: anywhere;
            color: #fff;
            font: normal 23px/1.35 'MagicCards', serif;
            /* MagicCards' own space is very narrow, so words get a wider gap to stay readable. */
            letter-spacing: 0.6px;
            word-spacing: 0.32em;
        }

        .custom-mod-card-version {
            font-size: 13px;
            font-weight: 700;
            line-height: 1.3;
            letter-spacing: 0.3px;
            color: #c9d1dc;
            font-variant-numeric: tabular-nums;
        }

        @media (max-width: 1000px) {
            .custom-mod-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }

        @media (max-width: 640px) {
            .custom-mod-cards { grid-template-columns: minmax(0, 1fr); }
            .custom-mod-card-label { min-height: 52px; padding: 10px 14px 12px; gap: 5px; }
        }

        /* Database checks share the storage panel: problems stay visible, completed checks collapse. */
        .dashboard-status {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px 20px;
            margin-top: auto;
            padding: 10px 0 0;
            border-top: 1px solid rgba(138, 155, 182, 0.14);
            font-size: 13.5px;
            line-height: 1.45;
            color: #c7d0dd;
        }

        .dashboard-status-attention {
            flex: 1 1 360px;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .dashboard-status-line {
            display: flex;
            gap: 9px;
            margin: 0;
            min-width: 0;
            font-size: inherit;
            line-height: inherit;
            overflow-wrap: anywhere;
        }

        .dashboard-status-attention .dashboard-status-line::before {
            content: '!';
            flex: none;
            width: 18px;
            height: 18px;
            margin-top: 1px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #9cadc3;
            color: #12151b;
            font-weight: 800;
            font-size: 12px;
            line-height: 1;
        }

        .dashboard-status-line.unavailable { color: #f4c47a; }
        .dashboard-status-line.unavailable::before { background: #f4c47a; }
        .dashboard-status-line.error { color: #ef6b6b; }
        .dashboard-status-attention .dashboard-status-line.error::before { background: #ef6b6b; }
        .dashboard-status-line.ok { color: #a9dca0; }

        .dashboard-status-ok { flex: 0 1 auto; margin-left: auto; color: #8ed081; }

        .dashboard-status-ok summary {
            cursor: pointer;
            list-style: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 2px 4px;
            border-radius: 6px;
        }

        .dashboard-status-ok summary::-webkit-details-marker { display: none; }
        .dashboard-status-ok summary::before { content: '\2713'; font-weight: 800; }
        .dashboard-status-ok summary::after { content: '\25BE'; color: #9cadc3; transition: transform 0.15s; }
        .dashboard-status-ok[open] summary::after { transform: rotate(180deg); }
        .dashboard-status-ok summary:hover { background: rgba(255, 173, 97, 0.08); }
        .dashboard-status-ok summary:focus-visible { outline: 2px solid #ffad61; outline-offset: 2px; }
        .dashboard-status-hint { color: #9cadc3; font-size: 12px; }

        .dashboard-status-ok ul {
            list-style: none;
            margin: 6px 0 0;
            padding: 0 0 0 22px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            font-size: 13px;
        }

        @media (max-width: 640px) {
            .dashboard-status-attention { flex-basis: 100%; }
            .dashboard-status-ok { margin-left: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            .dashboard-status-ok summary::after { transition: none; }
        }
    </style>
</head>
<body>
    <div class="dashboard-layout">
        <main class="dashboard-shell">
            <header class="dashboard-header">
                <h1 class="dashboard-title">Dwemer Dashboard</h1>
                <div class="dashboard-ploofy-wrap">
                    <span class="dashboard-ploofy-tag">Free Alpha!</span>
                    <a class="dashboard-ploofy" href="https://ploofy.ai/" target="_blank" rel="noopener noreferrer" aria-label="Ploofy.ai (opens in a new tab)">
                        <img class="dashboard-ploofy-logo" src="images/ploofy-logo.png" alt="" width="338" height="104" decoding="async">
                    </a>
                </div>
            </header>
            <div class="dashboard-mods-heading">
                <h2 class="home-storage-title dashboard-mods-title">Official mods</h2>
                <span class="dashboard-mods-tools">
                    <button type="button" class="mod-card-effects-toggle" aria-pressed="false" hidden>Pause effects</button>
                </span>
            </div>
            <div class="dashboard-mods" role="group" aria-label="Official mods">
                <?php foreach ($modCards as $mod):
                    $displayName = $mod['name'] === 'REIGN' ? 'REIGN (Closed Alpha)' : $mod['name'];
                    // Installation is local file presence, independent of update or service health.
                    $installed = $mod['root'] !== '' && ($mod['name'] === 'REIGN' ? (is_file($mod['root'] . '/ReignServer') || is_file($mod['root'] . '/ReignBetaServer')) : is_file($mod['root'] . '/ui/home.php'));
                    $versionLabel = $installed && isset($mod['versionFiles'])
                        ? (dashboard_server_version($mod['root'], $mod['versionFiles']) ?? 'Version unavailable') : null;
                    $openLabel = 'Open ' . $displayName . ($versionLabel === null ? '' : ', ' . (str_starts_with($versionLabel, 'Version') ? $versionLabel : 'version ' . $versionLabel));
                ?>
                <div class="mod-card mod-card-<?= htmlspecialchars(strtolower($mod['name']), ENT_QUOTES, 'UTF-8') ?>">
                <a class="mod-card-open"
                    <?php if ($installed): ?>
                        href="<?= htmlspecialchars($mod['url'], ENT_QUOTES, 'UTF-8') ?>"
                        aria-label="<?= htmlspecialchars($openLabel, ENT_QUOTES, 'UTF-8') ?>"
                    <?php else: ?>
                        role="link" aria-disabled="true" tabindex="-1"
                        aria-label="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?> — Not installed"
                    <?php endif; ?>>
                    <img class="mod-card-art" src="images/<?= htmlspecialchars($mod['image'], ENT_QUOTES, 'UTF-8') ?>?v=<?= (int) filemtime(__DIR__ . '/images/' . $mod['image']) ?>" alt="" width="1920" height="1080">
                    <span class="mod-card-label">
                        <span class="mod-card-game"><?= htmlspecialchars($mod['game'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($versionLabel !== null): ?><span class="mod-card-version"><?= htmlspecialchars($versionLabel, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        <?php if (!$installed): ?><span class="mod-card-status">Not installed</span><?php endif; ?>
                    </span>
                </a>
                </div>
                <?php endforeach; ?>
            </div>
            <section class="home-custom-mods home-panel" aria-labelledby="home-custom-mods-title">
                <div class="home-custom-mods-heading">
                    <h2 class="home-storage-title" id="home-custom-mods-title">Custom mods</h2>
                    <a class="home-kagrenac-link" href="https://dwemerdynamics.com/custom-mods/index.html" target="_blank" rel="noopener noreferrer" aria-label="KAGRENAC: Create your own AI mod (opens in a new tab)">
                        <img class="kagrenac-brand-icon" src="images/kagrenac-icon.png" alt="" width="42" height="42" decoding="async">
                        <span class="home-kagrenac-text">
                            <span class="home-kagrenac-name">KAGRENAC</span>
                            <span class="home-kagrenac-tagline">Create your own AI mod!</span>
                        </span>
                    </a>
                </div>
                <?php if ($customMods['mods'] === []): ?>
                    <p class="home-storage-note"><?= $customMods['readable'] ? 'No custom mods installed.' : 'No custom mods yet.' ?> Add one in the launcher under Mods &gt; Custom mods.</p>
                <?php elseif ($customMods['mods'] !== []): ?>
                    <ul class="custom-mod-cards" aria-label="Installed custom mods">
                        <?php foreach ($customMods['mods'] as $customModIndex => $customMod): ?>
                            <li>
                                <a class="custom-mod-card" href="<?= htmlspecialchars($customMod['url'], ENT_QUOTES, 'UTF-8') ?>" aria-label="Open <?= htmlspecialchars($customMod['name'], ENT_QUOTES, 'UTF-8') ?>"<?php if ($customMod['version'] !== ''): ?> aria-describedby="custom-mod-card-<?= (int) $customModIndex ?>"<?php endif; ?>>
                                    <span class="custom-mod-card-art" aria-hidden="true">
                                        <span class="custom-mod-card-monogram"><?= htmlspecialchars($customMod['initial'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($customMod['icon'] !== ''): ?>
                                            <img class="custom-mod-card-icon" src="<?= htmlspecialchars($customMod['icon'], ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" decoding="async">
                                        <?php endif; ?>
                                        <?php if ($customMod['banner'] !== ''): ?>
                                            <img class="custom-mod-card-banner" src="<?= htmlspecialchars($customMod['banner'], ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy" decoding="async">
                                        <?php endif; ?>
                                    </span>
                                    <span class="custom-mod-card-label">
                                        <span class="custom-mod-card-name"><?= htmlspecialchars($customMod['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($customMod['version'] !== ''): ?>
                                            <span class="custom-mod-card-version" id="custom-mod-card-<?= (int) $customModIndex ?>">v<?= htmlspecialchars($customMod['version'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <div class="home-split">
            <section class="home-tools home-panel" aria-labelledby="home-tools-title">
                <div class="home-split-head">
                    <h2 class="home-storage-title" id="home-tools-title">Tools</h2>
                </div>
                <ul class="home-tool-list">
                    <li><a class="home-tool lmstudio-manager" href="lmstudio.php"><img class="chim-brand-icon" src="images/centurion.webp" alt="" width="42" height="42"><span class="home-tool-name">LLM Studio</span><span class="home-tool-chev" aria-hidden="true">&rsaquo;</span></a></li>
                    <li><a class="home-tool distro-debugger" href="<?= htmlspecialchars($distroDebuggerUrl, ENT_QUOTES, 'UTF-8') ?>"><img class="kagrenac-brand-icon" src="images/dwemerspider.png" alt=""><span class="home-tool-name">Distro Debugger</span><span class="home-tool-chev" aria-hidden="true">&rsaquo;</span></a></li>
                    <li><a class="home-tool database-manager" href="<?= htmlspecialchars($databaseManagerUrl, ENT_QUOTES, 'UTF-8') ?>"><img class="kagrenac-brand-icon" src="images/puzzlebox.png" alt=""><span class="home-tool-name"><?= htmlspecialchars($databaseManagerLabel, ENT_QUOTES, 'UTF-8') ?></span><span class="home-tool-chev" aria-hidden="true">&rsaquo;</span></a></li>
                </ul>
            </section>
            <div class="home-status home-panel">
            <section class="home-storage" id="home-storage" aria-labelledby="home-storage-title" aria-busy="true"
                data-mods="<?= htmlspecialchars(json_encode($storageMods), ENT_QUOTES, 'UTF-8') ?>">
                <div class="home-split-head">
                    <h2 class="home-storage-title" id="home-storage-title">Database storage</h2>
                    <a class="home-storage-manage" href="data_manager.php?mod=all&amp;view=overview">Manage storage<span aria-hidden="true">&rarr;</span></a>
                </div>
                <div class="home-storage-summary">
                    <strong class="home-storage-total" data-storage-total>Measuring…</strong>
                    <span class="home-storage-note" data-storage-note></span>
                </div>
                <ul class="home-storage-mods" aria-label="Database size by mod">
                    <?php foreach ($storageMods as $storageMod): ?>
                        <li class="home-storage-mod home-storage-mod-<?= htmlspecialchars($storageMod['key'], ENT_QUOTES, 'UTF-8') ?><?= $storageMod['installed'] ? '' : ' is-absent' ?>" data-mod="<?= htmlspecialchars($storageMod['key'], ENT_QUOTES, 'UTF-8') ?>">
                            <span class="home-storage-mod-name"><span class="home-storage-mod-dot" aria-hidden="true"></span><?= htmlspecialchars($storageMod['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="home-storage-mod-meter" aria-hidden="true"><span class="home-storage-mod-bar"></span></span>
                            <span class="home-storage-mod-size"><?= $storageMod['installed'] ? 'Measuring…' : 'Not installed' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <noscript><span class="home-storage-note">Enable JavaScript to measure storage.</span></noscript>
            </section>
            <?php
            $dbStatusOkLines = array_values(array_filter($dbUpdateLines, static fn(array $line): bool => (string) $line['status'] === 'ok'));
            $dbStatusAttentionLines = array_values(array_filter($dbUpdateLines, static fn(array $line): bool => (string) $line['status'] !== 'ok'));
            ?>
            <?php if ($dbUpdateLines !== []): ?>
                <div class="dashboard-status" role="group" aria-label="Database version checks">
                    <?php if ($dbStatusAttentionLines !== []): ?>
                        <div class="dashboard-status-attention">
                            <?php foreach ($dbStatusAttentionLines as $line): ?>
                                <p class="dashboard-status-line <?= htmlspecialchars((string)$line['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$line['detail'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($dbStatusOkLines !== []): ?>
                        <details class="dashboard-status-ok">
                            <summary><?= count($dbStatusOkLines) ?> database <?= count($dbStatusOkLines) === 1 ? 'check' : 'checks' ?> completed <span class="dashboard-status-hint">Details</span></summary>
                            <ul>
                                <?php foreach ($dbStatusOkLines as $line): ?>
                                    <li class="dashboard-status-line ok"><?= htmlspecialchars((string)$line['detail'], ENT_QUOTES, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            </div>
            </div>
        </main>

        <aside class="keys-shell home-panel" id="home-api-keys" aria-labelledby="home-api-keys-title" data-csrf="<?= htmlspecialchars($apiKeysCsrf, ENT_QUOTES, 'UTF-8') ?>">
            <h2 class="patron-title" id="home-api-keys-title">Global API Keys</h2>
            <p class="keys-note" data-keys-message role="status"><?= $apiKeysCsrf === '' ? 'API keys can only be viewed from the browser on this PC.' : 'Loading saved keys…' ?></p>
            <div class="keys-list-scroll" data-keys-list hidden></div>
        </aside>

        <aside class="patron-shell home-panel" aria-label="Current Patreon members">
            <div class="patron-head">
                <h2 class="patron-title"><a class="patron-title-link" href="<?= htmlspecialchars($patreonCampaignUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Patreon Members</a></h2>
                <?php if ($patronSyncNote !== ''): ?>
                    <p class="patron-sync-note"><?= htmlspecialchars($patronSyncNote, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <?php if ($patronActiveCount === 0): ?>
                <p class="patron-empty">No Patreon members found yet. Run <code>csv/patreon_sync_local.php</code> to build <code>data/patron_members.generated.json</code>, or add fallback entries to <code>data/patron_members.json</code>.</p>
            <?php else: ?>
                <div class="patron-list-scroll" aria-label="Patreon members by tier">
                    <div class="patron-list-track">
                        <?php for ($patronListCycle = 0; $patronListCycle < 2; $patronListCycle++): ?>
                            <div class="patron-list-cycle" <?php if ($patronListCycle === 1): ?>aria-hidden="true"<?php endif; ?>>
                                <?php foreach ($patronTierOrder as $tierLabel): ?>
                                    <?php $tierClass = strtolower($tierLabel); ?>
                                    <?php $tierDisplayLabel = $patronTierDisplayLabels[$tierLabel] ?? $tierLabel; ?>
                                    <?php $tierIconPath = $patronTierIconPaths[$tierLabel] ?? ''; ?>
                                    <section class="patron-tier-group patron-tier-<?= htmlspecialchars($tierClass, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($tierDisplayLabel, ENT_QUOTES, 'UTF-8') ?> tier members">
                                        <h3 class="patron-tier-title">
                                            <?php if ($tierIconPath !== ''): ?>
                                                <img class="patron-tier-icon" src="<?= htmlspecialchars($tierIconPath, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($tierDisplayLabel, ENT_QUOTES, 'UTF-8') ?> icon">
                                            <?php endif; ?>
                                            <span class="patron-tier-title-label"><?= htmlspecialchars($tierDisplayLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                        </h3>
                                        <?php if ($patronTierMembers[$tierLabel] === []): ?>
                                            <p class="patron-tier-empty">None</p>
                                        <?php else: ?>
                                            <ul class="patron-list">
                                                <?php foreach ($patronTierMembers[$tierLabel] as $patronMemberName): ?>
                                                    <li class="patron-list-item"><?= htmlspecialchars($patronMemberName, ENT_QUOTES, 'UTF-8') ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php endif; ?>
                                    </section>
                                <?php endforeach; ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</body>
</html>
