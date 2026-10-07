'use strict';
const el = id => document.getElementById(id);
const csrf = document.querySelector('meta[name="lmstudio-csrf"]').content;
let unlocked = false, pending = false, queued = false, busy = false, submitting = false;
let current = null, shownModel = null, engineDirty = false, tabChosen = false, dismissedJob = null, epoch = 0;
let testRun = null, testFailure = null, pollError = false, staleBefore = 0, preferLoaded = '';
// Loaded instances seen by the previous poll, and a last-load record known to describe an earlier load.
let seenInstances = null, seenLoadJob = '', staleLoad = '';
// Saved defaults the form was filled from, the form as filled, and the last defaults this page saved.
let savedBase = '', filledForm = '', ownSaved = '';
// Jobs submitted from this page; only these get a success message.
const ownJobs = new Map();
const loadFields = {
    flashAttention: ['Flash Attention', 'bool'], offloadKVCacheToGpu: ['Offload KV cache to GPU', 'bool'],
    gpuStrictVramCap: ['Strict GPU memory limit', 'bool'], keepModelInMemory: ['Keep model in RAM', 'bool'],
    tryMmap: ['Memory mapping (mmap)', 'bool'], evalBatchSize: ['Evaluation batch size', 1, 2048, 1],
    seed: ['Random seed (next load)', 0, 4294967295, 1],
    llamaKCacheQuantizationType: ['K cache precision', 'cache'], llamaVCacheQuantizationType: ['V cache precision', 'cache'],
    ropeFrequencyBase: ['RoPE frequency base', 0, 10000000, 'any'], ropeFrequencyScale: ['RoPE frequency scale', .01, 100, 'any']
};
// Matches the CHIM connector greeting test; the helper applies its own generation defaults.
const testSystemPrompt = 'This is an isolated connection test, not a game scene. Respond with a short greeting. Do not request any action.';
const jobNames = {start: 'Starting server', stop: 'Stopping server', restart: 'Restarting server', settings: 'Saving settings',
    download: 'Downloading', load: 'Loading model', unload: 'Unloading model', test: 'Testing', 'model-defaults': 'Saving defaults'};
const failNames = {start: 'Start failed', stop: 'Stop failed', restart: 'Restart failed', settings: 'Save failed',
    download: 'Download failed', load: 'Load failed', unload: 'Unload failed', test: 'Test failed', 'model-defaults': 'Save failed'};
const doneNames = {load: 'Model loaded', unload: 'Model unloaded', download: 'Download complete', settings: 'Settings saved', 'model-defaults': 'Defaults saved'};
// Load and download successes lead somewhere next, so they stay until dismissed; saves fade after a few seconds.
const lastingDone = new Set(['load', 'download']);
// The helper may report an optional job phase. Only transfer phases show a percentage.
const transferPhases = new Set(['download', 'downloading', 'transfer', 'resume', 'resuming']);
const phaseNames = {download: 'Downloading', downloading: 'Downloading', transfer: 'Downloading', resume: 'Resuming download',
    resuming: 'Resuming download', verify: 'Verifying download', verifying: 'Verifying download', import: 'Importing model',
    importing: 'Importing model', retry: 'Retrying', retrying: 'Retrying'};
const tabs = [...document.querySelectorAll('[role=tab]')];

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
        else if (latest === ownSaved) savedBase = latest;
    }
}

function selectTab(name, focus = false) {
    tabChosen = true;
    for (const tab of tabs) {
        const selected = tab.dataset.tab === name;
        tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1;
        el(tab.getAttribute('aria-controls')).hidden = !selected;
        if (selected && focus) tab.focus();
    }
}

function setText(id, text) { if (el(id).textContent !== text) el(id).textContent = text; }

// Mutations stay disabled while a request or job runs; navigation, disclosures and copy stay usable.
function updateControls() {
    const locked = busy || submitting, running = Boolean(current?.running), installed = Boolean(current?.installed);
    const toggle = el('engine-toggle');
    setText('engine-toggle', running ? 'Stop' : 'Start');
    toggle.setAttribute('aria-label', running ? 'Stop server' : 'Start server');
    toggle.classList.toggle('primary', !running);
    toggle.disabled = locked || !unlocked || !current || !installed;
    el('restart').disabled = locked || !installed || !running;
    // The helper refuses a second load, so Load stays off while any model is loaded.
    el('load-button').disabled = locked || !running || !el('model').value || hasLoaded();
    setText('load-button', basicLoad() ? 'Load basic' : 'Load');
    el('save-defaults').disabled = locked || !el('model').value || !el('defaults-conflict').hidden;
    el('unload').disabled = el('notice-unload').disabled = locked || !el('loaded').value;
    ['save-engine', 'preset-download', 'custom-download'].forEach(id => { el(id).disabled = locked; });
    el('run-test').disabled = locked || !el('loaded').value || Boolean(testRun);
}

