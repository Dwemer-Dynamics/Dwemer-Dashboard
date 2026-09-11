// Add the shared security token to native forms, and turn old mutation links into POSTs.
(() => {
    'use strict';
    const configNode = document.getElementById('sm-guard-config');
    if (!configNode) return;
    const config = JSON.parse(configNode.textContent);
    const controls = document.getElementById('sm-controls');
    if (!controls) return;
    function hidden(form, name, value) {
        let field = form.querySelector(`input[name="${name}"]`);
        if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = name; form.append(field); }
        field.value = value;
    }
    function secure(form) {
        hidden(form, '_sm_csrf', config.csrf);
        hidden(form, '_sm_scope', config.scope);
    }
    function confirmAction(action, subject = '') {
        const description = action.replaceAll('_', ' ');
        return window.confirm(`${description}${subject ? ': ' + subject : ''}\n\nThese tools can change ${config.scope}. Before restoring or resetting, stop the game and server activity and keep a separate backup. A database backup is not a game save. SQL backups can execute database commands; only restore files you trust.\n\nContinue?`);
    }
    controls.querySelectorAll('form').forEach(form => { if (form.method.toLowerCase() === 'post') secure(form); });
    controls.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.method.toLowerCase() !== 'post') return;
        secure(form);
        const action = form.elements.namedItem('action')?.value || '';
        if (/(restore|switch|delete|reset|repair|vacuum|reindex)/.test(action) && !confirmAction(action)) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    controls.addEventListener('click', event => {
        const link = event.target.closest('[data-sm-action], a[href]');
        if (!link) return;
        const url = new URL(link.href || location.href, location.href);
        const action = link.dataset.smAction || url.searchParams.get('action');
        if (url.origin !== location.origin || !config.legacyActions.includes(action)) return;
        for (const key of ['filename', 'file', 'source', 'target']) {
            const value = link.getAttribute('data-sm-' + key);
            if (value !== null) url.searchParams.set(key, value);
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (action !== 'backup' && !confirmAction(action, url.searchParams.get('filename') || url.searchParams.get('target') || url.searchParams.get('file') || '')) return;
        const form = document.createElement('form');
        form.method = 'post';
        url.searchParams.delete('action');
        for (const key of ['filename', 'file', 'source', 'target']) {
            if (url.searchParams.has(key)) { hidden(form, key, url.searchParams.get(key)); url.searchParams.delete(key); }
        }
        form.action = url.href;
        hidden(form, '_sm_legacy_action', action);
        secure(form);
        controls.append(form);
        form.submit();
    }, true);
})();
