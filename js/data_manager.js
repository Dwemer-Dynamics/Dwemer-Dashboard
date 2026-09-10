(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('sm-config').textContent);
    const content = document.getElementById('sm-content');
    const status = document.getElementById('sm-status');
    const dialog = document.getElementById('sm-dialog');
    const dialogBody = document.getElementById('sm-dialog-body');
    const dialogActions = document.getElementById('sm-dialog-actions');
    const labels = {all:'Distro', chim:'CHIM', stobe:'STOBE', dialectic:'DIALECTIC'};
    const games = {chim:'Skyrim', stobe:'Kenshi', dialectic:'Fallout: New Vegas'};
    // Fixed product map. The retention endpoint is never taken from a URL or user value.
    const serverDirs = {chim:'HerikaServer', stobe:'StobeServer', dialectic:'DialecticServer'};
    // Minimum in-game days behind before loading an older game save makes a Playthrough Save.
    const defaultMinDays = {chim:3, stobe:1, dialectic:3};
    const query = new URLSearchParams(location.search);
    let mod = query.get('mod') || 'all';
    let view = query.get('view') || '';
    if (mod === 'shared') { mod = 'all'; view = 'backups'; }
    if (!labels[mod]) mod = 'all';
    view = ({manage:'playthroughs', storage:'cleanup', databases:'backups'})[view] || view;
    if (location.hash === '#retention-section' && serverDirs[mod]) view = 'cleanup';
    // Legacy per-mod backup URLs open the shared Distro archives.
    if (view === 'backups') mod = 'all';
    const views = mod === 'all' ? {overview:'Overview',backups:'Backups',advanced:'Advanced'}
        : {playthroughs:'Playthrough Saves',cleanup:'Cleanup',advanced:'Advanced'};
    if (!views[view]) view = Object.keys(views)[0];
    let search = (query.get('q') || '').slice(0,120);
    let offset = Math.max(0, Math.min(1000000, Number(query.get('offset')) || 0));
    let busy = false, dirty = false, generation = 0, capabilities = null;
    // Bulk selection covers the visible page only; load() clears it on paging and searching.
    const selected = new Map();
    const bulkLimit = 50;
    const retentionUrl = serverDirs[mod]
        ? config.prefix + '/' + serverDirs[mod] + '/ui/api/playthrough_retention.php?summary=1' : null;

    // DOM-only rendering keeps names, notes, SQL filenames and server messages inert.
    function el(tag, text, cls) {
        const node = document.createElement(tag);
        if (text !== undefined && text !== null) node.textContent = String(text);
        if (cls) node.className = cls;
        return node;
    }
    function button(text, handler, cls = '') {
        const node = el('button', text, cls);
        node.type = 'button';
        node.addEventListener('click', handler);
        return node;
    }
    function link(text, targetMod, targetView, cls = 'sm-button') {
        const node = el('a', text, cls);
        node.href = '?mod=' + targetMod + '&view=' + targetView;
        return node;
    }
    function note(text, cls = 'sm-help') { return el('p', text, cls); }
    function bytes(value) {
        if (value === null || value === undefined) return 'Not recorded';
        const n = Math.max(0, Number(value) || 0);
        const unit = n ? Math.min(4, Math.floor(Math.log(n) / Math.log(1024))) : 0;
        return (n / 1024 ** unit).toLocaleString(undefined, {maximumFractionDigits:1}) + ' ' + ['B','KB','MB','GB','TB'][unit];
    }
    function number(value) { return value === null || value === undefined ? 'Not recorded' : Number(value).toLocaleString(); }
    function date(value) {
        if (!value) return 'Not recorded';
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? String(value) : parsed.toLocaleString(undefined, {dateStyle:'medium',timeStyle:'short'});
    }
    function announce(text, kind = '') { status.textContent = text; status.className = 'sm-status ' + kind; }
    function toolbar(title, description, searchable = false) {
        const row = el('div', null, 'sm-toolbar');
        const heading = el('div', null, 'sm-toolbar-heading'); heading.append(el('h2', title), note(description)); row.append(heading);
        if (searchable) {
            const form = el('form'); const input = el('input');
            input.type = 'search'; input.value = search; input.maxLength = 120;
            input.placeholder = 'Search ' + title.toLowerCase(); input.setAttribute('aria-label', input.placeholder);
            const submit = el('button','Search'); submit.type = 'submit'; form.append(input, submit);
            form.addEventListener('submit', event => { event.preventDefault(); search = input.value.trim(); offset = 0; load(); });
            row.append(form);
        }
        return row;
    }
    function panel(title) {
        const node = el('section', null, 'sm-panel');
        if (title) node.append(el('h3',title,null));
        return node;
    }
    function metrics(items) {
        const list = el('dl', null, 'sm-metrics');
        items.forEach(([label,value,small]) => { const item = el('div', null, 'sm-metric'); item.append(el('dt',label),el('dd',value,small ? 'sm-small' : '')); list.append(item); });
        return list;
    }
    function table(headers, rows) {
        const wrap = el('div', null, 'sm-table-wrap'), grid = el('table', null, 'sm-table');
        wrap.tabIndex = 0;
        wrap.setAttribute('role', 'region');
        wrap.setAttribute('aria-label', headers[0] + ' results');
        const head = el('thead'), hr = el('tr'), body = el('tbody');
        headers.forEach(text => { const th = el('th',text); th.scope = 'col'; hr.append(th); });
        head.append(hr);
        rows.forEach(cells => {
            const row = el('tr');
            cells.forEach((value, i) => { const td = el('td'); td.dataset.label = headers[i]; td.append(value instanceof Node ? value : document.createTextNode(String(value))); row.append(td); });
            body.append(row);
        });
        grid.append(head,body); wrap.append(grid); return wrap;
    }
    function pager(list) {
        const node = el('div', null, 'sm-pager');
        node.append(note(list.total ? (list.offset + 1) + '–' + Math.min(list.offset + list.limit,list.total) + ' of ' + number(list.total) : '0 results'));
        const actions = el('div',null,'sm-actions');
        const prev = button('Previous', () => { offset = Math.max(0,offset - list.limit); load(); });
        const next = button('Next', () => { offset += list.limit; load(); });
        prev.disabled = offset === 0; next.disabled = offset + list.limit >= list.total;
        actions.append(prev,next); node.append(actions); return node;
    }
    function openDialog(title, body, actions = []) {
        document.getElementById('sm-dialog-title').textContent = title;
        dialogBody.replaceChildren(...body);
        dialogActions.replaceChildren(button('Cancel', () => dialog.close()), ...actions);
        if (!dialog.open) dialog.showModal();
    }
    document.getElementById('sm-dialog-close').addEventListener('click', () => { if (!busy) dialog.close(); });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
    window.addEventListener('beforeunload', event => { if (busy || dirty) { event.preventDefault(); event.returnValue = ''; } });
    document.addEventListener('click', event => {
        const anchor = event.target.closest('a');
        if (busy && anchor) { event.preventDefault(); announce('Wait for the current operation to finish.'); }
    });
    function confirmAction(title, description, run, danger = true, extra = []) {
        openDialog(title, [note(description, 'sm-muted'), ...extra],
            [button(title, () => perform(run), danger ? 'sm-danger' : 'sm-primary')]);
    }
    async function request(url, options = {}) {
        const response = await fetch(url, {credentials:'same-origin', ...options});
        if (response.headers.get('Content-Disposition')?.includes('attachment')) {
            await response.body?.cancel();
            throw new Error('Use the Download button to save this backup through your browser.');
        }
        let data;
        try { data = await response.json(); } catch { throw new Error('The server response could not be read (HTTP ' + response.status + '). Check Server Logs before repeating an operation.'); }
        if (!response.ok || data.ok !== true) {
            const error = new Error(data.error || data.message || 'The request failed.');
            error.details = data.details; throw error;
        }
        return data;
    }
    function action(operation, fields = {}, targetMod = mod) {
        if (operation === 'download_backup' || operation === 'export_backup') {
            // A native POST streams the attachment through the browser download
            // manager while keeping the same CSRF token and selected mod scope.
            const form = el('form'); form.method = 'POST'; form.action = 'api/storage_action.php'; form.target = '_blank';
            form.hidden = true;
            const values = {mod:targetMod,operation,_sm_csrf:config.csrf,
                _sm_scope:targetMod === 'all' ? 'Distro PostgreSQL server' : labels[targetMod] + ' database',
                ...fields,native_download:'1'};
            Object.entries(values).forEach(([key,value]) => {
                const input = el('input'); input.type = 'hidden'; input.name = key; input.value = value; form.append(input);
            });
            document.body.append(form); form.submit(); form.remove();
            return Promise.resolve({ok:true,download_requested:true,message:'Download requested. Your browser will show progress. If it cannot start, the download tab will explain why.'});
        }
        const body = new FormData();
        body.set('mod',targetMod); body.set('operation',operation); body.set('_sm_csrf',config.csrf);
        body.set('_sm_scope',targetMod === 'all' ? 'Distro PostgreSQL server' : labels[targetMod] + ' database');
        Object.entries(fields).forEach(([key,value]) => body.set(key,value));
        return request('api/storage_action.php', {method:'POST',body});
    }
    function retention(actionName, fields = {}) {
        if (!retentionUrl) return Promise.reject(new Error('These controls are not available for ' + labels[mod] + ' on this server.'));
        return request(retentionUrl, {method:'POST',body:new URLSearchParams({csrf_token:config.retentionCsrf,action:actionName,...fields})});
    }
    // preview, preview_delete and their results share one envelope shape.
    function previewOf(result) { return result?.preview || (result?.token ? result : null); }
    // Mutations are never retried automatically. Keep failures visible and scoped.
    async function perform(run, refresh = true) {
        if (busy) return;
        busy = true;
        const buttons = [...document.querySelectorAll('button,input,select,textarea')];
        const prior = buttons.map(node => node.disabled); buttons.forEach(node => node.disabled = true);
        announce('Working… Keep this page open. Large databases can take several minutes.');
        try {
            const data = await run();
            dialog.close(); if (!previewOf(data)) dirty = false;
            announce(data.message || 'Done.', 'sm-success');
            if (data.details?.length) showResult(data);
            if (refresh && !data.download_requested) await load();
            return data;
        } catch (error) {
            dialog.close(); announce(error.message, 'sm-error');
            if (error.details?.length) showResult({message:error.message,details:error.details});
        } finally {
            busy = false;
            buttons.forEach((node,i) => { if (node.isConnected) node.disabled = prior[i]; });
        }
    }
    function showResult(data) {
        const details = el('pre',null,'sm-result');
        details.textContent = data.details.map(item => (item.label || item.database || item.name || '') + ': ' + (item.ok ? 'Completed' : 'Failed') + '\n' + (item.output || '')).join('\n\n');
        openDialog('Operation results',[note(data.message),details]);
        dialogActions.replaceChildren(button('Close',()=>dialog.close()));
    }
    function field(label, name, type = 'text', value = '', help = '') {
        const wrap = el('div',null,'sm-field'), input = el(type === 'textarea' ? 'textarea' : 'input');
        input.id = 'sm-field-' + name; input.name = name;
        if (type !== 'textarea') input.type = type;
        const labelNode = el('label',label); labelNode.htmlFor = input.id;
        if (type === 'checkbox') { input.checked = value === true; labelNode.className = 'sm-check'; labelNode.prepend(input); wrap.append(labelNode); }
        else { input.value = value; wrap.append(labelNode,input); }
        if (help) { const hint = note(help); hint.id = input.id + '-help'; input.setAttribute('aria-describedby',hint.id); wrap.append(hint); }
        return {wrap,input};
    }
    function choiceField(label, name, options, value, help = '') {
        const wrap = el('div',null,'sm-field'), input = el('select');
        input.id = 'sm-field-' + name; input.name = name;
        options.forEach(([key,text]) => { const option = el('option',text); option.value = key; input.append(option); });
        input.value = options.some(([key]) => key === value) ? value : options[0][0];
        const labelNode = el('label',label); labelNode.htmlFor = input.id;
        wrap.append(labelNode,input);
        if (help) { const hint = note(help); hint.id = input.id + '-help'; input.setAttribute('aria-describedby',hint.id); wrap.append(hint); }
        return {wrap,input};
    }
    function integerField(label, name, value, min, max, help = '') {
        const f = field(label,name,'number',value,help);
        f.input.min = min; f.input.max = max; f.input.step = 1; f.input.required = true;
        f.input.inputMode = 'numeric';
        return f;
    }

    async function overview(ticket) {
        const grid = el('div',null,'sm-grid');
        content.replaceChildren(toolbar('Your mod databases','Choose a mod to manage its Playthrough Saves and cleanup settings.'),grid);
        await Promise.all(['chim','stobe','dialectic'].map(async key => {
            const card = panel(labels[key]); grid.append(card); card.append(note('Loading…'));
            try {
                const data = await request('api/data_manager.php?mod=' + key);
                if (ticket !== generation) return;
                card.replaceChildren(el('h3',labels[key]),note(data.game),metrics([['Database',bytes(data.live.database_bytes)],['Playthrough Saves',number(data.playthroughs.all_total)]]),
                    note('Active Playthrough Save: ' + (data.live.loaded_playthrough || 'None recorded')),el('br'),link('Manage ' + labels[key],key,'playthroughs'));
            } catch (error) { card.replaceChildren(el('h3',labels[key]),note(error.message,'sm-error'),link('Open tools',key,'playthroughs')); }
        }));
        if (ticket !== generation) return;
        const shared = panel('Backups for the whole setup');
        shared.style.marginTop = '16px';
        shared.append(note('New automatic archives include every PostgreSQL database and server role. Inspect a backup before restoring it; scope is checked from the file.'),el('br'),link('Manage database backups','all','backups'));
        content.append(shared);
    }
    function playthroughDetails(playthrough) {
        const details = el('div',null,'sm-details'), list = el('dl');
        const entries = [['Name',playthrough.name],['Player',playthrough.player || 'Not recorded'],['Saved on',date(playthrough.created_at)],['In-game date',playthrough.game_date || 'Not recorded'],
            ['Events at save',number(playthrough.event_count)],['Size at save',bytes(playthrough.size_bytes)],['Save type',playthrough.kind],['Notes',playthrough.notes || 'No notes']];
        if (mod === 'stobe') {
            let members = playthrough.members;
            if (typeof members === 'string') { try { members = JSON.parse(members); } catch { members = []; } }
            if (Array.isArray(members)) entries.push(['Faction members',members.map(member => typeof member === 'string' ? member : member.name || member.actor_name || 'Unnamed').join(', ') || 'Not recorded']);
        }
        entries.forEach(([label,value]) => list.append(el('dt',label),el('dd',value))); details.append(list);
        const actions = [];
        if (capabilities?.cleanup_api && !playthrough.loaded && playthrough.name.toLowerCase() !== 'default') {
            actions.push(button(playthrough.pinned ? 'Remove protection' : 'Protect from deletion', () => confirmAction(playthrough.pinned ? 'Remove protection' : 'Protect from deletion',
                playthrough.pinned ? 'Allow this save to be deleted. Automatic saves can also be removed by cleanup.' : 'Protect this save from deletion, including automatic cleanup.',
                () => retention('pin',{profile_id:playthrough.id,pinned:playthrough.pinned ? '0':'1'}).then(() => ({ok:true,message:'Playthrough Save protection updated.'})), playthrough.pinned)));
        }
        openDialog(playthrough.name,[details],actions);
        dialogActions.firstChild.textContent = 'Close';
    }
    function newPlaythrough(setup = false) {
        if (setup) {
            confirmAction('Set up Playthrough Saves','Save the current database as the protected default Playthrough Save. This does not create a game save.',
                () => action('setup'),false); return;
        }
        const form = el('form',null,'sm-form'), name = field('Playthrough Save name','name'), notes = field('Notes (optional)','notes','textarea');
        name.input.required = true; name.input.maxLength = 128; notes.input.maxLength = 4000;
        form.append(name.wrap,notes.wrap,note('Save a copy of your current mod data. Use a name that helps you find the matching game save.'));
        form.addEventListener('submit',event=>{event.preventDefault();if(form.reportValidity())perform(()=>action('create_playthrough',{name:name.input.value,notes:notes.input.value}));});
        openDialog('New Playthrough Save',[form],[button('Create Playthrough Save',()=>form.requestSubmit(),'sm-primary')]); name.input.focus();
    }
    // The backend re-checks locks and protection; this only proposes the visible selection.
    async function deleteSelected() {
        const ids = [...selected.keys()].slice(0,bulkLimit);
        if (!ids.length) return;
        const plan = previewOf(await perform(()=>retention('preview_delete',{profile_ids:JSON.stringify(ids)}),false));
        if (!plan) return;
        const chosen = plan.playthroughs || [];
        if (!chosen.length) {
            announce('None of the selected Playthrough Saves can be deleted. Active, default and protected saves are always kept.','sm-warning');
            return;
        }
        const names = el('ul',null,'sm-name-list');
        chosen.forEach(item => {
            const row = el('li',item.name);
            if (item.bytes !== null && item.bytes !== undefined) row.append(el('span',' \u00b7 ' + bytes(item.bytes),'sm-help'));
            names.append(row);
        });
        const extra = [names];
        const skipped = ids.length - chosen.length;
        if (skipped > 0) extra.push(note(skipped + (skipped === 1 ? ' selected Playthrough Save is' : ' selected Playthrough Saves are') + ' active, default or protected, and will be kept.','sm-warning'));
        if (plan.message) extra.push(note(plan.message));
        extra.push(note('Your game saves and the live ' + labels[mod] + ' database are not deleted. Freed space becomes reusable inside the database.'));
        const total = chosen.reduce((sum,item) => sum + (Number(item.bytes) || 0),0);
        confirmAction('Delete ' + chosen.length + ' Playthrough Save' + (chosen.length === 1 ? '' : 's'),
            'Permanently delete these ' + labels[mod] + ' Playthrough Saves (' + bytes(total) + '). This cannot be undone.',
            ()=>retention('run',{preview_token:plan.token}).then(result=>({ok:true,message:result.result?.message || 'Selected Playthrough Saves deleted.'})),true,extra);
    }
    function bulkBar(eligible, boxes) {
        const bar = el('div',null,'sm-bulk');
        const wrap = el('label',null,'sm-check'), all = el('input');
        all.type = 'checkbox'; all.disabled = !eligible.length;
        wrap.append(all,el('span','Select all on this page'));
        const count = el('p',null,'sm-help sm-bulk-count');
        count.setAttribute('aria-live','polite');
        const remove = button('Delete selected',()=>deleteSelected(),'sm-danger');
        const sync = () => {
            const picked = selected.size;
            remove.disabled = picked === 0;
            count.textContent = picked ? picked + ' selected on this page' : (eligible.length ? 'Nothing selected' : 'Nothing on this page can be deleted');
            all.checked = eligible.length > 0 && eligible.every(item => selected.has(item.id));
            all.indeterminate = !all.checked && eligible.some(item => selected.has(item.id));
            boxes.forEach(entry => { entry.box.checked = selected.has(entry.item.id); });
        };
        all.addEventListener('change',()=>{
            selected.clear();
            if (all.checked) eligible.slice(0,bulkLimit).forEach(item => selected.set(item.id,item.name));
            sync();
        });
        bar.append(wrap,count,remove);
        return {bar,sync};
    }
    async function playthroughs(data, ticket) {
        const list = data.playthroughs, top = toolbar('Playthrough Saves','Save and restore your mod data. Use each Playthrough Save with its matching game save.',true);
        top.append(button('New Playthrough Save',()=>newPlaythrough(),'sm-primary'));
        content.replaceChildren(top);
        if (!list.metadata_available && mod !== 'stobe') {
            content.append(note('Playthrough Saves have not been set up for this database yet.','sm-warning'),button('Set up Playthrough Saves',()=>newPlaythrough(true),'sm-primary')); return;
        }
        const summary = panel(); summary.classList.add('sm-summary');
        summary.append(metrics([['Active Playthrough Save',data.live.loaded_playthrough || 'None recorded',true],['Total Playthrough Saves',number(list.all_total)],['Database',bytes(data.live.database_bytes)]]));
        content.append(summary);
        const autoHost = panel();
        autoHost.classList.add('sm-settings-group','sm-auto-saves');
        if (capabilities?.cleanup_api) { autoHost.append(el('h3','Automatic Playthrough Saves'),note('Loading saved settings\u2026')); content.append(autoHost); }
        if (!list.items.length) content.append(note(search ? 'No Playthrough Saves match your search.' : 'No Playthrough Saves yet. Save one before making major changes.','sm-empty'));
        else {
            const selectable = capabilities?.cleanup_api === true;
            const eligible = list.items.filter(item => !item.protected && item.storage_type === 'schema'), boxes = [];
            const bulk = selectable && eligible.length ? bulkBar(eligible,boxes) : null;
            const rows = list.items.map(playthrough => {
                const name = el('div',null,selectable ? 'sm-pick' : null);
                if (selectable) {
                    const box = el('input');
                    box.type = 'checkbox'; box.className = 'sm-pick-box';
                    box.checked = selected.has(playthrough.id);
                    box.disabled = playthrough.protected || playthrough.storage_type !== 'schema' || !bulk;
                    box.setAttribute('aria-label','Select ' + playthrough.name);
                    if (playthrough.protected) box.title = 'Active, default and protected Playthrough Saves cannot be deleted.';
                    box.addEventListener('change',()=>{
                        if (box.checked) {
                            if (!selected.has(playthrough.id) && selected.size >= bulkLimit) {
                                box.checked = false;
                                announce('Select up to ' + bulkLimit + ' Playthrough Saves at a time.','sm-warning');
                                return;
                            }
                            selected.set(playthrough.id,playthrough.name);
                        } else selected.delete(playthrough.id);
                        bulk?.sync();
                    });
                    boxes.push({box,item:playthrough});
                    name.append(box);
                }
                const text = el('div',null,'sm-pick-text');
                text.append(el('div',playthrough.name,'sm-name'),note(playthrough.player || 'Player not recorded'));
                const badges = el('div',null,'sm-badges');
                if (playthrough.loaded) badges.append(el('span','Active','sm-badge sm-loaded'));
                else if (playthrough.protected) badges.append(el('span','Protected','sm-badge'));
                badges.append(el('span',playthrough.kind,'sm-badge'));
                text.append(badges);
                name.append(text);
                const when = el('div');
                when.append(note(date(playthrough.created_at),''),note(playthrough.game_date || 'In-game date not recorded'));
                const actions = el('div',null,'sm-actions');
                actions.append(button('Details',()=>playthroughDetails(playthrough)));
                const restore = button('Restore',()=>confirmAction('Restore this Playthrough Save',
                    'Replace your current ' + labels[mod] + ' data with \u201c' + playthrough.name + '\u201d. Stop the game first. After restoring, load the matching game save.',
                    ()=>action('restore_playthrough',{profile_id:playthrough.id}),true,
                    [note(mod === 'stobe' ? 'STOBE saves your current progress as a new Before-Switch Save first.' : 'Your current progress replaces the contents of the active Playthrough Save first. If no active save is found, the restore is blocked.','sm-warning')]));
                restore.disabled = playthrough.loaded;
                const remove = button('Delete',()=>confirmAction('Delete Playthrough Save','Permanently delete \u201c' + playthrough.name + '\u201d from ' + labels[mod] + '. Your current mod data and game saves are kept.',
                    ()=>action('delete_playthrough',{profile_id:playthrough.id})), 'sm-danger');
                remove.disabled = playthrough.protected; remove.title = playthrough.protected ? 'Active, default and protected Playthrough Saves cannot be deleted.' : '';
                actions.append(restore,remove);
                return [name,when,bytes(playthrough.size_bytes),actions];
            });
            if (bulk) { content.append(bulk.bar); bulk.sync(); }
            content.append(table(['Playthrough Save','Saved / in-game date','Size at save','Actions'],rows),pager(list));
        }
        if (!capabilities?.cleanup_api) return;
        let state;
        try { state = await request(retentionUrl); }
        catch (error) { state = {error:error.message}; }
        if (ticket !== generation) return;
        autoSaves(autoHost,state,ticket);
    }
    // Automatic Playthrough Saves are triggered by loading an older game save, never on a timer.
    function autoSaves(host, state, ticket) {
        host.replaceChildren(el('h3','Automatic Playthrough Saves'));
        if (state.error) { host.append(note(state.error,'sm-error'),button('Try again',()=>load())); return; }
        const saved = state.backup_settings && typeof state.backup_settings === 'object' ? state.backup_settings : {};
        const isOn = saved.enabled !== false && saved.enabled !== 0 && saved.enabled !== '0';
        const days = Number(saved.min_days) > 0 ? Number(saved.min_days) : defaultMinDays[mod];
        const line = el('p',null,'sm-status-line ' + (isOn ? 'sm-on' : 'sm-off'));
        line.textContent = isOn
            ? 'On \u00b7 loading a game save at least ' + number(days) + ' in-game ' + (days === 1 ? 'day' : 'days') + ' behind your current progress makes a Playthrough Save first.'
            : 'Off \u00b7 loading an older game save will not make a Playthrough Save first.';
        host.append(line);
        const last = state.last_backup;
        if (last && last.at) {
            const failed = last.status === 'failed';
            host.append(note((failed ? 'Last automatic save failed \u00b7 ' : 'Last automatic save \u00b7 ') + date(last.at),failed ? 'sm-error' : 'sm-help'));
            if (last.message) host.append(note(last.message,failed ? 'sm-error' : 'sm-help'));
        } else host.append(note('No automatic saves recorded yet.'));
        const form = el('form',null,'sm-form'), row = el('div',null,'sm-grid sm-two');
        const enabled = field('Save when loading an older game save','backup_enabled','checkbox',isOn,
            'Saves ' + labels[mod] + ' data only. These settings stay the same after a restore.');
        const minDays = integerField('Game days behind','backup_min_days',days,1,3650,
            'Save first if the game save you load is at least this many game days behind. This does not run on a timer.');
        row.append(enabled.wrap,minDays.wrap);
        const save = button('Save settings',()=>form.requestSubmit(),'sm-primary');
        form.append(row,save);
        form.addEventListener('input',()=>{ dirty = true; });
        form.addEventListener('submit',event=>{
            event.preventDefault();
            if (!form.reportValidity()) return;
            // Enabling this only creates saves, so it needs no deletion confirmation.
            perform(()=>retention('save_backup',{enabled:enabled.input.checked ? '1' : '0',min_days:minDays.input.value})
                .then(result=>{
                    const fresh = (result && (result.state || result)) || {};
                    const next = Object.assign({},state,fresh);
                    if (!fresh.backup_settings) next.backup_settings = {enabled:enabled.input.checked,min_days:Number(minDays.input.value)};
                    delete next.error;
                    return {ok:true,message:'Automatic save settings saved.',nextState:next};
                }),false).then(result=>{ if (result?.nextState && ticket === generation) autoSaves(host,result.nextState,ticket); });
        });
        host.append(form);
    }
    async function cleanup(data,ticket) {
        content.replaceChildren(toolbar('Cleanup','Turn on cleanup for the categories you want managed automatically, then save your settings.'));
        if (!capabilities?.cleanup_api) {
            content.append(note('Update this mod server to use cleanup settings.','sm-warning'),link('Manage Playthrough Saves',mod,'playthroughs'));
            return;
        }
        const host = panel(); host.append(note('Loading sizes and settings…')); content.append(host);
        const state = await request(retentionUrl);
        if (ticket !== generation) return;
        retentionForm(host,state,data.live);
    }
    function retentionForm(host,state,storage) {
        host.replaceChildren();
        const settings = state.settings || {}, caps = state.capabilities || {};
        const categories = (caps.categories || []).filter(item=>item && item.key);
        const measured = new Map((storage.categories || []).map(item=>[item.key,item]));
        // Show each category's share of this mod's total database storage.
        const categorySize = value => {
            const amount = Number(value), total = Number(storage?.database_bytes);
            if (value == null || storage?.database_bytes == null || !Number.isFinite(amount) || !Number.isFinite(total) || amount < 0 || total < 0 || (total === 0 && amount > 0)) return bytes(value);
            const percent = total > 0 ? amount / total * 100 : 0;
            const share = percent > 0 && percent < 0.1 ? '<0.1' : percent.toLocaleString(undefined, {maximumFractionDigits:1});
            return bytes(value) + ' (' + share + '%)';
        };
        const form = el('form',null,'sm-form'), inputs = {};
        const num = (key,fallback) => Number.isFinite(Number(settings[key])) ? Number(settings[key]) : fallback;
        const flag = key => settings[key] === true || settings[key] === 1 || settings[key] === '1';
        const keepInput = (entry,key) => { inputs[key] = entry.input; return entry.wrap; };
        const values = () => Object.fromEntries(Object.entries(inputs)
            .map(([key,input])=>[key,input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value]));
        // Open the row containing an invalid field before the browser focuses its message.
        const valid = container => {
            for (const input of container.querySelectorAll('input,select')) {
                if (!input.checkValidity()) { const row=input.closest('details'); if(row)row.open=true; input.reportValidity(); return false; }
            }
            return true;
        };
        const total = el('div',null,'sm-storage-total');
        total.append(el('h3','Playthrough Storage'),el('strong',bytes(storage.database_bytes)));
        form.append(total);
        const rowFor = (key,label,description) => {
            const row = el('details',null,'sm-cleanup-row'), summary = el('summary');
            row.id='sm-cleanup-'+key; row.setAttribute('aria-label',label);
            summary.append(el('strong',label),el('span',categorySize(measured.get(key)?.bytes),'sm-category-size'),el('span','Cleanup settings','sm-expand-hint'));
            const body = el('div',null,'sm-cleanup-body'); body.append(note(description));
            row.append(summary,body); form.append(row); return {row,body};
        };
        for(const category of categories) {
            const key=category.key, entry=rowFor(key,category.label,category.description || 'Troubleshooting logs.');
            const on=field('Clean up automatically',key+'_enabled','checkbox',flag(key+'_enabled'));
            const days=integerField('Older than (days)',key+'_days',num(key+'_days',7),1,3650,'Real-world days.');
            entry.body.append(keepInput(on,key+'_enabled'),keepInput(days,key+'_days'));
            if(key==='requests')entry.body.append(keepInput(choiceField('Request logs to include','requests_filter',
                [['all','All request logs'],['relationship','Relationship requests only']],settings.requests_filter || 'all'),'requests_filter'));
            entry.body.append(note('Logs from the last 24 hours are kept.'));
        }
        if(caps.event_cleanup) {
            const events=rowFor('events','Events',measured.get('events')?.description || 'Raw gameplay and conversation history.');
            events.body.append(keepInput(field('Clean up automatically (off by default)','events_enabled','checkbox',flag('events_enabled')),'events_enabled'));
            events.body.append(keepInput(integerField('Older than (in-game days)','events_days',num('events_days',30),1,3650,
                'Measured from the latest recorded game time.'),'events_days'));
            events.body.append(note('Events recorded in the last 24 real-world hours, unfinished replies and the newest event of each type are kept.'));
            events.body.append(note('Deleting event history can remove details used for NPC recall and future diaries. Existing memories and diaries are kept.','sm-warning'));
        }
        const saves=rowFor('playthroughs','Playthrough Saves',measured.get('playthroughs')?.description || 'Saved copies of your mod data.');
        saves.body.append(keepInput(field('Clean up automatically','playthroughs_enabled','checkbox',flag('playthroughs_enabled')),'playthroughs_enabled'));
        saves.body.append(keepInput(integerField('Maximum automatic saves','playthrough_keep',num('playthrough_keep',0),0,10000,
            '0 = Unlimited. Above the limit, the oldest automatic saves are deleted first.'),'playthrough_keep'));
        saves.body.append(note('Manual, unclassified, active, default and protected saves are kept.'));
        const saveActions=el('div',null,'sm-actions');saveActions.append(link('Manage saves',mod,'playthroughs'));saves.body.append(saveActions);

        const kept=el('section',null,'sm-kept-data');kept.append(el('h3','Data kept by cleanup'));
        for(const category of (storage.categories || []).filter(item=>!item.cleanup)) {
            const row=el('div',null,'sm-kept-row');
            row.append(el('strong',category.label),el('span',categorySize(category.bytes)),note(category.description || 'Not included in cleanup.'));
            kept.append(row);
        }
        const sizes=el('details',null,'sm-size-help');sizes.append(el('summary','About these sizes'),
            note('Percentages show each category\'s share of this mod\'s total database storage. Category sizes include indexes and unused database space. Cleanup frees space for reuse but may not reduce files on disk.'));
        form.append(kept,sizes);
        form.append(note('Automatic cleanup runs at most once an hour while the '+labels[mod]+' background service is running.'));
        const last=state.last_run;
        if(last)form.append(note('Last cleanup · '+date(last.at)+' · '+(last.message || last.status),last.status==='failed'?'sm-error':'sm-help'));
        else form.append(note('No cleanup has run yet.'));
        const actions=el('div',null,'sm-actions');
        actions.append(button('Save settings',()=>form.requestSubmit(),'sm-primary'));
        form.append(actions);host.append(form);
        form.noValidate=true;
        form.addEventListener('input',()=>{dirty=true;});
        form.addEventListener('submit',event=>{
            event.preventDefault();if(!valid(form))return;
            const chosen=values();
            const run=()=>retention('save',chosen).then(()=>({ok:true,message:'Cleanup settings saved.'}));
            if(chosen.events_enabled==='1'&&(!flag('events_enabled')||Number(chosen.events_days)<num('events_days',30)))confirmAction('Save Events cleanup rules',
                'Matching older events will be deleted during background cleanup. Your active Playthrough Save is kept.' + ' Deleting event history can remove details used for NPC recall and future diaries. Existing memories and diaries are kept.',run,true);
            else perform(run,false);
        });
    }
    async function previewRestore(fields, scope = mod) {
        if (scope === 'all' && !fields.destination) {
            const select = el('select'); select.id = 'sm-restore-destination';
            [['chim','CHIM'],['dialectic','DIALECTIC']].forEach(([value,text]) => { const option=el('option',text);option.value=value;select.append(option); });
            const label=el('label','Destination if the file does not identify a database');label.htmlFor=select.id;
            const field=el('div',null,'sm-field');field.append(label,select,note('Connection markers and recognized filenames take precedence.'));
            openDialog('Inspect backup',[note(fields.filename || fields.backup?.name),field],[button('Inspect backup',()=>previewRestore({...fields,destination:select.value},scope),'sm-primary')]);
            return;
        }
        const result = await perform(()=>action('preview_restore',fields,scope),false);
        if(!result?.preview)return;
        const preview=result.preview;
        confirmAction('Restore database backup','Replace '+preview.scope+' using “'+preview.filename+'” ('+bytes(preview.bytes)+'). Stop all affected games and servers first.',
            ()=>action('restore_backup',{...fields,preview_token:preview.token},scope),true,
            [note('Use only backups you trust. SQL backups contain commands that run on your database. This is not a game save.','sm-warning'),
             note(preview.combined ? 'Shared restore is not all-or-nothing. If it fails, some databases may already have changed. Keep a current backup of every affected mod.' : 'A supported STOBE pg_dump backup is restored in one transaction. Load the matching Kenshi save afterward.','sm-warning')]);
    }
    function uploadBackup(scope = mod) {
        const form=el('form',null,'sm-form'), file=field('Backup file','backup','file','',scope==='stobe'?'STOBE .sql or .sql.gz only. Combined archives belong in the Distro archives list.':'Plain .sql only. The next step inspects which databases it contains.');
        file.input.accept=scope==='stobe'?'.sql,.gz':'.sql';file.input.required=true;form.append(file.wrap);
        form.addEventListener('submit',event=>{event.preventDefault();if(form.reportValidity())previewRestore({source:'upload',backup:file.input.files[0]},scope);});
        openDialog('Restore from a file',[form],[button('Inspect backup',()=>form.requestSubmit(),'sm-primary')]);
    }
    async function backups(ticket) {
        const scope = 'all';
        const top = toolbar('Database backups','Back up the entire PostgreSQL server, including every database and server role. Game saves and server files are not included.',true);
        const actions=el('div',null,'sm-actions');
        actions.append(button('Export entire database',()=>confirmAction('Export entire database',
            'Download one SQL file containing every PostgreSQL database, all schemas, tables, Playthrough Saves and server roles. This includes databases for other mods and tests. Game saves and server files are not included.',
            ()=>action('export_backup',{},scope),false),'sm-primary'),button('Restore database backup',()=>uploadBackup(scope)));
        top.append(actions);
        content.replaceChildren(top,note('Full PostgreSQL backups are restored with psql to a clean PostgreSQL instance. The restore tool below is for older mod-only SQL backups.'));
        const data=await request('api/storage_tools.php?'+new URLSearchParams({mod:scope,view:'backups',q:search,offset}));
        if(ticket!==generation)return;
        if(data.automatic) {
            const box=panel('Automatic database backups'), form=el('form',null,'sm-form');
            const enabled=field('Create automatic backups','enabled','checkbox',data.automatic.enabled,'Off by default. New archives include every PostgreSQL database and server role, including other mods and test databases.');
            const keep=field('Backups to keep','keep','number',data.automatic.keep,'Old automatic backup files are removed as new backups are created.');
            keep.input.min=1;keep.input.max=10;keep.input.step=1;keep.input.required=true;
            const row=el('div',null,'sm-grid sm-two');row.append(enabled.wrap,keep.wrap);form.append(row,button('Save backup settings',()=>form.requestSubmit()));
            form.addEventListener('input',()=>dirty=true);
            form.addEventListener('submit',event=>{event.preventDefault();if(form.reportValidity())confirmAction('Save backup settings',
                (enabled.input.checked?'Enable automatic backups':'Disable automatic backups')+' and keep '+keep.input.value+' automatic archives. Reducing the limit can remove older backup files when the next archive is created.',
                ()=>action('save_backup_settings',{enabled:enabled.input.checked?'1':'0',keep:keep.input.value}),false);});
            box.append(form);content.append(box);
        }
        const list=data.backups;
        if(!list.items.length){content.append(note(search?'No backups match your search.':'No backup files found in the server’s backup folders.','sm-empty'));return;}
        content.append(table(['Backup file','Saved on','Size','Scope hint','Actions'],list.items.map(item=>{
            const name=el('div');name.append(el('div',item.filename,'sm-name'),note(item.source==='automatic'?'Automatic archive':'Server import folder'));
            const fields={filename:item.filename,source:item.source}, actions=el('div',null,'sm-actions');
            if(item.can_restore !== false)actions.append(button('Restore',()=>previewRestore(fields,scope)));
            else actions.append(note('Restore with PostgreSQL.'));
            if(item.can_download)actions.append(button('Download',()=>perform(()=>action('download_backup',fields,scope),false)));
            if(item.can_delete)actions.append(button('Delete',()=>confirmAction('Delete backup file','Permanently delete “'+item.filename+'”. This does not change the live database.',
                ()=>action('delete_backup',fields,scope)),'sm-danger'));
            return[name,date(item.modified*1000),bytes(item.size),item.scope,actions];
        })),note('Scope hints come from filenames. Restore inspects the file contents before asking you to confirm.'),pager(list));
    }
    async function advanced(ticket) {
        content.replaceChildren(toolbar('Advanced','Maintenance and repair tools. Stop the affected game before making database changes.'));
        const box=panel('Database tools'), actions=el('div',null,'sm-actions');
        if(mod==='all') {
            actions.append(button('Compact mods',()=>confirmAction('Compact mods','Reclaim unused database space for CHIM, STOBE and DIALECTIC. This locks tables and can take a long time. Stop Skyrim, Kenshi, Fallout: New Vegas and their servers first.',
                ()=>action('maintenance')),'sm-danger'));
            box.append(note('Shared maintenance can reclaim unused disk space. It does not choose or delete old events.'),el('br'),actions);
            content.append(box);
            const grid=el('div',null,'sm-grid');['chim','stobe','dialectic'].forEach(key=>{const p=panel(labels[key]);p.append(note('Version entries and supported repairs for this mod.'),el('br'),link('Open '+labels[key]+' tools',key,'advanced'));grid.append(p);});content.append(grid);return;
        }
        if(mod==='stobe') {
            actions.append(button('Analyze database',()=>confirmAction('Analyze STOBE database','Run VACUUM ANALYZE to update database statistics and make deleted-row space reusable. It does not shrink database files.',
                ()=>action('vacuum_analyze'),false)),button('Rebuild indexes',()=>confirmAction('Rebuild STOBE indexes','Rebuild database indexes. This can block database activity; stop Kenshi and STOBE first.',()=>action('reindex_database'))));
        }
        if(mod==='chim') {
            actions.append(button('Repair Oghma table',()=>confirmAction('Repair Oghma table','Remove duplicate CHIM knowledge topics and repair topic uniqueness. Make a database backup first.',()=>action('repair_oghma_table'))),
                button('Repair constraints',()=>confirmAction('Repair CHIM constraints','Repair uniqueness constraints in Oghma and configuration options. Duplicate rows may be removed or backed up by the repair.',()=>action('repair_core_constraints'))));
        }
        const pgAdmin=el('a','Open pgAdmin','sm-button');pgAdmin.href=config.prefix+'/pgAdmin/';pgAdmin.target='_blank';pgAdmin.rel='noopener';actions.append(pgAdmin);
        box.append(actions);content.append(box);
        if(mod!=='dialectic') {
            const danger=el('details',null,'sm-panel sm-details');danger.append(el('summary','Factory reset — destructive'));
            danger.append(note('Deletes live '+labels[mod]+' database content and rebuilds its tables. Make a database backup first. This is not needed for ordinary storage cleanup.'),el('br'),
                button('Factory reset '+labels[mod],()=>confirmAction('Factory reset '+labels[mod],'Permanently reset the live '+labels[mod]+' database. Stop the game and server. This cannot be undone without a backup.',()=>action('factory_reset_database')),'sm-danger'));
            content.append(danger);
        }
        const header=toolbar('Database version entries','Reset an entry only when troubleshooting a database update.',true);
        header.append(button('Reset all versions',()=>confirmAction('Reset all '+labels[mod]+' versions',
            mod==='chim'?'Clear all CHIM version entries and immediately replay its database updates. Back up first.':mod==='stobe'?'Clear all STOBE version entries. Its updates will be reapplied on startup.':'Clear all DIALECTIC version entries. Restart DIALECTIC to apply its own updates.',
            ()=>action('reset_all_db_versions')),'sm-danger'));
        content.append(header);
        const data=await request('api/storage_tools.php?'+new URLSearchParams({mod,view:'advanced',q:search,offset}));
        if(ticket!==generation)return;
        const list=data.versions;
        if(!list.items.length){content.append(note(list.available?'No matching version entries.':'This database has no version entries yet.','sm-empty'));return;}
        content.append(table(['Table','Version','Action'],list.items.map(item=>[item.name,item.version,button('Reset entry',()=>confirmAction('Reset version entry','Reset the '+labels[mod]+' version entry for “'+item.name+'”. The update can be reapplied on startup.',
            ()=>action('reset_db_version',{table:item.name})))])),pager(list));
    }
    async function load() {
        const ticket=++generation;
        // Bulk selection only ever covers the page on screen.
        selected.clear();
        content.setAttribute('aria-busy','true');
        content.replaceChildren(note('Loading '+views[view].toLowerCase()+'…','sm-empty'));
        try {
            if(view==='overview')await overview(ticket);
            else if(view==='backups')await backups(ticket);
            else if(view==='advanced')await advanced(ticket);
            else {
                const data=await request('api/data_manager.php?'+new URLSearchParams({mod,q:search,offset}));
                if(ticket!==generation)return;
                capabilities=data.tools||null;
                if(view==='playthroughs')await playthroughs(data,ticket);else await cleanup(data,ticket);
                if(data.warnings?.length)content.append(note(data.warnings.join(' '),'sm-warning'));
            }
        } catch(error) {
            if(ticket===generation)content.append(note(error.message,'sm-error'),button('Try loading again',()=>load()));
        } finally { if(ticket===generation)content.setAttribute('aria-busy','false'); }
    }
    document.querySelectorAll('[data-mod]').forEach(anchor=>{
        const key=anchor.dataset.mod;anchor.classList.toggle('is-active',key===mod);
        if(key===mod)anchor.setAttribute('aria-current','page');
        anchor.href='?mod='+key+'&view='+(key==='all'?(view==='backups'||view==='advanced'?view:'overview'):(view==='overview'||view==='backups'?'playthroughs':view));
    });
    const tasks=document.getElementById('sm-tasks');
    Object.entries(views).forEach(([key,label])=>{
        const anchor=link(label,mod,key,'sm-task'+(key===view?' is-active':''));
        if(key===view)anchor.setAttribute('aria-current','page');tasks.append(anchor);
    });
    document.getElementById('sm-refresh').addEventListener('click',()=>{
        if(busy)return;
        if(dirty)confirmAction('Discard unsaved settings','Reload the saved settings and discard your edits.',async()=>({ok:true,message:'Saved settings reloaded.'}),false);
        else {announce('');load();}
    });
    load();
})();
