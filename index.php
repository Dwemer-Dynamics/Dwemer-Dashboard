<?php
declare(strict_types=1);

error_reporting(E_ERROR);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$dbUpdateLines = [
    ['status' => $herikaUpdateStatus, 'detail' => $herikaUpdateDetail],
    ['status' => $stobeUpdateStatus, 'detail' => $stobeUpdateDetail],
    ['status' => $dialecticUpdateStatus, 'detail' => $dialecticUpdateDetail],
];

$chimUrl = '/HerikaServer/ui/index.php';
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
$stobeUrl = sprintf('%s://%s:8083/StobeServer/ui/index.php', $requestScheme, $stobeHostForUrl);
$dialecticUrl = sprintf('%s://%s:8088/DialecticServer/ui/', $requestScheme, $stobeHostForUrl);
$distroDebuggerUrl = 'distro_debugger.php';
$databaseManagerUrl = 'database_manager.php';

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
    <style>
        @font-face {
            font-family: 'MagicCards';
            src: url('css/font/MagicCardsNormal.ttf') format('truetype');
            font-weight: normal;
            font-style: normal;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            <?php if ($hasCustomBackground): ?>
            background:
                linear-gradient(180deg, rgba(24, 28, 35, 0.55) 0%, rgba(15, 17, 22, 0.75) 100%),
                url('images/background.jpg') center center / cover no-repeat fixed;
            <?php else: ?>
            background: linear-gradient(180deg, #23272f 0%, #161a20 100%);
            <?php endif; ?>
        }

        .dashboard-layout {
            width: min(980px, 95vw);
            box-sizing: border-box;
        }

        .dashboard-shell {
            width: 100%;
            background: rgba(24, 28, 35, 0.95);
            border: 1px solid rgba(138, 155, 182, 0.25);
            border-radius: 14px;
            padding: 40px 32px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.4);
            text-align: center;
        }

        .patron-shell {
            position: fixed;
            top: 5vh;
            right: max(16px, 2vw);
            width: min(340px, 28vw);
            max-height: 90vh;
            background: transparent;
            border: 0;
            border-radius: 0;
            padding: 0;
            box-shadow: none;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 0;
        }

        .patron-title {
            margin: 0 0 14px;
            width: 90%;
            font-size: 24px;
            color: #ffffff;
            line-height: 1.2;
            font-family: 'MagicCards', serif;
            letter-spacing: 0.4px;
            word-spacing: 6px;
            text-align: right;
            text-shadow:
                -1px -1px 0 #000,
                 1px -1px 0 #000,
                -1px  1px 0 #000,
                 1px  1px 0 #000;
        }

        .patron-sync-note {
            margin: 0 0 12px;
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
            gap: 5px;
        }

        .patron-list-scroll {
            height: 90vh;
            max-height: 90vh;
            width: 90%;
            margin: 0 auto;
            overflow: hidden;
            min-height: 0;
        }

        .patron-list-track {
            display: flex;
            flex-direction: column;
            animation: patron-list-scroll-loop <?= intval($patronScrollDurationSeconds) ?>s linear infinite;
            will-change: transform;
        }

        .patron-list-cycle {
            flex: 0 0 auto;
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
            padding: 10px 0 12px;
        }

        .patron-tier-title {
            margin: 0 0 10px auto;
            width: 50%;
            padding-bottom: 7px;
            border-bottom: 1px solid rgba(138, 155, 182, 0.45);
            color: #d8e3f2;
            font-size: 19.5px;
            letter-spacing: 1px;
            text-transform: none;
            font-weight: normal;
            font-family: 'MagicCards', serif;
            text-align: right;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
        }

        .patron-tier-icon {
            width: 56px;
            height: 56px;
            object-fit: contain;
            display: block;
            flex: 0 0 56px;
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
            color: #f2f5f9;
            font-size: 14px;
            padding: 0;
            line-height: 1.3;
            word-break: break-word;
            text-align: right;
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
            text-underline-offset: 2px;
        }

        .patron-empty {
            margin: 0;
            color: #c7d0dd;
            font-size: 14px;
            line-height: 1.5;
        }

        .patron-tier-empty {
            margin: 0;
            color: #9cadc3;
            font-size: 13px;
            line-height: 1.4;
            text-align: right;
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

        .dashboard-subtitle {
            color: #aeb8c5;
            margin-bottom: 28px;
        }

        .dashboard-actions {
            display: flex;
            justify-content: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .dashboard-actions-secondary {
            margin-top: 16px;
        }

        .dashboard-button {
            min-width: 280px;
            min-height: 76px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 22px;
            text-transform: uppercase;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid rgba(138, 155, 182, 0.3);
            color: #ffffff;
            background-color: rgba(30, 35, 45, 0.8);
            transition: all 0.2s ease-in-out;
            padding-inline: 18px;
        }

        .dashboard-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
            text-decoration: none;
            color: #ffffff;
        }

        .dashboard-button.chim {
            background-color: rgb(242, 124, 17);
            border-color: rgba(242, 124, 17, 0.95);
            color: #ffffff;
        }

        .dashboard-button.chim:hover {
            background-color: rgb(221, 106, 6);
            border-color: rgba(221, 106, 6, 0.95);
        }

        .dashboard-button.stobe {
            background-color: #e6b76c;
            border-color: #e6b76c;
            color: #ffffff;
        }

        .dashboard-button.stobe:hover {
            background-color: #d2a45a;
            border-color: #d2a45a;
        }

        .dashboard-button.dialectic {
            background-color: rgb(255, 182, 65);
            border-color: rgb(255, 182, 65);
            color: #1a1a1a;
        }

        .dashboard-button.dialectic:hover {
            background-color: rgb(231, 157, 42);
            border-color: rgb(231, 157, 42);
        }

        .dashboard-button.distro-debugger {
            background-color: #5e0505;
            border-color: #842121;
            color: #ffffff;
            min-width: 320px;
        }

        .dashboard-button.distro-debugger:hover {
            background-color: #710909;
            border-color: #9f2e2e;
        }

        .dashboard-button.database-manager {
            background-color: #5e0505;
            border-color: #842121;
            color: #ffffff;
            min-width: 320px;
        }

        .dashboard-button.database-manager:hover {
            background-color: #710909;
            border-color: #9f2e2e;
        }

        .dashboard-button.placeholder {
            opacity: 0.65;
            pointer-events: auto;
            background-color: rgba(45, 45, 45, 0.72);
            border: 1px solid rgba(230, 183, 108, 0.25);
            color: #9fa8b3;
            cursor: not-allowed;
        }

        .chim-brand {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            line-height: 1;
        }

        .chim-brand-main {
            width: 108px;
            height: auto;
            object-fit: contain;
            display: block;
        }

        .chim-brand-icon {
            width: 42px;
            height: 42px;
            min-width: 42px;
            min-height: 42px;
            object-fit: contain;
            display: block;
            border-radius: 4px;
        }

        .kagrenac-brand-icon {
            width: 42px;
            height: 42px;
            min-width: 42px;
            min-height: 42px;
            object-fit: cover;
            display: block;
            border-radius: 50%;
            border: 1px solid rgba(242, 124, 17, 0.75);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.28);
        }

        .distro-debugger-label {
            line-height: 1;
            letter-spacing: 0.4px;
        }

        .database-manager-label {
            line-height: 1;
            letter-spacing: 0.4px;
        }

        .dashboard-status {
            margin-top: 20px;
            font-size: 14px;
        }

        .dashboard-status-line {
            display: block;
            line-height: 1.5;
            color: #c7d0dd;
        }

        .dashboard-status-line.ok {
            color: #8ed081;
        }

        .dashboard-status-line.error {
            color: #ef6b6b;
        }

        @media (max-width: 1080px) {
            .dashboard-layout {
                width: min(980px, 94vw);
            }

            .patron-shell {
                position: static;
                width: 100%;
                max-height: none;
                padding-top: 18px;
            }

            .patron-list-scroll {
                width: 90%;
                margin: 5vh auto;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .patron-list-track {
                animation: none;
            }
        }
    </style>
</head>
<body>
    <main class="dashboard-layout">
        <section class="dashboard-shell">
            <h1 class="dashboard-title">Dwemer Dashboard</h1>
            <div class="dashboard-actions">
                <a class="dashboard-button chim" href="<?= htmlspecialchars($chimUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="chim-brand">
                        <img class="chim-brand-icon" src="images/chim-icon.png" alt="Dwemer Dynamics logo">
                        <img class="chim-brand-main" src="images/chim-logo.png" alt="CHIM logo">
                    </span>
                </a>
                <a class="dashboard-button stobe" href="<?= htmlspecialchars($stobeUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="chim-brand">
                        <img class="chim-brand-icon" src="images/stobe-icon.png" alt="StobeServer icon">
                        <img class="chim-brand-main" src="images/stobe-logo.png" alt="StobeServer logo">
                    </span>
                </a>
                <a class="dashboard-button dialectic" href="<?= htmlspecialchars($dialecticUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="chim-brand">
                        <img class="chim-brand-icon" src="images/dialectic-icon.png" alt="DialecticServer icon">
                        <img class="chim-brand-main" src="images/dialectic-logo.png" alt="DialecticServer logo">
                    </span>
                </a>
            </div>
            <div class="dashboard-actions dashboard-actions-secondary">
                <a class="dashboard-button distro-debugger" href="<?= htmlspecialchars($distroDebuggerUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="chim-brand">
                        <img class="kagrenac-brand-icon" src="images/kagrenac-icon.png" alt="Kagrenac MCP icon">
                        <span class="distro-debugger-label">Distro Debugger</span>
                    </span>
                </a>
                <a class="dashboard-button database-manager" href="<?= htmlspecialchars($databaseManagerUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <span class="chim-brand">
                        <span class="database-manager-label">Database Manager</span>
                    </span>
                </a>
            </div>
            <div class="dashboard-status">
                <?php foreach ($dbUpdateLines as $line): ?>
                    <span class="dashboard-status-line <?= htmlspecialchars((string)$line['status'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars((string)$line['detail'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="patron-shell" aria-label="Current Patreon members">
            <h2 class="patron-title"><a class="patron-title-link" href="<?= htmlspecialchars($patreonCampaignUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">Patreon Members</a></h2>
            <?php if ($patronSyncNote !== ''): ?>
                <p class="patron-sync-note"><?= htmlspecialchars($patronSyncNote, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
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
    </main>
</body>
</html>
