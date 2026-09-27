'use strict';
const el = id => document.getElementById(id);
const csrf = document.querySelector('meta[name="lmstudio-csrf"]').content;
let unlocked = false, pending = false, busy = false;
let current = null, shownModel = null, engineDirty = false;
const loadFields = {
    flashAttention: ['Flash Attention', 'bool'], offloadKVCacheToGpu: ['Offload KV cache to GPU', 'bool'],
    gpuStrictVramCap: ['Strict GPU memory limit', 'bool'], keepModelInMemory: ['Keep model in RAM', 'bool'],
    tryMmap: ['Memory mapping (mmap)', 'bool'], evalBatchSize: ['Evaluation batch size', 1, 2048, 1],
    seed: ['Random seed (next load)', 0, 4294967295, 1],
    llamaKCacheQuantizationType: ['K cache precision', 'cache'], llamaVCacheQuantizationType: ['V cache precision', 'cache'],
    ropeFrequencyBase: ['RoPE frequency base', 0, 10000000, 'any'], ropeFrequencyScale: ['RoPE frequency scale', .01, 100, 'any']
};
const generationFields = {
    temperature: ['Temperature', 0, 1, .01], max_output_tokens: ['Maximum output tokens', 1, 4096, 1],
    top_p: ['Top P', 0, 1, .01], top_k: ['Top K', 1, 1000, 1],
    min_p: ['Min P', 0, 1, .01], repeat_penalty: ['Repeat penalty', .1, 2, .01]
};

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
        } else { input.type = 'number'; input.min = min; input.max = max; input.step = step;
            input.placeholder = key === 'temperature' ? 'Default: 0.2' : key === 'max_output_tokens' ? 'Default: 192' : 'Engine default'; }
        label.append(input); el(container).append(label);
    }
}
buildFields('load-fields', loadFields); buildFields('generation-fields', generationFields);
el('temperature').value = .2; el('max_output_tokens').value = 192;

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
function generationValues() { return {...readFields(generationFields), system_prompt: el('system-prompt').value, reasoning: el('reasoning').value}; }

// Apply defaults only when the user changes models, never during status polling.
function selectModel(reset = false) {
    const values = reset ? {} : current?.modelDefaults?.[el('model').value] || {};
    el('context').value = values.context ?? 4096; el('gpu').value = values.gpu ?? 100; el('ttl').value = values.ttl ?? 600;
    fillFields(loadFields, values.advanced || {}); shownModel = el('model').value;
}

function applyGeneration(values) {
    fillFields(generationFields, values); el('system-prompt').value = values.system_prompt || ''; el('reasoning').value = values.reasoning || 'default';
}

async function api(action, data = {}) {
    const response = await fetch('api/lmstudio.php', {method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({...data, action, csrf}), cache: 'no-store'});
    const result = await response.json();
    if (!response.ok) {
        if (response.status === 401) { unlocked = false; el('unlock').hidden = false; el('manager').hidden = true; }
        throw new Error(result.error || 'Request failed.');
    }
    return result;
}

function report(error) { el('error').textContent = error.message; el('error').hidden = false; }

function options(id, models, key, title) {
    const select = el(id), selected = select.value;
    const signature = JSON.stringify(models.map(m => [m[key], m[title]]));
    if (select.dataset.signature === signature) return;
    select.replaceChildren(...models.map(m => new Option(m[title] || m[key], m[key])));
    if (models.some(m => m[key] === selected)) select.value = selected;
    select.dataset.signature = signature;
}

