<?php
declare(strict_types=1);
session_start();
$_SESSION['lmstudio_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['lmstudio_csrf'];
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="lmstudio-csrf" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <title>LLM Studio · DwemerDistro</title>
    <link rel="stylesheet" href="css/lmstudio.css?v=<?= (int) filemtime(__DIR__ . '/css/lmstudio.css') ?>">
    <script src="js/lmstudio.js?v=<?= (int) filemtime(__DIR__ . '/js/lmstudio.js') ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#engine">Skip to controls</a>
<main>
    <header class="studio-header">
        <div class="brand">
            <img src="images/centurion.webp" alt="" width="64" height="64">
            <div><a class="breadcrumb" href="index.php">← Dwemer Dashboard</a><h1>LLM <span>Studio</span></h1>
                <p class="muted">Your local models. Ready for your worlds.</p></div>
        </div>
        <div class="engine-status"><span class="eyebrow">LOCAL ENGINE</span><strong id="status" role="status" data-state="connecting">Connecting…</strong></div>
    </header>
    <p id="error" role="alert" hidden></p>
    <section id="unlock" class="unlock-panel" hidden aria-labelledby="unlock-title">
        <span class="eyebrow">MANAGER ACCESS</span><h2 id="unlock-title">Connect to your local engine</h2>
        <p>Choose <b>Open Manager</b> in the launcher’s Components page to unlock this workspace.</p>
        <details><summary>Use an access key instead</summary>
            <p class="muted">Run <code>ddistro_lmstudio access</code> as dwemer inside WSL, then paste the key below.</p>
            <form id="unlock-form" class="row"><label>Access key<input id="token" type="password" autocomplete="off" required></label><button class="primary">Unlock manager</button></form>
        </details>
    </section>
    <div id="manager" hidden>
        <nav class="section-nav wide" aria-label="Studio sections">
            <a href="#engine">Engine</a><a href="#downloads">Models</a><a href="#test-console">Test response</a><a href="#activity">Activity</a>
            <span>Runs on this machine</span>
        </nav>
        <section id="engine" class="wide" aria-labelledby="engine-title">
            <div class="section-heading"><div><span class="eyebrow">WORKSPACE</span><h2 id="engine-title">Engine &amp; connections</h2></div><span class="section-note">Local API · port 1234</span></div>
            <dl class="resource-grid" aria-label="Available resources">
                <div><dt>WSL memory available</dt><dd id="ram-available">—</dd></div>
                <div><dt>GPU memory available</dt><dd id="vram-available">—</dd></div>
                <div><dt>Disk space available</dt><dd id="disk-available">—</dd></div>
            </dl>
            <p id="resources" class="sr-only"></p>
            <div class="engine-controls"><div class="button-group"><button class="primary" data-action="start">Start engine</button><button data-action="stop">Stop</button><button data-action="restart">Restart</button></div>
                <label class="inline"><input id="autostart" type="checkbox"> Start with DwemerDistro</label></div>
            <div class="row startup-row"><label>Model to load on startup<select id="startup-model"><option value="">None — start engine only</option></select></label><button id="save-engine">Save startup settings</button></div>
            <p class="muted">Startup uses the saved defaults for that model. Enable “Start with DwemerDistro” to apply it.</p>
            <details class="connections"><summary>Connection details for game servers</summary>
                <div class="connection-grid"><div class="row"><label>API base<input id="endpoint" readonly></label><button data-copy="endpoint">Copy base</button></div>
                    <div class="row"><label>Chat completions URL<input id="chatUrl" readonly></label><button data-copy="chatUrl">Copy URL</button></div></div>
                <p class="muted">Use these addresses in your game server’s connector settings inside WSL. The API listens only on this machine.</p>
            </details>
        </section>
        <section id="downloads" class="download-panel" aria-labelledby="download-title">
            <div class="section-heading"><div><span class="eyebrow">MODEL LIBRARY</span><h2 id="download-title">Download a model</h2></div></div>
            <p class="muted">Add a model to your library, then load it when you’re ready.</p>
            <form id="preset-form"><label>Community model<select id="preset"></select></label><button class="primary">Download selected</button></form>
            <p class="notice">These options use about <b>17–19 GB of disk</b> each and need more memory than a small laptop typically has. Downloading does not load a model.</p>
            <details><summary>Download a custom model</summary>
                <form id="custom-form"><label>Hugging Face URL or model reference<input id="source" placeholder="owner/model-GGUF@Q4_K_M" required maxlength="500"></label><button>Download custom</button></form>
                <p class="muted">Community files are pinned and checksum verified. Custom downloads use the engine’s catalog selection.</p>
            </details>
        </section>
        <section id="models" class="model-panel" aria-labelledby="models-title">
            <div class="section-heading"><div><span class="eyebrow">YOUR LIBRARY</span><h2 id="models-title">Load &amp; manage models</h2></div></div>
            <p id="model-note" class="muted">Start the engine to list installed models.</p>
            <form id="load-form">
                <label>Installed model<select id="model"></select></label>
                <div class="settings-grid load-basics"><label>Context tokens<input id="context" type="number" min="512" max="131072" value="4096" required></label><label>GPU offload %<input id="gpu" type="number" min="0" max="100" value="100" required></label><label>Idle unload · seconds<input id="ttl" type="number" min="60" max="86400" value="600" required></label></div>
                <div class="button-group"><button class="primary">Load model</button><button type="button" id="save-defaults">Save model defaults</button><button type="button" id="reset-load" class="quiet">Reset form</button></div>
                <details id="advanced-load"><summary>Advanced loading settings</summary><p class="muted">Changes apply on the next load. “Engine default” leaves that option unchanged. Quantized V cache requires Flash Attention.</p><div id="load-fields" class="settings-grid"></div></details>
            </form>
            <p id="sdk-note" class="muted"></p>
            <div class="loaded-model"><span class="eyebrow">ACTIVE MODEL</span><div class="row"><label>Loaded model<select id="loaded"></select></label><button id="unload">Unload</button><button data-copy="loaded">Copy model ID</button></div></div>
            <p class="muted">Unload before switching. Memory needs vary with context length and other running components.</p>
            <details><summary>Model details &amp; active configuration</summary><pre id="model-details"></pre></details>
        </section>
        <section id="test-console" class="wide" aria-labelledby="test-title">
            <div class="section-heading"><div><span class="eyebrow">PLAYGROUND</span><h2 id="test-title">Test a response</h2></div><span class="section-note">Uses your loaded model</span></div>
            <p class="muted">A short CHIM-style connection test. Load a model first.</p>
            <div class="test-workspace">
                <form id="test-form">
                    <label>Test prompt<textarea id="prompt" maxlength="4000" rows="4" required>Reply with Hello.</textarea></label>
                    <div class="row"><button id="run-test" class="primary">Test</button></div>
                </form>
                <div class="response-panel"><h3>Response</h3><p id="response-placeholder" class="response-empty" role="status">No response yet.</p><pre id="output" aria-live="polite"></pre><p id="test-stats" class="muted"></p></div>
            </div>
        </section>
        <section id="activity" class="wide activity-panel" aria-labelledby="activity-title"><div class="section-heading"><h2 id="activity-title">Activity</h2><span class="section-note">Downloads, loading &amp; tests</span></div><progress id="progress" max="100" value="0" aria-label="Current operation progress"></progress><pre id="job" aria-live="polite">No operation yet.</pre></section>
    </div>
</main>
</body>
</html>
