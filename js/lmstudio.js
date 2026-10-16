'use strict';
const el = id => document.getElementById(id);
const csrf = document.querySelector('meta[name="lmstudio-csrf"]').content;
let unlocked = false, pending = false, queued = false, busy = false, submitting = false;
let current = null, shownModel = null, engineDirty = false, dismissedJob = null, epoch = 0;
let testRun = null, testFailure = null, staleBefore = 0, preferLoaded = '';
// Polling stops only when the session needs unlocking; transient status failures keep retrying.
let needsUnlock = false, catalogReady = false, catalogPending = false, catalogRetryAt = 0;
// Status pauses while a key is checked; responses to requests sent before the latest key check are stale and never lock or render.
let authorizing = false, authGen = 0, authError = '';
// Action and polling failures are reported separately so a poll never hides an action error.
const errors = {action: '', poll: ''};
// Loaded instances seen by the previous poll, the latest test result seen, and a last-load record known to describe an earlier load.
let seenInstances = null, seenTest = null, seenLoadJob = '', staleLoad = '';
// Saved defaults the form was filled from, the form as filled, and the model and defaults this page last saved.
let savedBase = '', filledForm = '', ownSaved = null;
// Jobs submitted from this page; only these get a success message.
const ownJobs = new Map();
// Every catalog entry by id, and the completed download that last refreshed their Installed state.
let catalogModels = [], catalogJob = '';
// Hugging Face browser: only the latest request may render; closing the install dialog aborts it. state is the
// committed search the shown results came from; Show more reuses it with the cursor the helper returned.
const hf = {seq: 0, controller: null, files: null, state: null, cursor: null, ids: new Set(), first: new Map(), scroll: 0};
const hfSorts = {trending: 'Trending', downloads: 'Most downloaded', likes: 'Most liked', updated: 'Recently updated', newest: 'Newest'};
// The button that opened the install dialog gets focus back when it closes.
let installOpener = null;
// A download or import finished on this page and the exact model key the helper reported for it, if any.
let handoff = null;
const loadFields = {
    flashAttention: ['Flash Attention', 'bool'], offloadKVCacheToGpu: ['Offload KV cache to GPU', 'bool'],
    gpuStrictVramCap: ['Strict GPU memory limit', 'bool'], keepModelInMemory: ['Keep model in memory', 'bool'],
    tryMmap: ['Memory mapping (mmap)', 'bool'], evalBatchSize: ['Evaluation batch size', 1, 2048, 1],
    seed: ['Random seed', 0, 4294967295, 1],
    llamaKCacheQuantizationType: ['K cache precision', 'cache'], llamaVCacheQuantizationType: ['V cache precision', 'cache'],
    ropeFrequencyBase: ['RoPE frequency base', 0, 10000000, 'any'], ropeFrequencyScale: ['RoPE frequency scale', .01, 100, 'any']
};
// Matches the CHIM connector greeting test; the helper applies its own generation defaults.
const testSystemPrompt = 'This is an isolated connection test, not a game scene. Respond with a short greeting. Do not request any action.';
const jobNames = {start: 'Starting server', stop: 'Stopping server', restart: 'Restarting server', settings: 'Saving settings',
    download: 'Downloading', import: 'Importing model', load: 'Loading model', unload: 'Unloading model', test: 'Testing', 'model-defaults': 'Saving defaults'};
const failNames = {start: 'Start failed', stop: 'Stop failed', restart: 'Restart failed', settings: 'Save failed',
    download: 'Download failed', import: 'Import failed', load: 'Load failed', unload: 'Unload failed', test: 'Test failed', 'model-defaults': 'Save failed'};
const doneNames = {load: 'Model loaded', unload: 'Model unloaded', download: 'Download complete', import: 'Model imported', settings: 'Settings saved', 'model-defaults': 'Defaults saved'};
// Load and download successes lead somewhere next, so they stay until dismissed; saves fade after a few seconds.
const lastingDone = new Set(['load', 'download', 'import']);
// The helper may report an optional job phase. Only transfer phases show a percentage.
const transferPhases = new Set(['download', 'downloading', 'transfer', 'resume', 'resuming', 'copy']);
const phaseNames = {download: 'Downloading', downloading: 'Downloading', transfer: 'Downloading', resume: 'Resuming download',
    resuming: 'Resuming download', copy: 'Copying model', verify: 'Verifying download', verifying: 'Verifying download', import: 'Importing model',
    importing: 'Importing model', retry: 'Retrying', retrying: 'Retrying'};

// Keep optional values empty so engine defaults remain available for every model.
function buildFields(container, fields) {
    for (const [key, [title, min, max, step]] of Object.entries(fields)) {
        const label = document.createElement('label'); label.textContent = title;
        const input = document.createElement(typeof min === 'string' ? 'select' : 'input'); input.id = key;
        if (typeof min === 'string') {
            input.add(new Option('Engine default', ''));
            const choices = min === 'bool' ? [['Enabled', 'true'], ['Disabled', 'false']] :
                ['f32', 'f16', 'q8_0', 'q4_0', 'q4_1', 'iq4_nl', 'q5_0', 'q5_1'].map(v => [v, v]);
            choices.forEach(([name, value]) => input.add(new Option(name, value)));
        } else { input.type = 'number'; input.min = min; input.max = max; input.step = step; input.placeholder = 'Engine default'; }
        label.append(input); el(container).append(label);
    }
}
buildFields('load-fields', loadFields);

function readFields(fields) {
    const values = {};
    for (const [key, spec] of Object.entries(fields)) {
        const input = el(key); if (!input.reportValidity()) throw new Error(`Check ${spec[0]}.`);
        if (input.value !== '') values[key] = spec[1] === 'bool' ? input.value === 'true' : spec[1] === 'cache' ? input.value : Number(input.value);
    }
    return values;
}

function fillFields(fields, values) { Object.keys(fields).forEach(key => { el(key).value = values[key] ?? ''; }); }

function loadValues() { return {model: el('model').value, context: Number(el('context').value), gpu: Number(el('gpu').value), ttl: Number(el('ttl').value), advanced: readFields(loadFields)}; }

// Without the SDK, Load deliberately skips advanced values; they stay in the form and in saved defaults.
function basicLoad() { return !current?.advancedAvailable && Object.keys(loadFields).some(key => el(key).value !== ''); }

// Form text for a set of defaults, so saved values compare with the form however they were typed.
function snapshotOf(values) {
    const advanced = values.advanced || {};
    return JSON.stringify([values.context ?? 4096, values.gpu ?? 100, values.ttl ?? 600, ...Object.keys(loadFields).map(key => advanced[key] ?? '')].map(String));
}

function savedSnapshot(model) { return snapshotOf(current?.modelDefaults?.[model] || {}); }

function formSnapshot() { return JSON.stringify(['context', 'gpu', 'ttl', ...Object.keys(loadFields)].map(id => el(id).value)); }

// Settings live in collapsed sections, so open the section holding an invalid value before reporting it.
function validLoad() {
    const invalid = el('load-form').querySelector(':invalid:not(form)');
    if (!invalid) return true;
    for (let node = invalid.closest('details'); node; node = node.parentElement.closest('details')) node.open = true;
    invalid.reportValidity();
    return false;
}

// Apply defaults only when the user changes models, never during status polling.
function selectModel(reset = false) {
    const values = reset ? {} : current?.modelDefaults?.[el('model').value] || {};
    el('context').value = values.context ?? 4096; el('gpu').value = values.gpu ?? 100; el('ttl').value = values.ttl ?? 600;
    fillFields(loadFields, values.advanced || {}); shownModel = el('model').value;
    if (!reset) { savedBase = savedSnapshot(shownModel); filledForm = formSnapshot(); el('defaults-conflict').hidden = true; }
}

// Unedited forms follow newer saved defaults; edited forms keep their values and block a stale Save until the user chooses.
function syncDefaults() {
    const latest = savedSnapshot(el('model').value);
    if (latest !== savedBase) {
        const form = formSnapshot();
        if (form === filledForm) selectModel();
        else if (form === latest) { savedBase = latest; filledForm = form; }
        // This page's own save landed while the user kept editing; those edits stay unsaved, not in conflict.
        else if (ownSaved?.model === el('model').value && latest === ownSaved.snapshot) { savedBase = latest; ownSaved = null; }
    }
}