async function refresh() {
    if (pending || document.hidden) return;
    pending = true;
    try {
        const state = await api('status');
        current = state;
        unlocked = true; el('unlock').hidden = true; el('manager').hidden = false;
        el('status').textContent = state.running ? 'Running' : state.installed ? 'Stopped' : 'Not installed';
        if (!engineDirty) el('autostart').checked = state.settings.autostart;
        el('endpoint').value = state.endpoint; el('chatUrl').value = state.chatUrl;
        const gb = bytes => (bytes / 1073741824).toFixed(1);
        el('resources').textContent = `Available: ${gb(state.resources.ramAvailable)} GB WSL RAM · ${gb(state.resources.vramFree)} GB GPU memory · ${gb(state.resources.diskFree)} GB disk`;
        options('model', state.models.filter(m => m.type === 'llm'), 'key', 'display_name');
        options('loaded', state.models.flatMap(m => m.loaded_instances || []), 'id', 'id');
        const startupModels = [{key: '', display_name: 'None — start engine only'}, ...state.models.filter(m => m.type === 'llm')];
        if (state.settings.startupModel && !startupModels.some(m => m.key === state.settings.startupModel)) startupModels.push({key: state.settings.startupModel});
        options('startup-model', startupModels, 'key', 'display_name');
        if (!engineDirty) el('startup-model').value = state.settings.startupModel || '';
        options('test-presets', [{key: '', name: 'Choose a preset'}, ...Object.keys(state.testPresets || {}).map(key => ({key, name: key}))], 'key', 'name');
        if (shownModel !== el('model').value) selectModel();
        el('sdk-note').textContent = state.advancedAvailable ? 'Advanced loading is available. Saved defaults are used by this manager and startup.' : 'Reinstall the LM Studio component to enable advanced loading.';
        el('load-fields').querySelectorAll('input,select').forEach(input => input.disabled = !state.advancedAvailable);
        el('model-details').textContent = JSON.stringify({model: state.models.find(m => m.key === el('model').value),
            lastAdvancedLoad: state.lastLoad?.model === el('model').value ? state.lastLoad.appliedConfig : undefined}, null, 2);
        el('model-note').textContent = state.running ? 'Select a downloaded language model.' : 'Start the engine to list installed models.';
        busy = state.job.state === 'running';
        el('manager').querySelectorAll('button:not([data-copy]), input[type=checkbox]').forEach(button => button.disabled = busy);
        el('job').textContent = state.job.message ? `${state.job.state}: ${state.job.message}` : 'No operation yet.';
        const test = state.job.action === 'test' && state.job.state === 'running' ? state.job : state.lastTest || {};
        el('output').textContent = test.output || '';
        el('test-stats').textContent = test.elapsedSeconds ? `${test.elapsedSeconds}s total · ${test.stats?.total_output_tokens ?? '—'} output tokens · ${test.stats?.tokens_per_second?.toFixed(1) ?? '—'} tokens/sec` : '';
        el('test-details').textContent = JSON.stringify({...test, output: undefined}, null, 2);
        if (busy && !state.job.total) el('progress').removeAttribute('value');
        else el('progress').value = state.job.total ? state.job.downloaded * 100 / state.job.total : 0;
    } catch (error) { report(error); }
    finally { pending = false; }
}

async function act(action, data = {}) {
    if (busy) return;
    el('error').hidden = true;
    try { await api(action, data); await refresh(); return true; } catch (error) { report(error); return false; }
}

async function catalog() {
    const result = await api('catalog'); options('preset', result.models, 'id', 'name');
}

async function unlock(token) {
    try { await api('authorize', {token}); el('token').value = ''; el('error').hidden = true; await catalog(); await refresh(); }
    catch (error) { report(error); el('unlock').hidden = false; }
}