// Show only output from the current or latest finished test, never an older result as a new one.
function renderTest(state) {
    const job = state.job || {}, last = state.lastTest || {};
    const running = job.action === 'test' && job.state === 'running';
    if (testRun && running) testRun.seen = true;
    if (testRun && !testRun.sending && !running && (last.updated !== testRun.updated || testRun.seen || JSON.stringify(job) !== testRun.job)) {
        if (last.updated === testRun.updated) testFailure = {model: testRun.model, message: job.message || 'No response was returned.'};
        testRun = null;
    }
    // A result belongs to the selected model and must be newer than its last load or unload.
    const selected = el('loaded').value, fresh = Boolean(selected) && last.model === selected && (last.updated || 0) > staleBefore;
    const failure = !running && !testRun && testFailure?.model === selected ? testFailure.message : '';
    let output = '', stats = '', note = '';
    if (running) { output = job.output || ''; note = 'Generating…'; }
    else if (testRun) note = 'Sending…';
    else if (failure) note = `Test failed: ${failure}`;
    else if (fresh && last.output) { output = last.output; stats = last.elapsedSeconds ? `Response time: ${Number(last.elapsedSeconds).toFixed(1)} s` : ''; }
    else if (fresh && last.updated) note = 'The model returned an empty response.';
    else note = 'No response yet.';
    if (running && output) stats = 'Generating…';
    setText('output', output); setText('test-stats', output ? stats : '');
    setText('response-placeholder', note); el('response-placeholder').hidden = Boolean(output) || !note;
    el('response-placeholder').dataset.state = failure ? 'failed' : '';
    el('test-empty').hidden = Boolean(selected); el('loaded-row').hidden = !selected;
    setText('test-empty-text', !current || current.running ? 'No model loaded.' : 'Server stopped.');
    updateControls();
}

function gb(bytes) { return (bytes / 1073741824).toFixed(1); }

// The engine's RAM locking limit in readable units; empty when unlimited or unknown.
function lockLimit(bytes) {
    if (typeof bytes !== 'number' || !Number.isFinite(bytes) || bytes < 0) return '';
    if (bytes >= 1073741824) return `${gb(bytes)} GB`;
    if (bytes >= 1048576) return `${Math.floor(bytes / 1048576)} MB`;
    return `${Math.floor(bytes / 1024)} KB`;
}

function modelName(key) { return current?.models.find(m => m.key === key)?.display_name || key; }

function hasLoaded() { return el('loaded').options.length > 0; }

function loadedModel(key) { return Boolean(key) && Boolean(current?.models.find(m => m.key === key)?.loaded_instances?.length); }

function presetName(id) { return [...el('preset').options].find(o => o.value === id)?.textContent.split(' · ')[0] || ''; }

function phaseOf(job) { return typeof job.phase === 'string' ? job.phase.toLowerCase() : ''; }

// Titles name the model the user picked; jobs started elsewhere keep generic titles.
function jobTitle(job, own) {
    const name = own?.model ? modelName(own.model) : own?.preset ? presetName(own.preset) : '', phase = phaseOf(job);
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
    const show = dismissedJob !== key && (running || failed || (done && !faded));
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
        setText('job-amount', `${Math.floor(job.downloaded * 100 / job.total)}% · ${gb(job.downloaded)} of ${gb(job.total)} GB`);
    } else { el('progress').removeAttribute('value'); setText('job-amount', ''); }
}