function setText(id, text) { if (el(id).textContent !== text) el(id).textContent = text; }

// Why Load is off, shown beside it; a running job explains itself on the activity card instead.
function loadReason(running, model, full) {
    if (!current?.installed || !model) return '';
    if (!running) return 'Start the server to load this model. You can still edit and save its defaults.';
    if (loadedModel(model)) return 'This model is already loaded.';
    if (full) return maxLoaded() > 1 ? 'Both positions are in use. Unload one first.' : 'A model is already loaded. Unload it first.';
    return '';
}

// Mutations stay disabled while a request or job runs; navigation, disclosures and copy stay usable.
function updateControls() {
    const locked = busy || submitting, running = Boolean(current?.running), installed = Boolean(current?.installed);
    const toggle = el('engine-toggle');
    setText('engine-toggle', running ? 'Stop' : 'Start');
    toggle.setAttribute('aria-label', running ? 'Stop server' : 'Start server');
    toggle.classList.toggle('primary', !running);
    toggle.disabled = locked || !unlocked || !current || !installed;
    el('restart').hidden = !running;
    el('restart').disabled = locked || !installed || !running;
    // The helper refuses a loaded model and loads beyond its limit, so Load stays off for those.
    const model = el('model').value, full = el('loaded').options.length >= maxLoaded();
    el('load-button').disabled = locked || !running || !model || loadedModel(model) || full;
    const reason = loadReason(running, model, full);
    setText('load-reason', reason); el('load-reason').hidden = !reason;
    // The SDK note and Load label describe the same form, so Reset and model changes update both at once.
    const basic = basicLoad();
    setText('load-button', basic ? 'Load basic' : 'Load');
    if (current && !current.advancedAvailable) setText('sdk-note', basic ?
        'Advanced loading is unavailable. Load basic skips these settings; your saved values are kept.' :
        'Reinstall the LLM Studio component to enable advanced loading.');
    el('save-defaults').disabled = locked || !el('model').value || !el('defaults-conflict').hidden;
    el('slots').querySelectorAll('[data-slot-action=unload]').forEach(button => { button.disabled = locked; });
    ['save-engine', 'custom-download', 'local-import'].forEach(id => { el(id).disabled = locked; });
    el('recommended').querySelectorAll('button[data-preset]').forEach(button => { button.disabled = locked; });
    el('hf-download').disabled = locked || !unlocked || !el('hf-files').querySelector('input:checked');
    // Only a running job is another operation; a locked key or a pending submit keeps the usual note.
    setText('hf-download-note', unlocked && busy ? 'Another operation is running. Wait for it to finish.' :
        'VRAM use is unknown for searched files. Downloading never loads the model.');
    el('install-busy').hidden = !(unlocked && busy);
    el('preset-download').disabled = locked || !el('preset').value;
    el('run-test').disabled = locked || !el('loaded').value || Boolean(testRun);
    renderGuide();
}

// A pass needs a non-empty answer from the selected instance, newer than its load and not followed by a failed test.
function testPassed(state) {
    const job = state.job || {}, last = state.lastTest || {}, selected = el('loaded').value;
    if (!selected || last.model !== selected || (last.updated || 0) <= staleBefore || !String(last.output || '').trim()) return false;
    if (testRun || (job.action === 'test' && job.state === 'running') || testFailure?.model === selected) return false;
    return !(job.action === 'test' && job.state === 'failed' && job.model === selected && (job.updated || 0) > last.updated);
}

// The next step comes from live status only, so it moves back after an idle unload or a stopped server.
function nextStep(state) {
    const job = state.job || {}, list = libraryOf(state), instances = state.running ? instancesOf(state) : [];
    if (!state.installed) return {step: 'install', head: 'LLM Studio isn’t installed.', detail: 'In the launcher, open Components and install LLM Studio.'};
    if (job.state === 'running' && ['download', 'import'].includes(job.action)) return {step: 'model', head: job.action === 'import' ? 'Importing your model…' : 'Downloading your model…', detail: 'Progress is shown below.'};
    if (job.state === 'running' && ['start', 'restart', 'load'].includes(job.action)) return {step: 'load', head: job.action === 'load' ? 'Loading the model…' : 'Starting the server…', detail: ''};
    // A failed load stays the next step until its card is dismissed. Memory advice only for a reported memory error.
    if (job.action === 'load' && job.state === 'failed' && dismissedJob !== (job.id || `${job.action}:${job.updated}`)) return {step: 'load', head: 'Loading failed.',
        detail: /memory|\boom\b|alloc/i.test(String(job.message || '')) ? 'The error is shown below. Lower Context tokens or GPU offload %, or choose a smaller model, then press Load.' :
            'The error is shown below. Check the model and its settings, then press Load.', action: 'configure'};
    // A model this page just downloaded or imported comes before testing an older one; nothing unloads automatically.
    if (handoff) {
        const name = list.some(m => m.key === handoff.key) ? modelName(handoff.key) : handoff.name;
        if (!state.running) return {step: 'load', head: `${name} is installed.`, detail: 'Start the server to load it.', action: 'start'};
        if (instances.length >= maxLoaded()) return {step: 'load', head: `${name} is installed.`,
            detail: maxLoaded() > 1 ? 'Both positions are in use. Unload one in Loaded, then load it.' : 'A model is already loaded. Unload it in Loaded, then load this one.'};
        if (handoff.selected && el('model').value === handoff.key) return {step: 'load', head: `Load ${name}.`, detail: 'Check its settings, then press Load.', action: 'configure'};
        return {step: 'load', head: `${name} is installed.`, detail: 'Select it in Models, then press Load.', action: 'select'};
    }
    if (!instances.length) {
        const memory = 'It shares your PC’s memory and graphics memory with the game.';
        if (!list.length) return {step: 'model', head: 'Choose a model.', detail: state.running ? memory : `${memory} Already have models? Start the server to list them.`, action: 'choose'};
        if (!state.running) return {step: 'load', head: 'Start the server.', detail: 'Models load only while it runs.', action: 'start'};
        return {step: 'load', head: 'Load a model.', detail: 'Choose one in Models, check its settings, then press Load.', action: 'configure'};
    }
    const instance = instances.find(i => i.id === el('loaded').value) || instances[0];
    if (testRun || (job.action === 'test' && job.state === 'running')) {
        const tested = instances.find(i => i.id === (testRun?.model || job.model)) || instance;
        return {step: 'test', head: `Testing ${tested.name}…`, detail: ''};
    }
    if (testPassed(state)) return {step: 'ready', head: 'Ready.', detail: `${instance.name} answered its test.`, action: 'connect'};
    const failed = testFailure?.model === instance.id ||
        (job.action === 'test' && job.state === 'failed' && job.model === instance.id && (job.updated || 0) > staleBefore);
    if (failed) return {step: 'test', head: 'The test failed.', detail: 'See Test for details, then try again.', action: 'test', label: 'Test again'};
    return {step: 'test', head: `Test ${instance.name}.`, detail: 'Send a short prompt to check it answers.', action: 'test'};
}

const guideLabels = {choose: 'Choose model', start: 'Start server', configure: 'Configure and load', select: 'Select in Models', test: 'Test it', connect: 'Connect'};
const guideSteps = ['install', 'model', 'load', 'test'];

