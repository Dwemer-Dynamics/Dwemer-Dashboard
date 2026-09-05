<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/storage_fragment.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(409);
    exit('These controls have been updated. Reload Storage & Cleanup. Nothing was changed.');
}
if (session_status() === PHP_SESSION_NONE) session_start();
foreach (['storage_csrf','ptm_csrf'] as $key) {
    if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(32));
}
header('Cache-Control: no-store');
$config = ['csrf'=>$_SESSION['storage_csrf'], 'retentionCsrf'=>$_SESSION['ptm_csrf'], 'prefix'=>dm_url_prefix()];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Storage &amp; Cleanup · Dwemer Dashboard</title>
    <link rel="icon" href="images/favicon.ico"><link rel="stylesheet" href="css/data_manager.css?v=<?= filemtime(__DIR__ . '/css/data_manager.css') ?>">
    <script id="sm-config" type="application/json"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="js/data_manager.js?v=<?= filemtime(__DIR__ . '/js/data_manager.js') ?>" defer></script>
</head>
<body class="sm-app">
<a class="sm-skip" href="#sm-content">Skip to storage tools</a>
<main class="sm-shell">
    <header class="sm-header">
        <div><h1>Storage &amp; Cleanup</h1><p class="sm-muted">Manage snapshots, stored data and database backups.</p></div>
        <nav class="sm-actions" aria-label="Related pages"><button id="sm-refresh" type="button">Refresh</button><a class="sm-button" href="distro_debugger.php">Server Logs</a><a class="sm-button" href="index.php">Dashboard</a></nav>
    </header>
    <nav class="sm-brand-tabs" aria-label="Choose a mod">
        <a class="sm-brand" data-mod="all" href="?mod=all"><img class="sm-brand-icon" src="images/kagrenac-icon.png" alt=""><span>Distro</span></a>
        <a class="sm-brand" data-mod="chim" href="?mod=chim"><img class="sm-brand-icon" src="images/chim-icon.png" alt=""><img class="sm-brand-logo" src="images/chim-logo.png" alt="CHIM"></a>
        <a class="sm-brand" data-mod="stobe" href="?mod=stobe"><img class="sm-brand-icon" src="images/stobe-icon.png" alt=""><img class="sm-brand-logo" src="images/stobe-logo.png" alt="STOBE"></a>
        <a class="sm-brand" data-mod="dialectic" href="?mod=dialectic"><img class="sm-brand-icon" src="images/dialectic-icon.png" alt=""><img class="sm-brand-logo" src="images/dialectic-logo.png" alt="DIALECTIC"></a>
    </nav>
    <nav class="sm-task-tabs" id="sm-tasks" aria-label="Storage task"></nav>
    <div id="sm-status" class="sm-status" role="status" aria-live="polite"></div>
    <section id="sm-content" tabindex="-1" aria-busy="true"><p class="sm-empty">Loading storage tools…</p></section>
    <noscript><p class="sm-error">Enable JavaScript to use these tools. No data is changed by opening this page.</p></noscript>
    <footer class="sm-footer">Each mod keeps its own database. A snapshot is stored inside it; a database backup is a separate file.</footer>
</main>
<dialog class="sm-dialog" id="sm-dialog" aria-labelledby="sm-dialog-title">
    <div class="sm-dialog-head"><h2 id="sm-dialog-title"></h2><button type="button" id="sm-dialog-close" aria-label="Close dialog">×</button></div>
    <div class="sm-dialog-body" id="sm-dialog-body"></div>
    <div class="sm-dialog-actions" id="sm-dialog-actions"></div>
</dialog>
</body>
</html>
