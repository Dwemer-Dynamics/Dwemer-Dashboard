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
        <button type="button" id="restart" class="quiet" data-action="restart" data-mutation hidden>Restart</button>
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
<main id="manager" class="studio" hidden>
    <div id="workspace" class="workspace" tabindex="-1">
        <div id="activity" class="activity" data-state="running" hidden>
            <div class="activity-head">
                <strong id="job-title" aria-live="polite"></strong>
                <span id="job-amount"></span>
                <span class="activity-actions">
                    <button type="button" id="job-next" class="primary small" hidden>Test it</button>
                    <button type="button" id="job-dismiss" class="quiet small" hidden>Dismiss</button>
                </span>
            </div>
            <progress id="progress" max="100" value="0" aria-labelledby="job-title"></progress>
            <p id="job-detail"></p>
            <details id="job-log" hidden><summary>Details</summary><pre id="job"></pre></details>
        </div>

        <div class="overview" role="group" aria-label="Server resources and access">
            <dl class="stats">
                <div><dt>RAM free</dt><dd id="stat-ram">—</dd></div>
                <div><dt>VRAM free</dt><dd id="stat-vram">—</dd></div>
            </dl>
            <div class="endpoints">
                <div class="copy-field"><label for="endpoint">API base</label><input id="endpoint" readonly><button type="button" class="small" data-copy="endpoint" aria-label="Copy API base">Copy</button></div>
                <div class="copy-field"><label for="chatUrl">Chat URL</label><input id="chatUrl" readonly><button type="button" class="small" data-copy="chatUrl" aria-label="Copy chat URL">Copy</button></div>
            </div>
        </div>

        <div class="layout">
            <section id="library-section" class="panel library" aria-labelledby="library-title">
                <div class="panel-head">
                    <h2 id="library-title">Installed models</h2>
                    <span id="library-count" class="count"></span>
                </div>
                <div id="library-note" class="note">
                    <p id="library-text" role="status">Connecting…</p>
                    <button type="button" id="open-add" class="small" hidden>Add models</button>
                </div>
                <fieldset id="library" class="library-list" hidden><legend class="sr-only">Choose a model to configure or load</legend></fieldset>
                <input type="hidden" id="model">
                <form id="load-form" class="model-config" novalidate hidden aria-labelledby="config-title">
                    <div class="config-head">
                        <div class="config-name">
                            <h3 id="config-title"></h3>
                            <p id="config-meta" class="muted"></p>
                        </div>
                        <button id="load-button" class="primary" data-mutation aria-describedby="load-reason">Load</button>
                    </div>
                    <p id="load-reason" class="reason" hidden></p>
                    <p id="sdk-note" class="muted" hidden></p>
                    <div class="settings-grid load-basics">
                        <label>Context tokens<input id="context" type="number" min="512" max="131072" value="4096" required></label>
                        <label>GPU offload %<input id="gpu" type="number" min="0" max="100" value="100" required></label>
                        <label>Idle unload (s)<input id="ttl" type="number" min="60" max="86400" value="600" required></label>
                    </div>
                    <details id="advanced-load"><summary>Advanced load settings</summary>
                        <p class="muted">Engine default uses LM Studio’s own setting. Quantized V cache needs Flash Attention.</p>
                        <p id="memlock-note" class="muted" hidden></p>
                        <div id="load-fields" class="settings-grid"></div>
                    </details>
                    <div id="defaults-conflict" class="notice warn" role="status" hidden>
                        <p>Saved defaults changed in another window. Reload saved replaces your edits.</p>
                        <button type="button" id="use-latest" class="small">Reload saved</button>
                    </div>
                    <div class="button-group defaults-actions">
                        <button type="button" id="save-defaults" data-mutation>Save defaults</button>
                        <button type="button" id="reset-load" class="quiet">Reset to built-in</button>
                        <span class="muted">Saved for this model and used the next time it loads.</span>
                    </div>
                    <details><summary>Technical details</summary><pre id="model-details"></pre></details>
                </form>
            </section>

            <section id="slots-section" class="panel slots" aria-labelledby="slots-title">
                <div class="panel-head">
                    <h2 id="slots-title">Loaded</h2>
                    <span id="slots-count" class="count"></span>
                </div>
                <ol id="slots" class="slot-list"></ol>
                <p id="multi-note" class="muted" hidden>Two different models can stay loaded, for example to serve different purposes; connectors choose one by its model ID. Both share RAM and VRAM, so size context and GPU offload for both.</p>
            </section>

            <section id="test-section" class="panel test" aria-labelledby="test-title">
                <h2 id="test-title">Test</h2>
                <form id="test-form">
                    <p id="test-empty" class="note"><span id="test-empty-text">No model loaded.</span></p>
                    <label id="loaded-row">Model<select id="loaded"></select></label>
                    <label>Prompt<textarea id="prompt" maxlength="4000" rows="3" required>Reply with Hello.</textarea></label>
                    <button id="run-test" class="primary">Send test</button>
                </form>
                <div class="response" role="group" aria-label="Response">
                    <p id="response-placeholder" class="response-note" role="status"></p>
                    <pre id="output" aria-live="polite"></pre>
                    <p id="test-stats" class="muted"></p>
                </div>
            </section>
        </div>

        <div class="extras">
            <details id="add-models" class="panel">
                <summary>Add models <span class="muted">Download or import a GGUF</span></summary>
                <div class="extras-body">
                    <p id="preset-note" class="muted" role="status" hidden></p>
                    <form id="preset-form" class="row">
                        <label>Suggested model<select id="preset"></select></label>
                        <button id="preset-download" class="primary" data-mutation>Download</button>
                    </form>
                    <form id="custom-form" class="row">
                        <label>Custom Hugging Face URL or model reference<input id="source" placeholder="owner/model-GGUF@Q4_K_M" required maxlength="500"></label>
                        <button id="custom-download" data-mutation>Download</button>
                    </form>
                    <form id="import-form" class="row">
                        <label>Import local model: path on this PC<input id="local-path" placeholder="C:\Models\model.gguf" required maxlength="1024" autocomplete="off" spellcheck="false" aria-describedby="import-help"></label>
                        <button id="local-import" data-mutation>Import</button>
                    </form>
                    <div id="import-help" class="muted">
                        <p>Import copies a standalone <code>.gguf</code> file already on the PC running DwemerDistro; nothing is uploaded from this browser. Paste its full path; the original stays in place. DwemerDistro needs free space for one copy, or two if LM Studio’s model folder is on another drive.</p>
                        <p>Ollama models are stored as managed blobs, not ordinary GGUF files, so they can’t be imported. Connect CHIM to Ollama instead. Split GGUF sets aren’t supported.</p>
                    </div>
                </div>
            </details>
            <details id="server-settings" class="panel">
                <summary>Startup <span id="startup-summary" class="muted"></span></summary>
                <div class="extras-body">
                    <label class="inline"><input id="autostart" type="checkbox"> Start with DwemerDistro</label>
                    <label>Startup model<select id="startup-model"><option value="">None</option></select></label>
                    <div class="button-group"><button type="button" id="save-engine" data-mutation>Save</button></div>
                </div>
            </details>
        </div>
    </div>
</main>
</body>
</html>