// One next action at a time. A finished job card that already offers it keeps the only button.
function renderGuide() {
    if (!current || !unlocked) { el('guide').hidden = true; el('connect').hidden = true; document.body.classList.remove('guide-leads'); return; }
    const next = nextStep(current), ready = next.step === 'ready', button = el('guide-action');
    el('guide').hidden = false; el('guide').dataset.step = next.step;
    el('guide-steps').hidden = ready;
    el('guide-steps').querySelectorAll('li').forEach(item => {
        const index = guideSteps.indexOf(item.dataset.step), at = guideSteps.indexOf(next.step);
        item.dataset.state = index < at ? 'done' : index === at ? 'current' : '';
        if (index === at) item.setAttribute('aria-current', 'step'); else item.removeAttribute('aria-current');
    });
    setText('guide-head', next.head); setText('guide-detail', next.detail);
    const label = next.label || guideLabels[next.action] || '';
    button.hidden = !label || (next.action === 'test' && !el('activity').hidden && !el('job-next').hidden);
    button.dataset.action = next.action || ''; setText('guide-action', label);
    button.disabled = next.action === 'start' && (busy || submitting);
    // While the guide offers the next action, the header Start and Install models look secondary; both stay usable.
    document.body.classList.toggle('guide-leads', !button.hidden);
    // Connect details describe only a passed test of the selected instance; anything older closes them.
    if (ready) { el('connect-model').value = el('loaded').value; el('connect-url').value = current.chatUrl || ''; }
    else el('connect').hidden = true;
    if (ready) button.setAttribute('aria-expanded', String(!el('connect').hidden)); else button.removeAttribute('aria-expanded');
    if (ready) button.setAttribute('aria-controls', 'connect'); else button.removeAttribute('aria-controls');
}

// Remember the exact key from this page's finished download or import, and select it once while the form is unedited.
function trackHandoff(state, list) {
    const job = state.job || {}, own = ownJobs.get(job.id);
    if (own && !own.handed && ['download', 'import'].includes(job.action) && job.state === 'completed') {
        own.handed = true;
        handoff = {job: job.id, key: typeof job.modelKey === 'string' ? job.modelKey : '', selected: false,
            name: (own.preset && presetName(own.preset)) || own.file || 'The new model'};
    }
    if (!handoff) return;
    if (handoff.key && loadedModel(handoff.key)) { handoff = null; return; }
    if (handoff.key && !handoff.selected && list.some(m => m.key === handoff.key) && formSnapshot() === filledForm) {
        handoff.selected = true; el('model').value = handoff.key;
    }
}

// Show only output from the current or latest finished test, never an older result as a new one.
function renderTest(state) {
    const job = state.job || {}, last = state.lastTest || {};
    const running = job.action === 'test' && job.state === 'running';
    if (testRun && running) testRun.seen = true;
    if (testRun && !testRun.sending && !running && (last.updated !== testRun.updated || testRun.seen || JSON.stringify(job) !== testRun.job)) {
        if (last.updated === testRun.updated) testFailure = {model: testRun.model, message: job.message || 'No response was returned.', after: last.updated || 0};
        testRun = null;
    }
    // A newer result for the failed instance, such as another session's test, replaces the failure.
    if (testFailure && last.model === testFailure.model && (last.updated || 0) > testFailure.after) testFailure = null;
    // A result belongs to the selected model and must be newer than its last load or unload.
    const selected = el('loaded').value, fresh = Boolean(selected) && last.model === selected && (last.updated || 0) > staleBefore;
    const failure = !running && !testRun && testFailure?.model === selected ? testFailure.message : '';
    // With two models loaded, a test in progress shows only under the model it tests.
    const tested = (running && job.model) || testRun?.model || '', here = !tested || tested === selected;
    let output = '', stats = '', note = '';
    if (running && here) { output = job.output || ''; note = 'Generating…'; }
    else if (testRun && here) note = 'Sending…';
    else if (running || testRun) note = 'Another loaded model is being tested.';
    else if (failure) note = `Test failed: ${failure}`;
    else if (fresh && last.output) { output = last.output; stats = last.elapsedSeconds ? `Response time: ${Number(last.elapsedSeconds).toFixed(1)} s` : ''; }
    else if (fresh && last.updated) note = emptyNote(job, last);
    else note = 'No response yet.';
    if (running && here && output) stats = 'Generating…';
    setText('output', output); setText('test-stats', output ? stats : '');
    setText('response-placeholder', note); el('response-placeholder').hidden = Boolean(output) || !note;
    el('response-placeholder').dataset.state = failure ? 'failed' : '';
    el('test-empty').hidden = Boolean(selected); el('loaded-row').hidden = !selected;
    setText('test-empty-text', !current || current.running ? 'No model loaded. Load one from Installed models to test it.' : 'Server stopped. Start it and load a model to test.');
    renderSlotTarget();
    updateControls();
}

// The helper explains an empty answer on the test job that wrote this result; older or other jobs say nothing about it.
function emptyNote(job, last) {
    const own = job.action === 'test' && job.state === 'completed' && job.model === last.model && job.elapsedSeconds === last.elapsedSeconds;
    return own && /reasoning/i.test(job.message || '') ?
        'No answer text. The model may have used its output limit on reasoning; turn reasoning off or raise max output tokens.' :
        'No answer text. The model returned an empty response.';
}

function gb(bytes) { return (bytes / 1073741824).toFixed(1); }

// The engine's memory locking limit in readable units; empty when unlimited or unknown.
function lockLimit(bytes) {
    if (typeof bytes !== 'number' || !Number.isFinite(bytes) || bytes < 0) return '';
    if (bytes >= 1073741824) return `${gb(bytes)} GiB`;
    if (bytes >= 1048576) return `${Math.floor(bytes / 1048576)} MiB`;
    return `${Math.floor(bytes / 1024)} KiB`;
}

// The library lists live models while running. Stopped, it lists the last confirmed inventory, which never says what is loaded.
function libraryOf(state) {
    if (!state) return [];
    if (state.running) return (state.models || []).filter(m => m.type === 'llm');
    return (Array.isArray(state.inventory) ? state.inventory : []).filter(m => m && m.key && (!m.type || m.type === 'llm'));
}

function modelName(key) { return libraryOf(current).find(m => m.key === key)?.display_name || key; }

// Use binary units consistently for model sizes and reported memory; empty when unknown.
function bytes(value) {
    if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) return '';
    if (value === 0) return '0 MiB';
    return value >= 1073741824 ? `${gb(value)} GiB` : `${Math.max(1, Math.round(value / 1048576))} MiB`;
}

// Optional metadata the engine reports; missing fields are skipped.
function modelMeta(model) {
    const quant = typeof model?.quantization === 'string' ? model.quantization : model?.quantization?.name;
    return [model?.publisher, bytes(model?.size_bytes), model?.params_string, quant].filter(v => typeof v === 'string' && v).join(' · ');
}

function checkedAgo(seconds) {
    if (typeof seconds !== 'number' || !Number.isFinite(seconds) || seconds <= 0) return '';
    const age = Math.max(0, Date.now() / 1000 - seconds);
    if (age < 60) return 'just now';
    if (age < 3600) return `${Math.floor(age / 60)} min ago`;
    if (age < 86400) return `${Math.floor(age / 3600)} h ago`;
    return `on ${new Date(seconds * 1000).toLocaleDateString()}`;
}

// An older helper omits maxLoaded and refuses any second load, so the manager then assumes one model.
function maxLoaded() { const max = current?.maxLoaded; return Number.isInteger(max) && max > 0 ? max : 1; }

// Friendly names in normal flow; the raw instance id stays available through Copy ID. Only the live list has instances.
function instancesOf(state) {
    const instances = (state.models || []).flatMap(m => (m.loaded_instances || []).map(i => ({id: i.id, model: m.key, name: m.display_name || i.id, config: i.config})));
    const named = instances.map(i => i.name);
    instances.forEach(i => { if (named.filter(name => name === i.name).length > 1) i.name = `${i.name} (${i.id})`; });
    return instances;
}

function instanceCount(key) { return current?.running ? current.models?.find(m => m.key === key)?.loaded_instances?.length || 0 : 0; }

function loadedModel(key) { return Boolean(key) && instanceCount(key) > 0; }

function setNodeText(node, text) { if (node.textContent !== text) node.textContent = text; }

function renderResources(resources) {
    const r = resources || {}, ram = bytes(r.ramAvailable), total = bytes(r.ramTotal);
    const stats = {'stat-ram': ram && total ? `${ram} of ${total}` : ram, 'stat-vram': bytes(r.vramFree)};
    for (const [id, text] of Object.entries(stats)) { setText(id, text || '—'); el(id).title = text ? '' : 'Not reported'; }
}

