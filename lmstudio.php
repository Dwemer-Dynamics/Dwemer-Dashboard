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
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="lmstudio-csrf" content="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
<title>LM Studio · DwemerDistro</title><link rel="stylesheet" href="css/lmstudio.css">
<script src="js/lmstudio.js" defer></script></head>
<body><main>
<header><div class="brand"><img src="images/centurion.webp" alt="" width="64" height="64"><div><a href="index.php">← Dwemer Dashboard</a><h1>LM Studio</h1><p class="muted">Local AI inside DwemerDistro. Models stay on this machine.</p></div></div><strong id="status" role="status">Connecting…</strong></header>
<p id="error" role="alert" hidden></p>
<section id="unlock" hidden><h2>Unlock manager</h2><p>Use <b>Open Manager</b> in the launcher's Components page. You can also paste the access key from <code>ddistro_lmstudio access</code>, run as dwemer inside WSL.</p>
<form id="unlock-form"><label>Access key <input id="token" type="password" autocomplete="off" required></label><button>Unlock</button></form></section>
<div id="manager" hidden>
<section class="wide"><h2>Engine</h2><div class="row"><button data-action="start">Start</button><button data-action="stop">Stop</button><button data-action="restart">Restart</button><label class="inline"><input id="autostart" type="checkbox"> Start with DwemerDistro</label></div><p id="resources"></p>
<p>Connections from game servers inside WSL:</p><div class="row"><label>API base<input id="endpoint" readonly></label><button data-copy="endpoint">Copy base</button><label>Chat completions URL<input id="chatUrl" readonly></label><button data-copy="chatUrl">Copy URL</button></div><p class="muted">Copy these into your provider settings. Existing providers are unchanged. The API listens only on this machine.</p></section>
<section class="wide"><h2>Download models</h2><p>These community options use about 17–19 GB of disk space each. They need substantially more memory than a small laptop has. Downloading does not load a model.</p>
<form id="preset-form" class="row"><label>Model option<select id="preset"></select></label><button>Download selected</button></form>
<form id="custom-form" class="row"><label>Custom Hugging Face URL or owner/model@quantization<input id="source" placeholder="owner/model-GGUF@Q4_K_M" required maxlength="500"></label><button>Download custom</button></form><p class="muted">Preset files are pinned and checksum verified. Custom downloads use LM Studio's catalog selection.</p></section>
<section class="wide"><h2>Installed models</h2><p id="model-note">Start the engine to list installed models.</p>
<form id="load-form"><div class="row"><label>Model<select id="model"></select></label><label>Context tokens<input id="context" type="number" min="512" max="131072" value="4096" required></label><label>GPU offload %<input id="gpu" type="number" min="0" max="100" value="100" required></label><label>Idle unload (seconds)<input id="ttl" type="number" min="60" max="86400" value="600" required></label><button>Load</button></div></form>
<div class="row"><label>Loaded model<select id="loaded"></select></label><button id="unload">Unload</button><button data-copy="loaded">Copy model ID</button></div>
<p class="muted">Unload before switching models. Memory checks leave headroom; actual needs also depend on context length and other running components.</p></section>
<section><h2>Test response</h2><form id="test-form"><label>Prompt<textarea id="prompt" maxlength="4000" rows="2" required>Say hello in one short sentence.</textarea></label><div class="row"><label>Thinking for this test<select id="reasoning"><option value="default">Model default</option><option value="off">Off</option><option value="on">On</option><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></label><button>Run short test</button></div></form><p class="muted">Unsupported thinking settings return an error. This test does not change game settings.</p><pre id="output" aria-live="polite"></pre></section>
<section><h2>Current operation</h2><progress id="progress" max="100" value="0"></progress><pre id="job" aria-live="polite">No operation yet.</pre></section>
</div></main></body></html>
