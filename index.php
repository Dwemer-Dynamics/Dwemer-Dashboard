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

$herikaUpdateStatus = 'unavailable';
$herikaUpdateDetail = 'HerikaServer path not found, DB update was not triggered.';
$stobeUpdateStatus = 'unavailable';
$stobeUpdateDetail = 'StobeServer path not found, DB update was not triggered.';
$hasCustomBackground = file_exists(__DIR__ . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'background.jpg');

if ($herikaRoot !== '') {
    try {
        require_once($herikaRoot . DIRECTORY_SEPARATOR . 'ui' . DIRECTORY_SEPARATOR . 'profile_loader.php');
        $dbDriver = trim(strval($GLOBALS['DBDRIVER'] ?? ''));
        if ($dbDriver === '') {
            throw new RuntimeException('DBDRIVER not set after profile_loader');
        }

        require_once($herikaRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . $dbDriver . '.class.php');
        if (!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) {
            $GLOBALS['db'] = new sql();
        }
        $db = $GLOBALS['db'];

        require_once($herikaRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'db_updates.php');
        require_once($herikaRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'npc_removal.php');
        $herikaUpdateStatus = 'ok';
        $herikaUpdateDetail = 'HerikaServer database versioning check completed.';
    } catch (Throwable $e) {
        $herikaUpdateStatus = 'error';
        $herikaUpdateDetail = 'HerikaServer DB update trigger failed. Check HerikaServer logs.';
        error_log('[DwemerDashboard] Herika DB update trigger failed: ' . $e->getMessage());
    }
}

if ($stobeRoot !== '') {
    try {
        $stobeRunner = $stobeRoot . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR . 'run_db_updates.php';
        $disableFunctions = strtolower(strval(ini_get('disable_functions') ?: ''));
        $canExec = function_exists('exec') && strpos($disableFunctions, 'exec') === false;

        if ($canExec && is_file($stobeRunner)) {
            $output = [];
            $exitCode = 0;
            $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stobeRunner) . ' 2>&1';
            exec($command, $output, $exitCode);
            if ($exitCode !== 0) {
                $error = trim(implode("\n", $output));
                if ($error === '') {
                    $error = 'unknown error';
                }
                throw new RuntimeException('StobeServer DB update process failed: ' . $error);
            }
        } else {
            // Fallback when exec is disabled: run inline.
            if ((!isset($GLOBALS['db']) || !is_object($GLOBALS['db'])) && file_exists($stobeRoot . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'bootstrap.php')) {
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
        }

        $stobeUpdateStatus = 'ok';
        $stobeUpdateDetail = 'StobeServer database versioning check completed.';
    } catch (Throwable $e) {
        $stobeUpdateStatus = 'error';
        $stobeUpdateDetail = 'StobeServer DB update trigger failed. Check StobeServer logs.';
        error_log('[DwemerDashboard] Stobe DB update trigger failed: ' . $e->getMessage());
    }
}

$dbUpdateLines = [
    ['status' => $herikaUpdateStatus, 'detail' => $herikaUpdateDetail],
    ['status' => $stobeUpdateStatus, 'detail' => $stobeUpdateDetail],
];

$chimUrl = '/HerikaServer/ui/index.php';
$stobeUrl = '/StobeServer/ui/home.php';
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

        .dashboard-shell {
            width: min(900px, 92vw);
            background: rgba(24, 28, 35, 0.95);
            border: 1px solid rgba(138, 155, 182, 0.25);
            border-radius: 14px;
            padding: 40px 32px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.4);
            text-align: center;
        }

        .dashboard-title {
            margin-bottom: 10px;
            font-size: 42px;
            font-family: 'MagicCards', serif;
            color: #ffffff;
            letter-spacing: 0;
            word-spacing: 1px;
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
            width: 180px;
            height: auto;
            object-fit: contain;
            display: block;
        }

        .chim-brand-icon {
            width: 42px;
            height: 42px;
            object-fit: contain;
            display: block;
            border-radius: 4px;
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
    </style>
</head>
<body>
    <main class="dashboard-shell">
        <h1 class="dashboard-title">DwemerDashboard</h1>
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
        </div>
        <div class="dashboard-status">
            <?php foreach ($dbUpdateLines as $line): ?>
                <span class="dashboard-status-line <?= htmlspecialchars((string)$line['status'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars((string)$line['detail'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