function libraryText(state, list) {
    if (!state.installed) return 'LLM Studio is not installed.';
    if (state.running) return list.length ? '' : 'No models installed yet. Download or import one to get started.';
    if (!list.length) return 'Start the server to discover installed models.';
    const ago = checkedAgo(state.inventoryUpdatedAt);
    return `${ago ? `Last checked ${ago}. ` : ''}Saved list from when the server last ran. Start the server to confirm it and load a model.`;
}

function modelRow(model) {
    const row = document.createElement('label'); row.className = 'model-row';
    const radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'library-model'; radio.value = model.key;
    const name = document.createElement('span'); name.className = 'model-name'; name.textContent = model.display_name || model.key;
    const meta = document.createElement('span'); meta.className = 'model-meta'; meta.textContent = modelMeta(model);
    const badge = document.createElement('span'); badge.className = 'badge'; badge.hidden = true;
    row.append(radio, name, meta, badge);
    return row;
}

// Rows rebuild only when the list changes, so polling never steals focus or a choice; badges and checks update in place.
function renderLibrary(state, list) {
    const library = el('library'), signature = JSON.stringify(list.map(m => [m.key, m.display_name, modelMeta(m)]));
    if (library.dataset.signature !== signature) {
        const focused = library.contains(document.activeElement) ? document.activeElement.value : null;
        library.querySelectorAll('.model-row').forEach(row => row.remove());
        library.append(...list.map(modelRow));
        library.dataset.signature = signature;
        if (focused) [...library.querySelectorAll('input')].find(radio => radio.value === focused)?.focus();
    }
    // A missing choice moves to a loaded model, else the first; an empty list keeps the choice and its unsaved form.
    if (list.length && !list.some(m => m.key === el('model').value)) el('model').value = (list.find(m => loadedModel(m.key)) || list[0]).key;
    library.querySelectorAll('.model-row').forEach(row => {
        const radio = row.querySelector('input'), badge = row.querySelector('.badge'), count = instanceCount(radio.value);
        radio.checked = radio.value === el('model').value;
        badge.hidden = !count; setNodeText(badge, count > 1 ? `Loaded ×${count}` : 'Loaded');
    });
    library.hidden = !list.length;
    const text = libraryText(state, list);
    setText('library-text', text); el('library-note').hidden = !text;
    el('library-note').dataset.state = !state.running && list.length ? 'cached' : '';
    setText('library-count', list.length ? `${list.length} ${state.running ? 'installed' : 'last seen'}` : '');
}

// The chosen model's heading and form; settings stay editable while stopped when the model is in the saved list.
function renderConfig(list) {
    const model = list.find(m => m.key === el('model').value);
    el('load-form').hidden = !model;
    if (!model) return;
    setText('config-title', model.display_name || model.key);
    const max = Number.isInteger(model.max_context_length) ? `up to ${model.max_context_length.toLocaleString()} context tokens` : '';
    setText('config-meta', [modelMeta(model), max].filter(Boolean).join(' · '));
}

function slotButton(action, label, instance, style = '') {
    const button = document.createElement('button'); button.type = 'button'; button.className = `small ${style}`.trim();
    button.textContent = label; button.dataset.slotAction = action; button.dataset.instance = instance.id;
    button.setAttribute('aria-label', `${label} ${instance.name}`);
    return button;
}

function slotItem(instance, state) {
    const item = document.createElement('li'), body = document.createElement('div'), title = document.createElement('strong');
    item.className = 'slot'; body.className = 'slot-body';
    if (!instance) {
        item.classList.add('empty'); title.textContent = 'Empty';
        const hint = document.createElement('span'); hint.className = 'muted';
        hint.textContent = state.running ? 'Choose a model and Load.' : 'Server stopped.';
        body.append(title, hint); item.append(body);
        return item;
    }
    item.dataset.instance = instance.id; title.textContent = instance.name;
    const id = document.createElement('code'); id.textContent = instance.id;
    const context = instance.config?.context_length ?? instance.config?.contextLength;
    const meta = [modelMeta(state.models.find(m => m.key === instance.model)), Number.isInteger(context) ? `${context.toLocaleString()} context` : ''].filter(Boolean).join(' · ');
    body.append(title, id);
    if (meta) { const span = document.createElement('span'); span.className = 'muted'; span.textContent = meta; body.append(span); }
    const actions = document.createElement('div'); actions.className = 'slot-actions';
    actions.append(slotButton('test', 'Test', instance), slotButton('unload', 'Unload', instance), slotButton('copy', 'Copy ID', instance, 'quiet'));
    item.append(body, actions);
    return item;
}

// Positions show live instances only; a stopped server has none, whatever the saved list says.
function renderSlots(state, instances) {
    const max = maxLoaded(), list = el('slots');
    setText('slots-count', `${instances.length}/${max}`);
    const signature = JSON.stringify([max, state.running, instances.map(i => [i.id, i.name, i.model, i.config?.context_length ?? i.config?.contextLength ?? null])]);
    if (list.dataset.signature !== signature) {
        const focused = list.contains(document.activeElement) ? document.activeElement.dataset : null;
        list.replaceChildren(...Array.from({length: Math.max(max, instances.length)}, (_, index) => slotItem(instances[index], state)));
        list.dataset.signature = signature;
        if (focused) [...list.querySelectorAll('button')].find(b => b.dataset.instance === focused.instance && b.dataset.slotAction === focused.slotAction)?.focus();
    }
    el('multi-note').hidden = max < 2;
    renderSlotTarget();
}

// The position whose model the Test panel targets is marked, so per-position Test and the Test model select agree.
function renderSlotTarget() {
    el('slots').querySelectorAll('.slot').forEach(item => {
        const target = Boolean(item.dataset.instance) && item.dataset.instance === el('loaded').value;
        item.classList.toggle('target', target);
        item.querySelector('[data-slot-action=test]')?.setAttribute('aria-pressed', String(target));
    });
}

function renderStartupSummary() {
    const startup = el('startup-model'), name = startup.value ? startup.selectedOptions[0]?.textContent || startup.value : '';
    let text = el('autostart').checked ? `Starts with DwemerDistro${name ? ` · loads ${name}` : ''}` : 'Manual start';
    if (engineDirty) text += ' · unsaved';
    setText('startup-summary', text);
}

function presetName(id) { return catalogModels.find(m => m.id === id)?.name?.split(' · ')[0] || ''; }

function phaseOf(job) { return typeof job.phase === 'string' ? job.phase.toLowerCase() : ''; }

// Titles name the model the user picked; jobs started elsewhere keep generic titles.
function jobTitle(job, own) {
    const name = own?.model ? modelName(own.model) : own?.preset ? presetName(own.preset) : own?.file || '', phase = phaseOf(job);
    if (job.state === 'failed') return failNames[job.action] || 'Operation failed';
    if (job.state === 'completed') return job.action === 'load' && name ? `${name} loaded` : doneNames[job.action] || 'Done';
    if (phase) {
        const title = phaseNames[phase] || phase.charAt(0).toUpperCase() + phase.slice(1).replace(/[-_]/g, ' ');
        return name && transferPhases.has(phase) ? `${title} ${name}` : title;
    }
    if (name && job.action === 'load') return `Loading ${name}`;
    if (name && job.action === 'download') return `Downloading ${name}`;
    return jobNames[job.action] || 'Working';
}

// Long CLI or engine output stays one click away; the card shows only its last meaningful line.
function jobSummary(message) {
    const lines = String(message || '').split('\n').map(line => line.trim()).filter(Boolean);
    const last = lines[lines.length - 1] || '';
    const line = /^(starting|done)\.*$/i.test(last) ? '' : last.length > 160 ? `${last.slice(0, 157)}…` : last;
    return {line, more: lines.length > 1 || last.length > 160};
}

