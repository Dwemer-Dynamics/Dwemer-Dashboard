// Homepage Global API Keys rail: every installed catalog provider with its masked global keys. Providers with saved keys
// are listed first, then providers with none; Edit and Add key open one shared modal dialog, which can also test the saved
// key. Secrets never enter markup, URLs or storage; every server value is written with textContent.
(() => {
    'use strict';
    const root = document.getElementById('home-api-keys');
    if (!root || !window.fetch) return;
    const csrf = root.dataset.csrf || '';
    const note = root.querySelector('[data-keys-message]');
    const list = root.querySelector('[data-keys-list]');
    list.tabIndex = -1;
    // Display names only; the installed catalog in the status reply decides which providers are offered.
    const PROVIDERS = {openrouter:'OpenRouter', openai:'OpenAI', inworld:'Inworld', deepgram:'Deepgram', google:'Google',
        azure:'Azure', elevenlabs:'ElevenLabs', replicate:'Replicate', cartesia:'Cartesia', nano_gpt:'Nano-GPT', deepl:'DeepL',
        groq:'Groq', ploofy:'Ploofy', atlascloud:'AtlasCloud', custom_openai:'Custom OpenAI-compatible'};
    // Catalog providers listed without an Add key slot; their already saved keys still show and stay editable.
    const NO_NEW_KEYS = new Set(['custom_openai']);
    const MESSAGES = {
        not_configured: 'Global API keys are not set up yet. Open API Keys in the DwemerDistro launcher to set them up.',
        unavailable: 'API keys are unavailable right now. Update DwemerDistro, then reload this page.',
        refused: 'API keys can only be viewed from the browser on this PC.',
        session: 'Your session expired. Reload this page.',
        conflict: 'This key changed elsewhere. Reload it before editing again.',
        missing: 'This key no longer exists. Reload it to update the list.',
        busy: 'Another key change is running. Nothing was saved; try again shortly.',
        unknown: 'The save result is unknown. Reload this key before trying again.',
        invalid: 'Check the replacement key.',
        limit: 'The saved key limit has been reached. Nothing was saved.',
        crypto_unavailable: 'Key encryption is unavailable, so nothing was saved. Open API Keys in the DwemerDistro launcher.',
    };
    // Fixed text per test result code; the server never returns provider replies or messages.
    const TESTS = {
        accepted: ['Accepted: the service accepted the saved key for a read-only request. Model access was not checked.', 'ok'],
        rejected: ['Rejected: the service refused the saved key (authentication or permission). This does not by itself prove the key is invalid.', 'error'],
        rate_limited: ['Rate limited: try again later. The key was not verified.', 'error'],
        unavailable: ['Check failed: the service could not be reached or answered unexpectedly. The key was not verified.', 'error'],
        unsupported: ['No quick check is available for this provider and endpoint.', 'error'],
        reachable: ['Reachable: the service answered. The key and model access were not verified.', 'ok'],
    };
    const FIELDS = {provider:'This provider is not available.', label:'This key name was not accepted.',
        origin:'This endpoint origin was not accepted.', secret:'This key was not accepted.'};
    const rows = [];
    const slots = [];
    const groups = new Map();

    if (!csrf) return;

    async function call(body) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 60000);
        try {
            const response = await fetch('api/api_keys.php', {method:'POST', credentials:'same-origin', cache:'no-store',
                headers:{'Content-Type':'application/json'}, body:JSON.stringify(Object.assign({csrf}, body)), signal:controller.signal});
            const data = await response.json().catch(() => null);
            return data && typeof data.outcome === 'string' ? data : {ok:false, outcome:'unavailable'};
        } catch (error) {
            return {ok:false, outcome:'unavailable'};
        } finally {
            clearTimeout(timer);
        }
    }

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    function button(className, text, type) {
        const node = el('button', className, text);
        node.type = type || 'button';
        return node;
    }

    function providerName(provider) {
        return Object.prototype.hasOwnProperty.call(PROVIDERS, provider) ? PROVIDERS[provider] : String(provider);
    }

    // One shared modal for Edit and Add key; `active` is the row or slot it is open for.
    const dialog = el('dialog', 'keys-dialog');
    dialog.setAttribute('aria-labelledby', 'keys-dialog-title');
    dialog.setAttribute('aria-describedby', 'keys-dialog-provider');
    const form = el('form', 'keys-form keys-dialog-body');
    form.noValidate = true;
    const dTitle = el('h2', 'keys-dialog-title');
    dTitle.id = 'keys-dialog-title';
    const dProvider = el('p', 'keys-dialog-provider');
    dProvider.id = 'keys-dialog-provider';
    const secretField = el('label', 'keys-field');
    const dSecretText = el('span');
    const dSecret = el('input', 'keys-input');
    Object.assign(dSecret, {type:'password', maxLength:4096, autocomplete:'new-password', spellcheck:false});
    secretField.append(dSecretText, dSecret);
    // No default address: regional and custom services differ per account.
    const originField = el('label', 'keys-field', 'Endpoint origin');
    const dOrigin = el('input', 'keys-input');
    Object.assign(dOrigin, {type:'text', maxLength:256, autocomplete:'off', spellcheck:false, placeholder:'https://'});
    const originHelp = el('span', 'keys-help', 'The start of the API address from your provider account: https:// and the host name, '
        + 'plus :port only if it has one, with nothing after it. This key is only ever sent there.');
    originHelp.id = 'keys-dialog-origin-help';
    dOrigin.setAttribute('aria-describedby', originHelp.id);
    originField.append(dOrigin, originHelp);
    const dStatus = el('p', 'keys-state');
    dStatus.setAttribute('role', 'status');
    // Test checks only the saved key; the hint explains why it is unavailable, the result line shows the latest check.
    const dTestHint = el('p', 'keys-state keys-help');
    dTestHint.id = 'keys-dialog-test-hint';
    const dTestResult = el('p', 'keys-state');
    dTestResult.setAttribute('role', 'status');
    const actions = el('div', 'keys-actions');
    const dSave = button('keys-button keys-add primary', 'Save', 'submit');
    const dTest = button('keys-button', 'Test');
    dTest.setAttribute('aria-describedby', dTestHint.id);
    const dCancel = button('keys-button', 'Cancel');
    const dReload = button('keys-button', 'Reload');
    actions.append(dSave, dTest, dCancel, dReload);
    form.append(dTitle, dProvider, secretField, originField, dStatus, dTestHint, dTestResult, actions);
    dialog.append(form);
    document.body.append(dialog);
    let active = null;
    let returnFocus = null;
    let backdropDown = false;

    function setState(target, text, kind) {
        target.state.textContent = text;
        target.state.className = 'keys-state' + (kind ? ' ' + kind : '');
        if (active === target) {
            dStatus.textContent = text;
            dStatus.className = 'keys-state' + (kind ? ' ' + kind : '');
        }
    }

    function setBusy(target, on) {
        target.busy = on;
        dialog.setAttribute('aria-busy', String(on));
        dSave.disabled = on || target.blocked;
        dCancel.disabled = dReload.disabled = on;
        dSecret.readOnly = dOrigin.readOnly = on;
        refreshTest();
    }

    // Like the launcher: only an unchanged saved key is tested, so a typed replacement is never mistaken for the old key.
    function testHint(target) {
        if (!target.key) return 'Save the key before testing.';
        if (target.blocked) return 'Reload this key before testing.';
        if (dSecret.value !== '') return 'Save the replacement before testing.';
        if (target.key.test_supported !== true) return 'No quick check is available for this provider and endpoint.';
        return 'Test sends one read-only request with the saved key. Nothing is generated.';
    }

    function refreshTest() {
        if (!active) return;
        dTestHint.textContent = testHint(active);
        dTest.disabled = active.busy || !active.key || active.blocked || dSecret.value !== '' || active.key.test_supported !== true;
    }

    function setTestResult(text, kind) {
        dTestResult.textContent = text;
        dTestResult.className = 'keys-state' + (kind ? ' ' + kind : '');
    }

    // Returns focus to a usable control after a request disabled the focused one.
    function refocus(preferred) {
        (preferred && !preferred.disabled ? preferred : dSecret).focus();
    }

    function openDialog(target, trigger) {
        if (dialog.open || !trigger.isConnected) return;
        const edit = !!target.key;
        active = target;
        returnFocus = trigger;
        dTitle.textContent = edit ? 'Edit key' : 'Add key';
        dProvider.textContent = edit ? providerName(target.key.provider) + ' · ' + target.preview.textContent
            : providerName(target.provider);
        dSecret.value = '';
        dSecretText.textContent = edit ? 'Replacement key' : 'Key';
        dSecret.placeholder = edit ? 'Leave blank to keep current' : '';
        dSecret.required = !edit;
        originField.hidden = edit || !target.needsOrigin;
        dOrigin.required = !originField.hidden;
        dOrigin.value = originField.hidden ? '' : (target.draftOrigin || '');
        setTestResult('');
        setBusy(target, false);
        // A blocked key stays blocked until Reload succeeds, even after Cancel.
        dReload.hidden = !target.blocked;
        if (target.blocked) setState(target, target.state.textContent, 'error');
        else setState(target, '');
        dialog.showModal();
        dSecret.focus();
    }

    // Never dismisses during a request; returns focus to `focusTo`, else the opening button, else the list.
    function closeDialog(focusTo) {
        if (!active || active.busy) return;
        const target = active;
        if (!target.key) target.draftOrigin = dOrigin.value;
        dSecret.value = '';
        // A save shown in the open dialog stays on its row after closing; other dialog messages are cleared.
        if (!target.blocked && !target.saved) setState(target, '');
        target.saved = false;
        active = null;
        dStatus.textContent = '';
        setTestResult('');
        if (dialog.open) dialog.close();
        const next = [focusTo, returnFocus].find((node) => node && node.isConnected && !node.hidden);
        returnFocus = null;
        (next || list).focus();
    }

    function showView(row) {
        row.name.textContent = row.key.label || providerName(row.key.provider);
        row.preview.textContent = row.key.preview || (row.key.configured ? '********' : 'No key saved');
        row.edit.setAttribute('aria-label', 'Edit ' + row.name.textContent);
    }

    function block(target, message) {
        // Conflicts and unknown results need explicit reload; never retry the write automatically.
        target.blocked = true;
        dSave.disabled = true;
        dSecret.value = '';
        dReload.hidden = false;
        setTestResult('');
        refreshTest();
        setState(target, message, 'error');
    }

    // Keeps the dialog open on the saved key so it can be tested straight away; Cancel then returns to its Edit button.
    function showSaved(row, message) {
        active = row;
        returnFocus = row.edit;
        row.saved = true;
        dTitle.textContent = 'Edit key';
        dProvider.textContent = providerName(row.key.provider) + ' · ' + row.preview.textContent;
        dSecret.value = '';
        dSecretText.textContent = 'Replacement key';
        dSecret.placeholder = 'Leave blank to keep current';
        dSecret.required = false;
        originField.hidden = true;
        dOrigin.required = false;
        dOrigin.value = '';
        dReload.hidden = true;
        setTestResult('');
        setBusy(row, false);
        setState(row, message, 'ok');
        refocus(dTest);
    }

    async function test(row) {
        if (row.busy || row.blocked || !row.key || dSecret.value !== '' || row.key.test_supported !== true) return;
        const key = row.key;
        setBusy(row, true);
        setState(row, '');
        setTestResult('Checking the saved key…');
        const result = await call({op:'test', key_id:key.key_id, revision:key.revision});
        setBusy(row, false);
        if (active !== row || row.key !== key) return;
        if (result.ok && Object.prototype.hasOwnProperty.call(TESTS, result.result)) {
            setTestResult(TESTS[result.result][0], TESTS[result.result][1]);
        } else if (result.outcome === 'conflict' || result.outcome === 'missing') {
            block(row, result.outcome === 'conflict' ? 'This key changed elsewhere. Reload it before testing.' : MESSAGES.missing);
        } else {
            setTestResult(result.outcome === 'busy' ? 'Another key change is running. Try the test again shortly.'
                : MESSAGES[result.outcome === 'session' || result.outcome === 'refused' ? result.outcome : '']
                || 'The saved key could not be checked. Try again shortly.', 'error');
        }
        refocus(dTest);
    }

    function problem(field, message) {
        setState(active, message, 'error');
        field.focus();
    }

    async function save(row) {
        if (row.busy || row.blocked) return;
        // Display names are not editable here; send the saved one back unchanged.
        const label = row.key.label;
        const secret = dSecret.value;
        if (secret !== '' && !/^[\x21-\x7e]{1,4096}$/.test(secret)) {
            return problem(dSecret, 'Replacement key must be printable characters without spaces.');
        }
        setBusy(row, true);
        setTestResult('');
        setState(row, 'Saving…');
        const result = await call({op:'edit', key_id:row.key.key_id, revision:row.key.revision, label, secret});
        setBusy(row, false);
        if (result.ok && result.key && result.key.key_id === row.key.key_id) {
            row.key = result.key;
            showView(row);
            showSaved(row, result.outcome === 'unchanged' ? 'No changes to save.' : 'Saved.');
            return;
        }
        if (result.outcome === 'conflict' || result.outcome === 'missing' || result.outcome === 'unknown' || result.outcome === 'unavailable') {
            block(row, MESSAGES[result.outcome === 'unavailable' ? 'unknown' : result.outcome]);
        } else if (result.outcome === 'invalid') {
            setState(row, Array.isArray(result.fields) && result.fields.length === 1
                ? (result.fields[0] === 'label' ? 'This saved key name was not accepted. Reload this key.' : 'This replacement key was not accepted.')
                : MESSAGES.invalid, 'error');
        } else {
            setState(row, MESSAGES[result.outcome] || MESSAGES.unknown, 'error');
        }
    }

    // Refresh only this row from a new status read; other rows keep their state.
    async function reloadRow(row) {
        setBusy(row, true);
        setTestResult('');
        setState(row, 'Reloading…');
        const result = await call({op:'status'});
        setBusy(row, false);
        if (!result.ok || !Array.isArray(result.keys)) {
            setState(row, MESSAGES[result.outcome] || MESSAGES.unavailable, 'error');
            return;
        }
        const fresh = result.keys.find((key) => key && key.key_id === row.key.key_id);
        row.blocked = false;
        if (!fresh) {
            row.edit.hidden = true;
            closeDialog(list);
            setState(row, 'This key no longer exists.', 'error');
            return;
        }
        row.key = fresh;
        showView(row);
        closeDialog(row.edit);
        setState(row, 'Reloaded. Edit again to make changes.', 'ok');
    }

    function buildRow(key) {
        const row = {key, busy:false, blocked:false};
        row.item = el('li', 'keys-row');
        const head = el('div', 'keys-row-head');
        const text = el('div', 'keys-row-text');
        row.name = el('span', 'keys-name');
        row.preview = el('code', 'keys-preview');
        row.edit = button('keys-button', 'Edit');
        row.edit.setAttribute('aria-haspopup', 'dialog');
        text.append(row.name, row.preview);
        head.append(text, row.edit);
        row.state = el('p', 'keys-state');
        row.state.setAttribute('role', 'status');
        row.item.append(head, row.state);
        row.edit.addEventListener('click', () => openDialog(row, row.edit));
        showView(row);
        rows.push(row);
        return row;
    }

    const compare = (a, b) => a.localeCompare(b, undefined, {sensitivity:'base'});
    const savedBox = el('div', 'keys-category');
    const savedTitle = el('p', 'keys-category-title', 'Saved');
    const emptyBox = el('div', 'keys-category');
    const emptyTitle = el('p', 'keys-category-title', 'Not set');
    savedBox.append(savedTitle);
    emptyBox.append(emptyTitle);

    // Saved providers first, then providers without keys; alphabetical within each. Only the moved group's node changes.
    function placeGroup(group) {
        const box = group.items.querySelector('.keys-row:not(.keys-empty)') ? savedBox : emptyBox;
        if (group.section.parentNode !== box) {
            const next = [...box.querySelectorAll(':scope > .keys-group')].find((section) => compare(section.dataset.name, group.name) > 0);
            box.insertBefore(group.section, next || null);
        }
        savedTitle.hidden = !savedBox.querySelector('.keys-group');
        emptyTitle.hidden = !emptyBox.querySelector('.keys-group');
    }

    function addSaved(key) {
        const group = groups.get(key.provider);
        if (!group || rows.some((row) => row.key.key_id === key.key_id)) return null;
        const row = buildRow(key);
        const next = rows.find((other) => other !== row && other.item.parentNode === group.items
            && compare(String(other.key.label), String(key.label)) > 0);
        group.items.insertBefore(row.item, next ? next.item : null);
        placeGroup(group);
        return row;
    }

    function removeSlot(slot) {
        slot.item.remove();
        slots.splice(slots.indexOf(slot), 1);
    }

    function checkCreate(slot, secret, origin) {
        if (!/^[\x21-\x7e]{1,4096}$/.test(secret)) {
            return [dSecret, secret === '' ? 'Enter the key to save.' : 'Key must be printable characters without spaces.'];
        }
        if (slot.needsOrigin && !/^https?:\/\/[^\s/?#@]{1,240}$/i.test(origin)) {
            return [dOrigin, 'Enter only the start of the address, such as https://host, with nothing after the host or port.'];
        }
        return null;
    }

    async function create(slot) {
        if (slot.busy || slot.blocked) return;
        // New keys are named after their provider.
        const label = providerName(slot.provider);
        const secret = dSecret.value;
        const origin = slot.needsOrigin ? dOrigin.value.trim() : null;
        const invalid = checkCreate(slot, secret, origin);
        if (invalid) return problem(invalid[0], invalid[1]);
        setBusy(slot, true);
        setState(slot, 'Saving…');
        const result = await call({op:'create', provider:slot.provider, label, origin, secret});
        setBusy(slot, false);
        if (result.ok && result.key && result.key.provider === slot.provider && typeof result.key.key_id === 'string') {
            removeSlot(slot);
            const row = addSaved(result.key);
            if (row) showSaved(row, 'Saved.');
            else closeDialog(list);
            return;
        }
        if (result.outcome === 'unknown' || result.outcome === 'unavailable') {
            // The key may exist now. Block until Reload shows the saved list; never resubmit automatically.
            block(slot, 'The save result is unknown. Reload before trying again.');
        } else if (result.outcome === 'invalid') {
            setState(slot, Array.isArray(result.fields) && result.fields.length === 1 && FIELDS[result.fields[0]]
                ? FIELDS[result.fields[0]] : (slot.needsOrigin ? 'Check the key and endpoint origin.' : 'Check the key.'), 'error');
        } else {
            setState(slot, MESSAGES[result.outcome] || MESSAGES.unknown, 'error');
        }
    }

    // Shows any key saved for this provider since the page loaded instead of creating a duplicate.
    async function reloadSlot(slot) {
        setBusy(slot, true);
        setTestResult('');
        setState(slot, 'Reloading…');
        const result = await call({op:'status'});
        setBusy(slot, false);
        if (!result.ok || !Array.isArray(result.keys)) {
            setState(slot, MESSAGES[result.outcome] || MESSAGES.unavailable, 'error');
            return;
        }
        const fresh = result.keys.filter((key) => key && typeof key.key_id === 'string' && key.provider === slot.provider);
        slot.blocked = false;
        if (fresh.length > 0) {
            removeSlot(slot);
            const added = fresh.map(addSaved).filter(Boolean);
            closeDialog(added[0] && added[0].edit);
            if (added[0]) setState(added[0], 'Reloaded. This key was saved.', 'ok');
            return;
        }
        closeDialog(slot.add);
        setState(slot, 'Reloaded. No key was saved; add it again when ready.', 'ok');
    }

    function buildSlot(provider, needsOrigin) {
        const slot = {provider, needsOrigin, busy:false, blocked:false, draftOrigin:''};
        slot.item = el('li', 'keys-row keys-empty');
        const head = el('div', 'keys-row-head');
        const text = el('div', 'keys-row-text');
        text.append(el('span', 'keys-unset', 'Not set'));
        slot.add = button('keys-button keys-add', 'Add key');
        slot.add.setAttribute('aria-label', 'Add ' + providerName(provider) + ' key');
        slot.add.setAttribute('aria-haspopup', 'dialog');
        head.append(text, slot.add);
        slot.state = el('p', 'keys-state');
        slot.state.setAttribute('role', 'status');
        slot.item.append(head, slot.state);
        slot.add.addEventListener('click', () => openDialog(slot, slot.add));
        slots.push(slot);
        return slot;
    }

    function render(keys, providers) {
        const catalog = new Map();
        for (const entry of providers) {
            if (entry && typeof entry.provider === 'string' && !NO_NEW_KEYS.has(entry.provider)) {
                catalog.set(entry.provider, entry.needs_origin === true);
            }
        }
        const saved = keys.filter((key) => key && typeof key.key_id === 'string' && typeof key.provider === 'string');
        // Every offered catalog provider is listed; a saved key whose provider is not offered keeps its row (edit only).
        const names = [...new Set([...catalog.keys(), ...saved.map((key) => key.provider)])];
        list.append(savedBox, emptyBox);
        for (const provider of names) {
            const section = el('section', 'keys-group');
            section.dataset.name = providerName(provider);
            const items = el('ul', 'keys-items');
            section.append(el('h3', 'keys-group-title', providerName(provider)), items);
            const group = {section, items, name:section.dataset.name};
            groups.set(provider, group);
            saved.filter((key) => key.provider === provider).forEach(addSaved);
            if (!items.children.length && catalog.has(provider)) items.append(buildSlot(provider, catalog.get(provider)).item);
            placeGroup(group);
        }
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (active && active.key) save(active);
        else if (active) create(active);
    });
    dCancel.addEventListener('click', () => closeDialog());
    dTest.addEventListener('click', () => { if (active && active.key) test(active); });
    // A typed replacement clears the last result and disables Test until it is saved.
    dSecret.addEventListener('input', () => {
        if (!active || active.busy) return;
        setTestResult('');
        refreshTest();
    });
    dReload.addEventListener('click', () => {
        if (active && !active.busy) (active.key ? reloadRow : reloadSlot)(active);
    });
    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeDialog();
        } else if (event.key === 'Tab') {
            // Keep Tab inside the dialog rather than reaching the browser toolbar.
            const focusable = [...form.querySelectorAll('input, button')].filter((node) => !node.disabled && node.offsetParent);
            const edge = event.shiftKey ? focusable[0] : focusable[focusable.length - 1];
            if (focusable.length && (document.activeElement === edge || !form.contains(document.activeElement))) {
                event.preventDefault();
                focusable[event.shiftKey ? focusable.length - 1 : 0].focus();
            }
        }
    });
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); closeDialog(); });
    // A backdrop click (press and release outside the panel) behaves like Cancel.
    dialog.addEventListener('pointerdown', (event) => { backdropDown = event.target === dialog; });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog && backdropDown) closeDialog();
        backdropDown = false;
    });
    // Should the browser force-close the dialog mid-request, reopen it so the result is still shown in place.
    dialog.addEventListener('close', () => {
        if (active && active.busy) dialog.showModal();
        else if (active) closeDialog();
    });

    window.addEventListener('pagehide', () => { dSecret.value = ''; });

    call({op:'status'}).then((result) => {
        if (!result.ok || !Array.isArray(result.keys) || !Array.isArray(result.providers)) {
            note.textContent = MESSAGES[result.outcome] || MESSAGES.unavailable;
            note.classList.add('error');
            return;
        }
        render(result.keys, result.providers);
        // The status line only carries loading and error text; hiding it collapses its space once the list shows.
        note.textContent = '';
        note.hidden = true;
        list.hidden = false;
    });
})();
