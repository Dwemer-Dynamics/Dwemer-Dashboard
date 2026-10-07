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
<a class="skip-link" href="#workspace">Skip to controls</a>
<header class="studio-header">
    <div class="brand">
        <img src="images/centurion.webp" alt="" width="32" height="32">
        <div><a class="back" href="index.php">← Dashboard</a><h1>LLM <span>Studio</span></h1></div>
    </div>
    <div class="engine">
        <span id="status" role="status" data-state="connecting">Connecting…</span>
        <button type="button" id="engine-toggle" class="primary" data-mutation disabled>Start</button>
    </div>
</header>
<p id="error" role="alert" hidden></p>
<section id="unlock" class="unlock-panel" hidden aria-labelledby="unlock-title">
    <h2 id="unlock-title">Locked</h2>
    <p>Choose <b>Open Manager</b> in the launcher’s Components page to unlock.</p>
    <details><summary>Use an access key</summary>
        <p class="muted">Run <code>ddistro_lmstudio access</code> as dwemer inside WSL, then paste the key.</p>
        <form id="unlock-form" class="row"><label>Access key<input id="token" type="password" autocomplete="off" required></label><button class="primary">Unlock</button></form>
    </details>
</section>
<div id="manager" class="studio" hidden>
    <div class="rail" role="tablist" aria-label="LLM Studio" aria-orientation="vertical">
        <button type="button" role="tab" id="tab-test" aria-controls="panel-test" aria-selected="false" tabindex="-1" data-tab="test">Test</button>
        <button type="button" role="tab" id="tab-models" aria-controls="panel-models" aria-selected="true" tabindex="0" data-tab="models">Models</button>
        <button type="button" role="tab" id="tab-server" aria-controls="panel-server" aria-selected="false" tabindex="-1" data-tab="server">Server</button>
    </div>
    <div id="workspace" class="workspace" tabindex="-1">
        <div id="activity" class="activity" data-state="running" hidden>
            <div class="activity-head">
                <strong id="job-title" aria-live="polite"></strong>
                <span id="job-amount"></span>
                <span class="activity-actions">
                    <button type="button" id="job-next" class="primary small" hidden>Go to Test</button>
                    <button type="button" id="job-dismiss" class="quiet small" hidden>Dismiss</button>
                </span>
            </div>
            <progress id="progress" max="100" value="0" aria-labelledby="job-title"></progress>
            <p id="job-detail"></p>
            <details id="job-log" hidden><summary>Details</summary><pre id="job"></pre></details>
        </div>

        <section id="panel-test" class="panel test-panel" role="tabpanel" aria-labelledby="tab-test" hidden>
            <div class="test-input">
                <div id="test-empty" class="empty">
                    <p id="test-empty-text">No model loaded.</p>
                    <button type="button" id="goto-models">Open Models</button>
                </div>
                <div id="loaded-row" class="row">
                    <label>Model<select id="loaded"></select></label>
                    <button type="button" id="unload" data-mutation>Unload</button>
                    <button type="button" class="quiet" data-copy="loaded">Copy ID</button>
                </div>
                <form id="test-form">
                    <label>Prompt<textarea id="prompt" maxlength="4000" rows="4" required>Reply with Hello.</textarea></label>
                    <button id="run-test" class="primary">Test</button>
                </form>
            </div>
            <div class="response" aria-label="Response">
                <p id="response-placeholder" class="response-note" role="status"></p>
                <pre id="output" aria-live="polite"></pre>
                <p id="test-stats" class="muted"></p>
            </div>
        </section>

        <section id="panel-models" class="panel models-panel" role="tabpanel" aria-labelledby="tab-models">
            <div class="block" role="group" aria-labelledby="installed-title">
                <h2 id="installed-title">Installed</h2>
                <p id="model-note" class="muted">Start the server to see models.</p>
                <div id="loaded-note" class="notice" hidden>
                    <p><b id="loaded-name"></b> is loaded. Unload it to load another model.</p>
                    <div class="button-group">
                        <button type="button" id="notice-test" class="small">Test</button>
                        <button type="button" id="notice-unload" class="small" data-mutation>Unload</button>
                    </div>
                </div>
                <form id="load-form" novalidate hidden>
                    <div class="row">
                        <label>Model<select id="model"></select></label>
                        <button id="load-button" class="primary" data-mutation>Load</button>
                    </div>
                    <p id="sdk-note" class="muted" hidden></p>
                    <details id="load-settings"><summary>Load settings</summary>
                        <p class="muted">Changes apply next time you load the model.</p>
                        <div class="settings-grid load-basics">
                            <label>Context tokens<input id="context" type="number" min="512" max="131072" value="4096" required></label>
                            <label>GPU offload %<input id="gpu" type="number" min="0" max="100" value="100" required></label>
                            <label>Idle unload (s)<input id="ttl" type="number" min="60" max="86400" value="600" required></label>
                        </div>
                        <details id="advanced-load"><summary>Advanced</summary>
                            <p class="muted">Engine default uses LM Studio’s own setting. Quantized V cache needs Flash Attention.</p>
                            <p id="memlock-note" class="muted" hidden></p>
                            <div id="load-fields" class="settings-grid"></div>
                        </details>
                        <div id="defaults-conflict" class="notice warn" role="status" hidden>
                            <p>Saved defaults changed in another window. Reload saved replaces your edits.</p>
                            <button type="button" id="use-latest" class="small">Reload saved</button>
                        </div>
                        <div class="button-group">
                            <button type="button" id="save-defaults" data-mutation>Save defaults</button>
                            <button type="button" id="reset-load" class="quiet">Reset to built-in</button>
                        </div>
                    </details>
                    <details><summary>Technical details</summary><pre id="model-details"></pre></details>
                </form>
            </div>
            <div class="block" role="group" aria-labelledby="download-title">
                <h2 id="download-title">Download</h2>
                <p id="preset-note" class="muted" role="status" hidden></p>
                <form id="preset-form" class="row">
                    <label>Model<select id="preset"></select></label>
                    <button id="preset-download" class="primary" data-mutation>Download</button>
                </form>
                <details><summary>Custom Hugging Face model</summary>
                    <form id="custom-form" class="row">
                        <label>URL or model reference<input id="source" placeholder="owner/model-GGUF@Q4_K_M" required maxlength="500"></label>
                        <button id="custom-download" data-mutation>Download</button>
                    </form>
                </details>
            </div>
        </section>

        <section id="panel-server" class="panel server-panel" role="tabpanel" aria-labelledby="tab-server" hidden>
            <label class="inline"><input id="autostart" type="checkbox"> Start with DwemerDistro</label>
            <label>Startup model<select id="startup-model"><option value="">None</option></select></label>
            <div class="button-group"><button type="button" id="save-engine" data-mutation>Save</button></div>
            <div class="row">
                <label>API base<input id="endpoint" readonly></label>
                <button type="button" data-copy="endpoint">Copy</button>
            </div>
            <div class="row">
                <label>Chat URL<input id="chatUrl" readonly></label>
                <button type="button" data-copy="chatUrl">Copy</button>
            </div>
            <div class="server-actions"><button type="button" id="restart" data-action="restart" data-mutation>Restart</button></div>
        </section>
    </div>
</div>
</body>
</html>