document.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', () => act(button.dataset.action)));
document.querySelectorAll('[data-copy]').forEach(button => button.addEventListener('click', async () => {
    try {
        const input = el(button.dataset.copy);
        if (navigator.clipboard) await navigator.clipboard.writeText(input.value);
        else { const copy = document.createElement('textarea'); copy.value = input.value; document.body.append(copy); copy.select(); document.execCommand('copy'); copy.remove(); }
        button.textContent = 'Copied';
    } catch (error) { report(error); }
}));
el('unlock-form').addEventListener('submit', event => { event.preventDefault(); unlock(el('token').value); });
el('autostart').addEventListener('change', () => { engineDirty = true; });
el('startup-model').addEventListener('change', () => { engineDirty = true; });
el('save-engine').addEventListener('click', async () => { if (await act('settings', {autostart: el('autostart').checked, startupModel: el('startup-model').value})) engineDirty = false; });
el('preset-form').addEventListener('submit', event => { event.preventDefault(); act('download', {preset: el('preset').value}); });
el('custom-form').addEventListener('submit', event => { event.preventDefault(); act('download', {source: el('source').value.trim()}); });
el('load-form').addEventListener('submit', event => { event.preventDefault(); try { act('load', loadValues()); } catch (error) { report(error); } });
el('model').addEventListener('change', () => { selectModel(); refresh(); });
el('reset-load').addEventListener('click', () => selectModel(true));
el('save-defaults').addEventListener('click', () => { if (!el('load-form').reportValidity()) return; try { act('model-defaults', loadValues()); } catch (error) { report(error); } });
el('unload').addEventListener('click', () => act('unload', {model: el('loaded').value}));
el('test-form').addEventListener('submit', event => { event.preventDefault(); try { act('test', {model: el('loaded').value, prompt: el('prompt').value, generation: generationValues()}); } catch (error) { report(error); } });
el('apply-preset').addEventListener('click', () => { const name = el('test-presets').value; if (name) { applyGeneration(current.testPresets[name]); el('preset-name').value = name; } });
el('save-preset').addEventListener('click', () => { try { act('test-preset', {name: el('preset-name').value.trim(), generation: generationValues()}); } catch (error) { report(error); } });
el('delete-preset').addEventListener('click', () => { const name = el('test-presets').value; if (name && confirm(`Delete test preset “${name}”?`)) act('test-preset', {name, delete: true}); });
el('export-preset').addEventListener('click', () => {
    try {
        const content = JSON.stringify({format: 'dwemer-llm-test-v1', name: el('preset-name').value, generation: generationValues()}, null, 2);
        const url = URL.createObjectURL(new Blob([content], {type: 'application/json'}));
        const link = document.createElement('a'); link.href = url; link.download = 'llm-studio-test-preset.json'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (error) { report(error); }
});
el('import-preset').addEventListener('change', async event => {
    try {
        const file = event.target.files[0]; if (!file) return;
        if (file.size > 12000) throw new Error('Preset file is too large.');
        const value = JSON.parse(await file.text());
        if (value.format !== 'dwemer-llm-test-v1' || !value.generation || typeof value.generation !== 'object' || Array.isArray(value.generation)) throw new Error('Invalid Dwemer test preset.');
        if (Object.keys(value.generation).some(key => !Object.hasOwn(generationFields, key) && !['system_prompt', 'reasoning'].includes(key))) throw new Error('Preset contains unsupported settings.');
        for (const [key, item] of Object.entries(value.generation)) {
            const spec = generationFields[key];
            if (spec && (typeof item !== 'number' || !Number.isFinite(item) || item < spec[1] || item > spec[2] || (spec[3] === 1 && !Number.isInteger(item)))) throw new Error(`Invalid ${spec[0]} in preset.`);
            if (key === 'system_prompt' && (typeof item !== 'string' || item.length > 4000)) throw new Error('Invalid system prompt in preset.');
            if (key === 'reasoning' && !['default', 'off', 'on', 'low', 'medium', 'high'].includes(item)) throw new Error('Invalid thinking setting in preset.');
        }
        applyGeneration(value.generation); el('preset-name').value = String(value.name || '').slice(0, 60);
        generationValues();
    } catch (error) { report(error); }
    finally { event.target.value = ''; }
});
document.addEventListener('visibilitychange', () => { if (!document.hidden && unlocked) refresh(); });
setInterval(() => { if (unlocked) refresh(); }, 2000);
const token = new URLSearchParams(location.hash.slice(1)).get('token');
history.replaceState(null, '', location.pathname);
if (token) unlock(token);
else refresh().then(() => { if (unlocked) catalog().catch(report); });