async function api(action, data = {}) {
    const response = await fetch('api/lmstudio.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({...data, action, csrf}), cache: 'no-store'});
    let result;
    try { result = await response.json(); } catch { throw new Error(`Request failed (HTTP ${response.status}).`); }
    if (!response.ok) {
        if (response.status === 401) {
            unlocked = false; el('unlock').hidden = false; el('manager').hidden = true;
            setText('status', 'Locked'); el('status').dataset.state = 'locked'; updateControls();
        }
        throw new Error(result.error || 'Request failed.');
    }
    return result;
}

function report(error) { el('error').textContent = error.message; el('error').hidden = false; pollError = false; }

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
    const llms = state.models.filter(m => m.type === 'llm');
    options('model', llms, 'key', 'display_name');
    // Friendly names in normal flow; the raw instance id stays available through Copy ID.
    const instances = state.models.flatMap(m => (m.loaded_instances || []).map(i => ({id: i.id, model: m.key, name: m.display_name || i.id})));
    const named = instances.map(i => i.name);
    instances.forEach(i => { if (named.filter(name => name === i.name).length > 1) i.name = `${i.name} (${i.id})`; });
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
    if (seenInstances !== null && seenInstances !== instanceIds) {
        staleBefore = Math.max(staleBefore, state.lastTest?.updated || 0); testFailure = null;
        // Applied settings of an unloaded model no longer describe whatever loads next.
        if (!instances.some(i => i.model === state.lastLoad?.model)) staleLoad = loadRecord;
    }
    seenInstances = instanceIds;
    const startupModels = [{key: '', display_name: 'None'}, ...llms];
    if (state.settings.startupModel && !startupModels.some(m => m.key === state.settings.startupModel)) startupModels.push({key: state.settings.startupModel});
    options('startup-model', startupModels, 'key', 'display_name');
    if (!engineDirty) el('startup-model').value = state.settings.startupModel || '';
    if (shownModel !== el('model').value) selectModel(); else syncDefaults();
    el('defaults-conflict').hidden = savedSnapshot(el('model').value) === savedBase;
    setText('sdk-note', state.advancedAvailable ? '' : basicLoad() ?
        'Advanced loading is unavailable. Load basic skips these settings; your saved values are kept.' :
        'Reinstall the LLM Studio component to enable advanced loading.');
    el('sdk-note').hidden = Boolean(state.advancedAvailable);
    el('load-fields').querySelectorAll('input,select').forEach(input => input.disabled = !state.advancedAvailable);
    const limit = lockLimit(state.memoryLockLimit);
    setText('memlock-note', limit ? `RAM locking is limited to ${limit} on this system.` : ''); el('memlock-note').hidden = !limit;
    const applied = state.lastLoad?.model === el('model').value && loadedModel(el('model').value) && loadRecord !== staleLoad;
    setText('model-details', JSON.stringify({model: state.models.find(m => m.key === el('model').value),
        lastAdvancedLoad: applied ? state.lastLoad.appliedConfig : undefined, memoryLockWarning: applied ? state.lastLoad.memoryLockWarning : undefined}, null, 2));
    const listed = state.running && llms.length > 0;
    setText('model-note', state.running ? 'No models yet.' : 'Start the server to see models.');
    el('model-note').hidden = listed; el('load-form').hidden = !listed;
    el('loaded-note').hidden = !state.running || !instances.length;
    setText('loaded-name', instances.length > 1 ? `${instances.length} models` : instances[0]?.name || '');
    busy = state.job.state === 'running';
    if (!tabChosen) selectTab(el('loaded').value ? 'test' : 'models');
    renderActivity(state.job);
    renderTest(state);
}

// Results from a status request that started before a mutation are discarded and fetched again.
async function refresh() {
    if (document.hidden) return;
    if (pending) { queued = true; return; }
    pending = true;
    const started = epoch;
    try {
        const state = await api('status');
        if (started === epoch) render(state); else queued = true;
        if (pollError) { el('error').hidden = true; pollError = false; }
    } catch (error) { report(error); pollError = true; }
    finally { pending = false; if (queued) { queued = false; if (unlocked) refresh(); } }
}

async function submit(action, data) {
    const job = await api(action, data);
    epoch++;
    if (job?.id) ownJobs.set(job.id, {model: data.model, preset: data.preset});
    if (job?.id && current) { busy = job.state === 'running'; current = {...current, job}; renderActivity(job); }
}

async function act(action, data = {}) {
    if (busy || submitting) return false;
    el('error').hidden = true; submitting = true; updateControls();
    try { await submit(action, data); return true; } catch (error) { report(error); return false; }
    finally { submitting = false; updateControls(); await refresh(); }
}

async function catalog() {
    const result = await api('catalog'); options('preset', result.models, 'id', 'name'); updateControls();
}

async function unlock(token) {
    try { await api('authorize', {token}); el('token').value = ''; el('error').hidden = true; await catalog(); await refresh(); }
    catch (error) { report(error); el('unlock').hidden = false; }
}

tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => selectTab(tab.dataset.tab));
    tab.addEventListener('keydown', event => {
        const keys = {ArrowLeft: index - 1, ArrowUp: index - 1, ArrowRight: index + 1, ArrowDown: index + 1, Home: 0, End: tabs.length - 1};
        if (!(event.key in keys)) return;
        event.preventDefault();
        selectTab(tabs[(keys[event.key] + tabs.length) % tabs.length].dataset.tab, true);
    });
});
const vertical = matchMedia('(min-width: 761px)');
const orient = () => document.querySelector('[role=tablist]').setAttribute('aria-orientation', vertical.matches ? 'vertical' : 'horizontal');
orient(); vertical.addEventListener('change', orient);
el('goto-models').addEventListener('click', () => selectTab('models', true));
// Explicit next step after a load; polling never switches tabs on its own.
function gotoTest(model) {
    const instance = model && current?.models.find(m => m.key === model)?.loaded_instances?.[0];
    if (instance) el('loaded').value = instance.id; else if (model) preferLoaded = model;
    selectTab('test');
    renderTest(current);
    (el('run-test').disabled ? el('tab-test') : el('run-test')).focus();
}
el('job-next').addEventListener('click', () => {
    dismissedJob = el('job-dismiss').dataset.job; el('activity').hidden = true; gotoTest(el('job-next').dataset.model);
});
el('notice-test').addEventListener('click', () => gotoTest(''));
el('notice-unload').addEventListener('click', () => act('unload', {model: el('loaded').value}));
el('engine-toggle').addEventListener('click', () => { if (current) act(current.running ? 'stop' : 'start'); });
document.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', () => act(button.dataset.action)));
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
    try {
        const input = el(button.dataset.copy);
        if (navigator.clipboard) await navigator.clipboard.writeText(input.value);
        else { const copy = document.createElement('textarea'); copy.value = input.value; document.body.append(copy); copy.select(); document.execCommand('copy'); copy.remove(); }
        button.dataset.label ??= button.textContent; button.textContent = 'Copied';
        clearTimeout(button.copyTimer); button.copyTimer = setTimeout(() => { button.textContent = button.dataset.label; }, 1500);
    } catch (error) { report(error); }
}));
el('job-dismiss').addEventListener('click', () => { dismissedJob = el('job-dismiss').dataset.job; el('activity').hidden = true; el('workspace').focus(); });
el('unlock-form').addEventListener('submit', event => { event.preventDefault(); unlock(el('token').value); });
el('autostart').addEventListener('change', () => { engineDirty = true; });
el('startup-model').addEventListener('change', () => { engineDirty = true; });
// Edits made while the save is pending stay dirty so polling does not overwrite them.
el('save-engine').addEventListener('click', async () => {
    const saved = {autostart: el('autostart').checked, startupModel: el('startup-model').value};
    if (await act('settings', saved) && el('autostart').checked === saved.autostart && el('startup-model').value === saved.startupModel) engineDirty = false;
});
el('preset-form').addEventListener('submit', event => { event.preventDefault(); act('download', {preset: el('preset').value}); });
el('custom-form').addEventListener('submit', event => { event.preventDefault(); act('download', {source: el('source').value.trim()}); });
el('load-form').addEventListener('submit', event => {
    event.preventDefault(); if (!validLoad()) return;
    try { const values = loadValues(); if (basicLoad()) values.advanced = {}; act('load', values); } catch (error) { report(error); }
});
el('model').addEventListener('change', () => { selectModel(); updateControls(); refresh(); });
el('reset-load').addEventListener('click', () => { selectModel(true); updateControls(); });
el('use-latest').addEventListener('click', () => { selectModel(); el('defaults-conflict').hidden = true; updateControls(); el('save-defaults').focus(); });
// Unavailable advanced fields keep their saved values, so saving other settings never erases them.
el('save-defaults').addEventListener('click', () => {
    if (!validLoad() || !el('defaults-conflict').hidden) return;
    try { const values = loadValues(); ownSaved = snapshotOf(values); act('model-defaults', values); } catch (error) { report(error); }
});
el('unload').addEventListener('click', () => act('unload', {model: el('loaded').value}));
el('loaded').addEventListener('change', () => { if (current) renderTest(current); });
el('test-form').addEventListener('submit', async event => {
    event.preventDefault();
    if (busy || submitting || testRun) return;
    const model = el('loaded').value, prompt = el('prompt').value.trim();
    if (!model) { report(new Error('Load a model before running the test.')); return; }
    if (!prompt) { report(new Error('Enter a test prompt.')); return; }
    el('error').hidden = true; testFailure = null;
    testRun = {model, updated: current.lastTest?.updated, job: JSON.stringify(current.job || {}), seen: false, sending: true};
    submitting = true;
    renderTest(current);
    try { await submit('test', {model, prompt, generation: {system_prompt: testSystemPrompt}}); }
    catch (error) { submitting = false; testRun = null; testFailure = {model, message: error.message}; report(error); renderTest(current); return; }
    submitting = false; testRun.sending = false;
    await refresh();
});
document.addEventListener('visibilitychange', () => { if (!document.hidden && unlocked) refresh(); });
setInterval(() => { if (unlocked) refresh(); }, 2000);
const token = new URLSearchParams(location.hash.slice(1)).get('token');
history.replaceState(null, '', location.pathname);
if (token) unlock(token);
else refresh().then(() => { if (unlocked) catalog().catch(report); });