// Show running jobs, failures until dismissed, and brief success for jobs started on this page.
function renderActivity(job) {
    const key = job.id || `${job.action}:${job.updated}`, own = ownJobs.get(job.id);
    const failed = job.state === 'failed', running = job.state === 'running';
    const done = job.state === 'completed' && Boolean(own) && job.action in doneNames;
    if (done) own.finished ??= Date.now();
    // A load success leads to Test only while that model is still loaded, so an idle unload retires it.
    const loadDone = done && job.action === 'load';
    if (loadDone) { if (loadedModel(own.model)) own.seenLoaded = true; else if (own.seenLoaded) own.gone = true; }
    const gone = loadDone && (own.gone || !loadedModel(own.model));
    const faded = gone || (done && !lastingDone.has(job.action) && Date.now() - own.finished > 4000);
    // The Test response pane already shows a running test of its model, so the card would only repeat it there.
    const shownInPane = running && job.action === 'test' && (!job.model || job.model === el('loaded').value);
    const show = dismissedJob !== key && !shownInPane && (running || failed || (done && !faded));
    el('activity').hidden = !show;
    if (!show) return;
    el('activity').dataset.state = failed ? 'failed' : done ? 'done' : 'running';
    setText('job-title', jobTitle(job, own));
    const message = done ? '' : String(job.message || ''), summary = jobSummary(message);
    // A successful load may still report that RAM locking is limited; keep that honest note on the card.
    const warning = loadDone && typeof job.memoryLockWarning === 'string' ? job.memoryLockWarning.trim() : '';
    // Short failures stay fully visible; long ones keep their last line visible and the full text below.
    const shortFailure = failed && message.length <= 400 && message.trim().split('\n').length <= 3;
    setText('job-detail', warning || (shortFailure ? message.trim() : summary.line));
    setText('job', message);
    el('job-log').hidden = shortFailure || !summary.more;
    el('job-dismiss').hidden = running; el('job-dismiss').dataset.job = key;
    el('job-next').hidden = !(done && job.action === 'load'); el('job-next').dataset.model = own?.model || '';
    // Percentages describe bytes transferred only; verifying and importing stay indeterminate.
    const phase = phaseOf(job);
    const transfer = running && job.total > 0 && (phase ? transferPhases.has(phase) : job.downloaded < job.total);
    el('progress').hidden = !running;
    el('job-detail').hidden = transfer || !el('job-detail').textContent;
    if (transfer) {
        el('progress').value = job.downloaded * 100 / job.total;
        // Decimal GB with the total's precision, so the amounts match the size shown on the model card.
        const digits = job.total < 1e10 ? 2 : 1;
        setText('job-amount', `${Math.floor(job.downloaded * 100 / job.total)}% · ${(job.downloaded / 1e9).toFixed(digits)} of ${(job.total / 1e9).toFixed(digits)} GB`);
    } else { el('progress').removeAttribute('value'); setText('job-amount', ''); }
}

function lock() {
    unlocked = false; needsUnlock = true; el('unlock').hidden = false; el('manager').hidden = true;
    setText('status', 'Locked'); el('status').dataset.state = 'locked'; updateControls();
}

