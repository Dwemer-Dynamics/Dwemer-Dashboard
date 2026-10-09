// Homepage storage strip. Read-only: uses each server's Playthrough Saves overview API once per page load.
(() => {
    'use strict';
    const root = document.getElementById('home-storage');
    if (!root || !window.fetch) return;
    const mods = JSON.parse(root.dataset.mods || '[]');
    const totalValue = root.querySelector('[data-storage-total]');
    const totalNote = root.querySelector('[data-storage-note]');

    function bytes(value) {
        const n = Math.max(0, Number(value) || 0);
        const unit = n ? Math.min(4, Math.floor(Math.log(n) / Math.log(1024))) : 0;
        return (n / 1024 ** unit).toLocaleString(undefined, {maximumFractionDigits:1}) + ' ' + ['B','KB','MB','GB','TB'][unit];
    }
    // Finite nonnegative number or numeric string; anything else is unavailable rather than 0.
    function byteCount(value) {
        if (typeof value === 'string' && /^\s*\d+(\.\d+)?\s*$/.test(value)) value = Number(value);
        return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? value : null;
    }
    async function request(url) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(url, {credentials:'same-origin', cache:'no-store', signal:controller.signal});
            let data = null;
            try { data = await response.json(); } catch (error) { data = null; }
            if (!response.ok || !data || data.ok !== true) throw new Error(data && typeof data.error === 'string' ? data.error : 'Storage could not be read. Try again shortly.');
            return data;
        } catch (error) {
            if (error.name === 'AbortError') throw new Error('Timed out while measuring storage.');
            throw error;
        } finally { clearTimeout(timer); }
    }

    async function measure(mod) {
        const size = root.querySelector('[data-mod="' + mod.key + '"] .home-storage-mod-size');
        let total = null, reason = 'Database size unavailable.';
        try { total = byteCount((await request('api/data_manager.php?mod=' + encodeURIComponent(mod.key)))?.live?.database_bytes); }
        catch (error) { reason = error.message; }
        size.textContent = total === null ? 'Unavailable' : bytes(total);
        if (total === null) { size.parentElement.classList.add('is-error'); size.title = reason; }
        return total;
    }

    async function load() {
        const installed = mods.filter(mod => mod.installed);
        const measured = (await Promise.all(installed.map(measure))).filter(value => value !== null);
        if (!installed.length) {
            totalValue.textContent = 'None';
            totalNote.textContent = 'No mod installed';
        } else if (!measured.length) {
            totalValue.textContent = 'Unavailable';
        } else {
            totalValue.textContent = bytes(measured.reduce((acc, value) => acc + value, 0));
            if (measured.length < installed.length) totalNote.textContent = 'Partial: ' + measured.length + ' of ' + installed.length + ' measured';
        }
    }

    // Start after the page has loaded so storage queries never delay the homepage.
    const start = () => { load().catch(() => {}).finally(() => root.setAttribute('aria-busy', 'false')); };
    if (document.readyState === 'complete') start();
    else window.addEventListener('load', start, {once:true});
})();
