'use strict';
const el = id => document.getElementById(id);
const csrf = document.querySelector('meta[name="lmstudio-csrf"]').content;
let unlocked = false, pending = false, busy = false;

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
        unlocked = true; el('unlock').hidden = true; el('manager').hidden = false;
        el('status').textContent = state.running ? 'Running' : state.installed ? 'Stopped' : 'Not installed';
        el('autostart').checked = state.settings.autostart;
        el('endpoint').value = state.endpoint; el('chatUrl').value = state.chatUrl;
        const gb = bytes => (bytes / 1073741824).toFixed(1);
        el('resources').textContent = `Available: ${gb(state.resources.ramAvailable)} GB WSL RAM · ${gb(state.resources.vramFree)} GB GPU memory · ${gb(state.resources.diskFree)} GB disk`;
        options('model', state.models.filter(m => m.type === 'llm'), 'key', 'display_name');
        options('loaded', state.models.flatMap(m => m.loaded_instances || []), 'id', 'id');
        el('model-note').textContent = state.running ? 'Select a downloaded language model.' : 'Start the engine to list installed models.';
        busy = state.job.state === 'running';
        el('manager').querySelectorAll('button:not([data-copy]), input[type=checkbox]').forEach(button => button.disabled = busy);
        el('job').textContent = state.job.message ? `${state.job.state}: ${state.job.message}` : 'No operation yet.';
        el('output').textContent = state.job.output || '';
        if (busy && !state.job.total) el('progress').removeAttribute('value');
        else el('progress').value = state.job.total ? state.job.downloaded * 100 / state.job.total : 0;
    } catch (error) { report(error); }
    finally { pending = false; }
}

async function act(action, data = {}) {
    if (busy) return;
    el('error').hidden = true;
    try { await api(action, data); await refresh(); } catch (error) { report(error); }
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
el('autostart').addEventListener('change', () => act('settings', {autostart: el('autostart').checked}));
el('preset-form').addEventListener('submit', event => { event.preventDefault(); act('download', {preset: el('preset').value}); });
el('custom-form').addEventListener('submit', event => { event.preventDefault(); act('download', {source: el('source').value.trim()}); });
el('load-form').addEventListener('submit', event => { event.preventDefault(); act('load', {model: el('model').value, context: Number(el('context').value), gpu: Number(el('gpu').value), ttl: Number(el('ttl').value)}); });
el('unload').addEventListener('click', () => act('unload', {model: el('loaded').value}));
el('test-form').addEventListener('submit', event => { event.preventDefault(); act('test', {model: el('loaded').value, prompt: el('prompt').value, reasoning: el('reasoning').value}); });
document.addEventListener('visibilitychange', () => { if (!document.hidden && unlocked) refresh(); });
setInterval(() => { if (unlocked) refresh(); }, 2000);
const token = new URLSearchParams(location.hash.slice(1)).get('token');
history.replaceState(null, '', location.pathname);
if (token) unlock(token);
else refresh().then(() => { if (unlocked) catalog().catch(report); });