async function api(action, data = {}, signal = undefined) {
    const auth = authGen;
    const response = await fetch('api/lmstudio.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({...data, action, csrf}), cache: 'no-store', signal});
    // The HTTP status rides on the error so callers can tell a rejected key from a temporary failure.
    const failure = message => Object.assign(new Error(message), {status: response.status});
    let result;
    try { result = await response.json(); } catch { throw failure(`Request failed (HTTP ${response.status}).`); }
    if (!response.ok) {
        if (response.status === 401 && auth === authGen && !authorizing) lock();
        throw failure(result.error || 'Request failed.');
    }
    return result;
}

function showErrors() {
    const text = [...new Set([errors.action, errors.poll].filter(Boolean))].join('\n');
    setText('error', text); el('error').hidden = !text;
}

function report(error) { errors.action = error.message; showErrors(); }

function clearReport() { errors.action = ''; showErrors(); }

function options(id, models, key, title) {
    const select = el(id), selected = select.value;
    const signature = JSON.stringify(models.map(m => [m[key], m[title]]));
    if (select.dataset.signature === signature) return;
    select.replaceChildren(...models.map(m => new Option(m[title] || m[key], m[key])));
    if (models.some(m => m[key] === selected)) select.value = selected;
    select.dataset.signature = signature;
}

function render(state) {
    current = state;
    unlocked = true; el('unlock').hidden = true; el('manager').hidden = false;
    setText('status', state.running ? 'Running' : state.installed ? 'Stopped' : 'Not installed');
    el('status').dataset.state = state.running ? 'running' : state.installed ? 'stopped' : 'missing';
    if (!engineDirty) el('autostart').checked = state.settings.autostart;
    el('endpoint').value = state.endpoint; el('chatUrl').value = state.chatUrl;
    renderResources(state.resources);
    // A restarting server or an older helper lists no models; the choice and unsaved form stay until a list returns.
    const llms = libraryOf(state);
    trackHandoff(state, llms);
    renderLibrary(state, llms);
    const instances = state.running ? instancesOf(state) : [];
    options('loaded', instances, 'id', 'name');
    const preferred = preferLoaded && instances.find(i => i.model === preferLoaded);
    if (preferred) { el('loaded').value = preferred.id; preferLoaded = ''; }
    // Results older than the latest model load, unload or idle unload belong to an earlier instance, even after a reload.
    if (['load', 'unload'].includes(state.job.action)) staleBefore = Math.max(staleBefore, state.job.updated || 0);
    if (typeof state.modelStateChangedAt === 'number') staleBefore = Math.max(staleBefore, state.modelStateChangedAt);
    const instanceIds = JSON.stringify(instances.map(i => i.id).sort()), loadRecord = JSON.stringify(state.lastLoad || {});
    // Only an advanced load writes a new record; a basic load leaves the previous one behind.
    const loadJob = state.job.action === 'load' && state.job.state === 'completed' ? `${state.job.id}:${state.job.updated}` : '';
    if (loadJob && loadJob !== seenLoadJob) { seenLoadJob = loadJob; staleLoad = state.job.appliedConfig ? '' : loadRecord; }
    // A result or failure is history once its instance leaves the list; other models loading or unloading leave it valid.
    if (state.lastTest?.model) seenTest = state.lastTest;
    if (seenTest && !instances.some(i => i.id === seenTest.model)) staleBefore = Math.max(staleBefore, seenTest.updated || 0);
    if (testFailure && !instances.some(i => i.id === testFailure.model)) testFailure = null;
    if (seenInstances !== null && seenInstances !== instanceIds) {
        // Applied settings of an unloaded model no longer describe whatever loads next.
        if (!instances.some(i => i.model === state.lastLoad?.model)) staleLoad = loadRecord;
    }
    seenInstances = instanceIds;
    const startupModels = [{key: '', display_name: 'None'}, ...llms];
    // Keep the saved choice and any unsaved choice listed, with their names, while the server lists no models.
    const startup = el('startup-model'), keep = [state.settings.startupModel, engineDirty ? startup.value : ''];
    for (const key of keep) {
        const name = [...startup.options].find(o => o.value === key)?.textContent;
        if (key && !startupModels.some(m => m.key === key)) startupModels.push({key, display_name: name});
    }
    options('startup-model', startupModels, 'key', 'display_name');
    if (!engineDirty) el('startup-model').value = state.settings.startupModel || '';
    if (shownModel !== el('model').value) selectModel(); else syncDefaults();
    el('defaults-conflict').hidden = savedSnapshot(el('model').value) === savedBase;
    el('sdk-note').hidden = Boolean(state.advancedAvailable);
    el('load-fields').querySelectorAll('input,select').forEach(input => input.disabled = !state.advancedAvailable);
    const limit = lockLimit(state.memoryLockLimit);
    setText('memlock-note', limit ? `Keep model in memory can lock at most ${limit} on this system.` : ''); el('memlock-note').hidden = !limit;
    const applied = state.lastLoad?.model === el('model').value && loadedModel(el('model').value) && loadRecord !== staleLoad;
    setText('model-details', JSON.stringify({model: llms.find(m => m.key === el('model').value),
        lastAdvancedLoad: applied ? state.lastLoad.appliedConfig : undefined, memoryLockWarning: applied ? state.lastLoad.memoryLockWarning : undefined}, null, 2));
    renderConfig(llms);
    renderSlots(state, instances);
    renderStartupSummary();
    busy = state.job.state === 'running';
    // A finished download may have installed a recommended model, so its card is checked again.
    if (state.job.action === 'download' && state.job.state === 'completed' && state.job.id !== catalogJob) { catalogJob = state.job.id; catalogReady = false; }
    renderActivity(state.job);
    renderTest(state);
}

// Results from a status request that started before a mutation are discarded and fetched again.
async function refresh() {
    if (document.hidden || authorizing) return;
    if (pending) { queued = true; return; }
    pending = true;
    const started = epoch, auth = authGen;
    try {
        const state = await api('status');
        if (auth !== authGen) queued = true;
        else {
            if (started === epoch) render(state); else queued = true;
            // An open manager makes a temporary key-check failure moot.
            if (unlocked && authError) { if (errors.action === authError) errors.action = ''; authError = ''; }
            errors.poll = ''; showErrors();
            if (unlocked) catalog();
        }
    } catch (error) {
        if (auth !== authGen) queued = true;
        // The key-check message already explains a lock that follows it.
        else { errors.poll = error.status === 401 && authError && errors.action === authError ? '' : error.message; showErrors(); }
    }
    finally { pending = false; if (queued) { queued = false; if (!needsUnlock && !authorizing) refresh(); } }
}

async function submit(action, data) {
    const job = await api(action, data);
    epoch++;
    if (job?.id) ownJobs.set(job.id, {model: data.model, preset: data.preset, file: data.file});
    if (['download', 'import'].includes(action)) handoff = null;
    if (job?.id && current) { busy = job.state === 'running'; current = {...current, job}; renderActivity(job); }
}

async function act(action, data = {}) {
    if (busy || submitting) return false;
    clearReport(); submitting = true; updateControls();
    try { await submit(action, data); return true; } catch (error) { report(error); return false; }
    finally { submitting = false; updateControls(); await refresh(); }
}

// The download list is optional: a failure is shown beside Download and retried later, never blocking the manager.
async function catalog() {
    if (catalogReady || catalogPending || Date.now() < catalogRetryAt) return;
    catalogPending = true;
    try {
        const result = await api('catalog'); options('preset', result.models, 'id', 'name');
        const recommended = Array.isArray(result.recommended) ? result.recommended : [];
        catalogModels = [...result.models, ...recommended]; renderRecommended(recommended);
        catalogReady = true; setText('preset-note', result.models.length ? '' : 'No suggested models. Use a custom model below.');
        setText('recommended-note', recommended.length ? '' : 'No recommended models in this DwemerDistro version. Update Distro or search Hugging Face.');
    } catch (error) {
        catalogRetryAt = Date.now() + 15000; setText('preset-note', `Suggested models unavailable: ${error.message}`);
        setText('recommended-note', `Recommended models unavailable: ${error.message}`);
    } finally {
        catalogPending = false; el('preset-note').hidden = !el('preset-note').textContent;
        el('recommended-note').hidden = !el('recommended-note').textContent; updateControls();
    }
}

// Download sizes use decimal GB, as Hugging Face lists them.
function downloadSize(value) { return Number.isInteger(value) && value > 0 ? `${(value / 1e9).toFixed(value < 1e10 ? 2 : 1)} GB` : 'Size unknown'; }

function span(className, text) { const node = document.createElement('span'); node.className = className; node.textContent = text; return node; }

// Cards say Installed only when the helper found the exact pinned file; otherwise Download stays explicit.
function renderRecommended(models) {
    const list = el('recommended'), focused = list.contains(document.activeElement) ? document.activeElement.dataset.preset : '';
    list.replaceChildren(...models.map(model => {
        const item = document.createElement('li'), title = document.createElement('h3'), purpose = document.createElement('p');
        item.className = 'card'; title.textContent = model.name; purpose.textContent = model.purpose || '';
        const [low, high] = Array.isArray(model.vramGB) ? model.vramGB : [];
        const vram = Number.isFinite(low) && Number.isFinite(high) ? `~${low}–${high} GB VRAM, estimated at 4K` : 'VRAM unknown';
        item.append(span('tier', model.tier || ''), title, purpose,
            span('card-facts', `${model.quant || ''} · ${downloadSize(model.bytes)} download`), span('card-facts', vram));
        if (model.installed === true) item.append(span('badge', 'Installed'));
        else {
            const button = document.createElement('button'); button.type = 'button'; button.className = 'primary small';
            button.textContent = 'Download'; button.dataset.preset = model.id; button.setAttribute('aria-label', `Download ${model.name}`);
            item.append(button);
        }
        return item;
    }));
    if (focused) list.querySelector(`[data-preset="${CSS.escape(focused)}"]`)?.focus();
}

const installTabs = ['recommended', 'search'];

function showTab(name, focus = false) {
    for (const tab of installTabs) {
        const button = el(`tab-${tab}`), selected = tab === name;
        button.setAttribute('aria-selected', String(selected)); button.tabIndex = selected ? 0 : -1;
        el(`${tab}-panel`).hidden = !selected;
    }
    if (focus) el(`tab-${name}`).focus();
    // Browsing starts the first time the tab is shown, never when the dialog opens on Recommended.
    if (name === 'search' && !hf.state && !hf.controller && el('hf-files-view').hidden) hfSearch();
}

function hfMessage(status, error = '') {
    setText(el('hf-files-view').hidden ? 'hf-status' : 'hf-files-status', status); setText('hf-error', error); el('hf-error').hidden = !error;
}

// Earlier results stay visible but marked busy while a lookup runs; Show more waits for it.
function hfBusy(on) {
    ['hf-results', 'hf-files'].forEach(id => el(id).setAttribute('aria-busy', String(on)));
    el('hf-more').disabled = on;
}

// Abort any earlier lookup; a response is used only if no newer lookup, Back or close happened meanwhile.
async function hfRequest(action, data) {
    hf.controller?.abort();
    const controller = new AbortController(), seq = ++hf.seq;
    hf.controller = controller;
    try {
        const result = await api(action, data, controller.signal);
        return seq === hf.seq ? result : null;
    } catch (error) {
        if (seq === hf.seq && error.name !== 'AbortError') hfMessage('', error.message);
        return null;
    } finally {
        if (hf.controller === controller) hf.controller = null;
        if (seq === hf.seq) hfBusy(false);
    }
}

function hfCancel() { if (hf.controller) hfMessage(''); hf.controller?.abort(); hf.seq++; hfBusy(false); }

function hfView(files) { el('hf-results-view').hidden = files; el('hf-files-view').hidden = !files; }

function hfForm() {
    return {query: el('hf-query').value.trim(), author: el('hf-author').value.trim(), size: el('hf-size').value,
        task: el('hf-task').value, sort: el('hf-sort').value};
}

function hfSummary() {
    if (!hf.state) return '';
    const count = hf.ids.size, what = hf.state.query ? `GGUF results for “${hf.state.query}”` : `${hfSorts[hf.state.sort]} GGUF repositories`;
    if (!count) return 'No GGUF repositories match. Try another name or creator, or reset filters.';
    return `${what}: ${count} shown${hf.cursor ? ', more available' : ''}. Choose one to see its files.`;
}

// Parameters, architecture and context come only from Hugging Face's GGUF metadata; anything unknown is left out.
function hfItem(repo) {
    const format = new Intl.NumberFormat(undefined, {notation: 'compact'});
    const params = !Number.isInteger(repo.params) ? '' : repo.params >= 1e9 ?
        `${(repo.params / 1e9).toFixed(repo.params < 1e10 ? 1 : 0)}B params` : `${Math.max(1, Math.round(repo.params / 1e6))}M params`;
    const facts = [params, repo.architecture, Number.isInteger(repo.context) && `${Math.round(repo.context / 1024)}K context`];
    const meta = [`${format.format(repo.downloads)} downloads`, `${format.format(repo.likes)} likes`, repo.updated && `updated ${repo.updated}`, repo.gated && 'Gated'];
    const item = document.createElement('li'), button = document.createElement('button');
    button.type = 'button'; button.className = 'hf-repo'; button.dataset.repo = repo.id;
    button.append(span('model-name', repo.id), span('model-meta', facts.filter(Boolean).join(' · ')), span('model-meta', meta.filter(Boolean).join(' · ')));
    item.append(button);
    return item;
}

// New searches replace the list; Show more appends the next cursor page and skips repositories already shown.
function hfRender(state, result, append) {
    if (!append) { hf.ids = new Set(); el('hf-results').replaceChildren(); }
    hf.state = state; hf.cursor = typeof result.cursor === 'string' ? result.cursor : null;
    const fresh = result.results.filter(repo => !hf.ids.has(repo.id));
    fresh.forEach(repo => hf.ids.add(repo.id));
    const items = fresh.map(hfItem);
    el('hf-results').append(...items);
    el('hf-more').hidden = !hf.cursor;
    hfMessage(hfSummary());
    if (append) items[0]?.querySelector('button').focus();
}

// Text is applied by Search; dropdowns apply at once. First pages are kept for ten minutes per filter state.
async function hfSearch(more = false) {
    const state = more ? hf.state : hfForm();
    if (more && (!state || !hf.cursor)) return;
    if (!more && /^https?:\/\//i.test(state.query)) { hfOpen(state.query); return; }
    if (state.query.length === 1) { hfMessage('', 'Enter at least 2 characters, or leave the search empty to browse.'); el('hf-query').focus(); return; }
    const key = JSON.stringify(state), saved = !more && hf.first.get(key);
    hfView(false);
    if (saved && Date.now() - saved.at < 600000) { hfCancel(); hfRender(state, saved.result, false); return; }
    hfMessage(more ? 'Loading more…' : 'Searching…');
    const request = hfRequest('search', more ? {...state, cursor: hf.cursor} : state);
    hfBusy(true);
    const result = await request;
    if (!result) return;
    if (!more) {
        hf.first.delete(key); hf.first.set(key, {at: Date.now(), result});
        if (hf.first.size > 20) hf.first.delete(hf.first.keys().next().value);
    }
    hfRender(state, result, more);
}

// File filters narrow only this repository's listed files; a hidden choice is cleared so it cannot be downloaded.
function hfApplyFiles() {
    if (!hf.files) return;
    const quant = el('hf-quant').value, sort = el('hf-file-sort').value, max = Number(el('hf-max').value);
    const limit = el('hf-max').value && max > 0 ? max * 1e9 : Infinity;
    const size = file => file.size ?? Infinity, byName = (a, b) => a.file.name.localeCompare(b.file.name);
    const order = {name: byName, 'size-desc': (a, b) => (b.file.size ?? -1) - (a.file.size ?? -1) || byName(a, b),
        size: (a, b) => size(a.file) - size(b.file) || byName(a, b)}[sort] || byName;
    let shown = 0;
    for (const row of [...el('hf-files').querySelectorAll('.file-row')].sort(order)) {
        row.hidden = !((!quant || row.file.quant === quant) && (limit === Infinity || size(row.file) <= limit));
        if (row.hidden) row.querySelector('input').checked = false; else shown++;
        el('hf-files').append(row);
    }
    const files = hf.files.files, usable = files.filter(file => file.selectable).length;
    let message = `Showing ${shown} of ${files.length} GGUF files in this repository; ${usable} standalone at revision ${hf.files.revision.slice(0, 7)}.`;
    if (!files.length) message = 'This repository has no GGUF files.';
    else if (!usable) message = 'No standalone GGUF file here can be downloaded. Choose another repository.';
    else if (!shown) message = 'No files in this repository match these file filters. Reset file filters.';
    hfMessage(message + (files.length && hf.files.truncated ? ' Only the first 300 files are listed.' : ''));
    updateControls();
}

function hfResetFiles() { el('hf-quant').value = ''; el('hf-max').value = ''; el('hf-file-sort').value = 'size'; }

// Only standalone GGUF files can be chosen; split parts, projectors and gated files say why not.
async function hfOpen(repo) {
    if (!el('hf-results-view').hidden) hf.scroll = el('install-dialog').scrollTop;
    hf.files = null; hfView(true); hfMessage('Loading files…'); el('hf-back').focus();
    setText('hf-repo', repo.replace(/^https:\/\/huggingface\.co\//i, '')); el('hf-link').hidden = true;
    el('hf-files').querySelectorAll('.file-row').forEach(row => row.remove()); el('hf-file-filters').hidden = true;
    el('hf-quant').replaceChildren(new Option('All', '')); hfResetFiles(); updateControls();
    const request = hfRequest('files', {repo});
    hfBusy(true);
    const result = await request;
    if (!result) return;
    hf.files = result; setText('hf-repo', result.repo);
    el('hf-link').href = `https://huggingface.co/${result.repo.split('/').map(encodeURIComponent).join('/')}`; el('hf-link').hidden = false;
    el('hf-files').append(...result.files.map(file => {
        const row = document.createElement('label'); row.className = 'model-row file-row'; row.file = file;
        const radio = document.createElement('input'); radio.type = 'radio'; radio.name = 'hf-file'; radio.value = file.name;
        radio.disabled = !file.selectable || file.installed;
        const details = [file.quant || 'Quant unknown', downloadSize(file.size), file.selectable ? 'VRAM Unknown' : file.reason];
        row.append(radio, span('model-name', file.name), span('model-meta', details.join(' · ')));
        if (Number.isInteger(file.size)) row.title = `${file.size.toLocaleString()} bytes`;
        if (file.installed) row.append(span('badge', 'Installed'));
        if (radio.disabled) row.setAttribute('aria-disabled', 'true');
        return row;
    }));
    const quants = [...new Set(result.files.map(file => file.quant).filter(Boolean))].sort();
    el('hf-quant').append(...quants.map(quant => new Option(quant, quant)));
    el('hf-file-filters').hidden = result.files.length < 2;
    hfApplyFiles();
}

// The key is sent once; afterwards status polling opens the manager and loads the download list.
// A rejected key locks. A temporary failure falls back to status, which still opens a valid or local session.
async function unlock(token) {
    if (authorizing) return;
    authorizing = true; authGen++;
    try { await api('authorize', {token}); el('token').value = ''; authError = ''; clearReport(); }
    catch (error) {
        report(error);
        if (error.status === 401 || error.status === 403) { authError = ''; errors.poll = ''; showErrors(); lock(); return; }
        authError = error.message;
    }
    finally { authorizing = false; }
    needsUnlock = false; errors.poll = ''; showErrors();
    await refresh();
}

function focusLibrary() { (el('library').querySelector('input:checked') || el('library').querySelector('input'))?.focus(); }

// Explicit next step after a load or a position's Test; polling never moves focus on its own.
function gotoTest(model, instanceId = '') {
    const instance = instanceId || (model && current?.models.find(m => m.key === model)?.loaded_instances?.[0]?.id);
    if (instance) el('loaded').value = instance; else if (model) preferLoaded = model;
    if (current) { renderActivity(current.job); renderTest(current); }
    (el('run-test').disabled ? el('prompt') : el('run-test')).focus();
}
el('job-next').addEventListener('click', () => {
    dismissedJob = el('job-dismiss').dataset.job; el('activity').hidden = true; gotoTest(el('job-next').dataset.model);
});
el('engine-toggle').addEventListener('click', () => { if (current) act(current.running ? 'stop' : 'start'); });
document.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', () => act(button.dataset.action)));
async function copyText(text, button) {
    try {
        if (navigator.clipboard) await navigator.clipboard.writeText(text);
        else { const copy = document.createElement('textarea'); copy.value = text; document.body.append(copy); copy.select(); document.execCommand('copy'); copy.remove(); }
        button.dataset.label ??= button.textContent; button.textContent = 'Copied';
        clearTimeout(button.copyTimer); button.copyTimer = setTimeout(() => { button.textContent = button.dataset.label; }, 1500);
    } catch (error) { report(error); }
}
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', () => copyText(el(button.dataset.copy).value, button)));
// Position buttons carry their own instance id, so Test, Unload and Copy ID act on that instance whichever model is chosen.
el('slots').addEventListener('click', event => {
    const button = event.target.closest('button[data-slot-action]');
    if (!button) return;
    const instance = button.dataset.instance;
    if (button.dataset.slotAction === 'test') gotoTest('', instance);
    else if (button.dataset.slotAction === 'unload') act('unload', {model: instance});
    else copyText(instance, button);
});
// Choosing a model only fills its settings; nothing loads until Load is pressed.
el('library').addEventListener('change', event => {
    if (event.target.name !== 'library-model') return;
    el('model').value = event.target.value; handoff = null;
    selectModel(); if (current) renderConfig(libraryOf(current)); updateControls(); refresh();
});
function installMessage(error) { setText('install-error', error); el('install-error').hidden = !error; }

// The native modal dialog keeps focus inside and closes on Escape. Every opening starts on Recommended.
function openInstall(opener) {
    installOpener = opener; installMessage('');
    showTab('recommended'); el('install-dialog').showModal(); el('tab-recommended').focus(); updateControls();
}

// An accepted job closes the dialog so its progress shows on the page; a refusal stays here with its reason and the input kept.
async function install(action, data, showError = installMessage) {
    showError('');
    if (await act(action, data)) el('install-dialog').close();
    else if (errors.action) showError(errors.action);
}
el('open-install').addEventListener('click', () => openInstall(el('open-install')));
el('install-close').addEventListener('click', () => el('install-dialog').close());
// Focus returns to the opener, or to Install models when the opener has since been hidden.
el('install-dialog').addEventListener('close', () => {
    hfCancel();
    // A repository whose files never loaded is not kept; the next visit returns to the results.
    if (!hf.files && !el('hf-files-view').hidden) { hfView(false); hfMessage(hfSummary()); }
    (installOpener?.isConnected && !installOpener.hidden ? installOpener : el('open-install')).focus();
    installOpener = null;
});
document.querySelectorAll('#install-dialog [role=tab]').forEach(tab => {
    tab.addEventListener('click', () => showTab(tab.id.slice(4)));
    tab.addEventListener('keydown', event => {
        const index = installTabs.indexOf(tab.id.slice(4));
        const next = {ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: installTabs.length - 1}[event.key];
        if (next === undefined) return;
        event.preventDefault(); showTab(installTabs[(next + installTabs.length) % installTabs.length], true);
    });
});
el('recommended').addEventListener('click', event => {
    const button = event.target.closest('button[data-preset]');
    if (button) install('download', {preset: button.dataset.preset});
});
el('hf-form').addEventListener('submit', event => { event.preventDefault(); hfSearch(); });
['hf-size', 'hf-task', 'hf-sort'].forEach(id => el(id).addEventListener('change', () => hfSearch()));
el('hf-reset').addEventListener('click', () => {
    el('hf-author').value = ''; el('hf-size').value = ''; el('hf-task').value = ''; el('hf-sort').value = 'trending'; hfSearch();
});
el('hf-more').addEventListener('click', () => hfSearch(true));
el('hf-results').addEventListener('click', event => { const button = event.target.closest('button[data-repo]'); if (button) hfOpen(button.dataset.repo); });
// Back keeps the results, filters and scroll position; it browses only if no results were ever shown.
el('hf-back').addEventListener('click', () => {
    const repo = hf.files?.repo || el('hf-repo').textContent;
    hfCancel(); hfView(false); el('install-dialog').scrollTop = hf.scroll;
    if (!hf.state && !/^https?:\/\//i.test(el('hf-query').value.trim())) hfSearch(); else hfMessage(hfSummary());
    (el('hf-results').querySelector(`[data-repo="${CSS.escape(repo)}"]`) || el('hf-query')).focus({preventScroll: true});
});
['hf-quant', 'hf-file-sort'].forEach(id => el(id).addEventListener('change', hfApplyFiles));
el('hf-max').addEventListener('input', hfApplyFiles);
el('hf-file-reset').addEventListener('click', () => { hfResetFiles(); hfApplyFiles(); });
el('hf-files').addEventListener('change', updateControls);
el('hf-download').addEventListener('click', async () => {
    const file = el('hf-files').querySelector('input:checked')?.value;
    if (!file || !hf.files) return;
    install('download', {repo: hf.files.repo, file}, error => { setText('hf-error', error); el('hf-error').hidden = !error; });
});
el('job-dismiss').addEventListener('click', () => {
    dismissedJob = el('job-dismiss').dataset.job; el('activity').hidden = true;
    if (handoff && dismissedJob === handoff.job) handoff = null;
    if (current) renderGuide();
    el('workspace').focus();
});
// Guide actions only move focus or open the existing dialog; Start server is the one mutation and stays explicit.
el('guide-action').addEventListener('click', () => {
    const action = el('guide-action').dataset.action;
    if (action === 'choose') openInstall(el('guide-action'));
    else if (action === 'start') act('start');
    else if (action === 'configure') el('load-form').hidden ? focusLibrary() : el('load-form').focus();
    else if (action === 'select') focusLibrary();
    else if (action === 'test') gotoTest('', el('loaded').value);
    else if (action === 'connect') { el('connect').hidden = !el('connect').hidden; renderGuide(); }
});
el('unlock-form').addEventListener('submit', event => { event.preventDefault(); unlock(el('token').value); });
el('autostart').addEventListener('change', () => { engineDirty = true; renderStartupSummary(); });
el('startup-model').addEventListener('change', () => { engineDirty = true; renderStartupSummary(); });
// Edits made while the save is pending stay dirty so polling does not overwrite them.
el('save-engine').addEventListener('click', async () => {
    const saved = {autostart: el('autostart').checked, startupModel: el('startup-model').value};
    if (await act('settings', saved) && el('autostart').checked === saved.autostart && el('startup-model').value === saved.startupModel) engineDirty = false;
});
el('preset-form').addEventListener('submit', event => { event.preventDefault(); if (el('preset').value) install('download', {preset: el('preset').value}); });
el('custom-form').addEventListener('submit', event => { event.preventDefault(); install('download', {source: el('source').value.trim()}); });
el('import-form').addEventListener('submit', event => { event.preventDefault(); install('import', {source: el('local-path').value.trim()}); });
el('load-form').addEventListener('submit', event => {
    event.preventDefault(); if (!validLoad()) return;
    try { const values = loadValues(); if (basicLoad()) values.advanced = {}; act('load', values); } catch (error) { report(error); }
});
el('reset-load').addEventListener('click', () => { selectModel(true); updateControls(); });
el('use-latest').addEventListener('click', () => { selectModel(); el('defaults-conflict').hidden = true; updateControls(); el('save-defaults').focus(); });
// Unavailable advanced fields keep their saved values, so saving other settings never erases them.
el('save-defaults').addEventListener('click', () => {
    if (!validLoad() || !el('defaults-conflict').hidden) return;
    try {
        const values = loadValues(), saving = {model: values.model, snapshot: snapshotOf(values)};
        ownSaved = saving;
        act('model-defaults', values).then(ok => { if (!ok && ownSaved === saving) ownSaved = null; });
    } catch (error) { report(error); }
});
el('loaded').addEventListener('change', () => { if (current) { renderActivity(current.job); renderTest(current); } });
el('test-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || submitting || testRun) return;
    const model = el('loaded').value, prompt = el('prompt').value.trim();
    if (!model) { report(new Error('Load a model before running the test.')); return; }
    if (!prompt) { report(new Error('Enter a test prompt.')); return; }
    clearReport(); testFailure = null;
    testRun = {model, updated: current.lastTest?.updated, job: JSON.stringify(current.job || {}), seen: false, sending: true};
    submitting = true;
    renderTest(current);
    try { await submit('test', {model, prompt, generation: {system_prompt: testSystemPrompt}}); }
    catch (error) { submitting = false; testRun = null; testFailure = {model, message: error.message, after: current.lastTest?.updated || 0}; report(error); renderTest(current); return; }
    submitting = false; testRun.sending = false;
    await refresh();
});
document.addEventListener('visibilitychange', () => { if (!document.hidden && !needsUnlock) refresh(); });
setInterval(() => { if (!needsUnlock) refresh(); }, 2000);
const token = new URLSearchParams(location.hash.slice(1)).get('token');
history.replaceState(null, '', location.pathname);
if (token) unlock(token);
else refresh();
