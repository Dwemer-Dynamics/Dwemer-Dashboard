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
    // Backend category keys are fixed; these labels stay stable whatever the server calls them.
    const categoryLabels = {log:'Prompt and response logs', requests:'Request logs',
        recall:'Memory search logs', responses:'Delivered response logs'};
    const query = new URLSearchParams(location.search);
    let mod = query.get('mod') || 'all';
    let view = query.get('view') || '';
    if (mod === 'shared') { mod = 'all'; view = 'backups'; }
    if (!labels[mod]) mod = 'all';
    view = ({manage:'playthroughs', storage:'cleanup', databases:'backups'})[view] || view;
    if (location.hash === '#retention-section' && serverDirs[mod]) view = 'cleanup';
    // Backups live under Distro only; legacy per-mod backup URLs land there with the matching list.
    const backupScope = query.get('scope') === 'stobe' || (view === 'backups' && mod === 'stobe') ? 'stobe' : 'all';
    if (view === 'backups') mod = 'all';
    const views = mod === 'all' ? {overview:'Overview',backups:'Backups',advanced:'Advanced'}
        : {playthroughs:'Playthrough Saves',cleanup:'Cleanup',advanced:'Advanced'};
    if (!views[view]) view = Object.keys(views)[0];
    let search = (query.get('q') || '').slice(0,120);
    let offset = Math.max(0, Math.min(1000000, Number(query.get('offset')) || 0));
    let busy = false, dirty = false, generation = 0, previewTimer = null, capabilities = null;
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
                _sm_scope:targetMod === 'all' ? 'CHIM, STOBE and DIALECTIC databases' : labels[targetMod] + ' database',
                ...fields,native_download:'1'};
            Object.entries(values).forEach(([key,value]) => {
                const input = el('input'); input.type = 'hidden'; input.name = key; input.value = value; form.append(input);
            });
            document.body.append(form); form.submit(); form.remove();
            return Promise.resolve({ok:true,download_requested:true,message:'Download requested. Your browser will show progress. If it cannot start, the download tab will explain why.'});
        }
        const body = new FormData();
        body.set('mod',targetMod); body.set('operation',operation); body.set('_sm_csrf',config.csrf);
        body.set('_sm_scope',targetMod === 'all' ? 'CHIM, STOBE and DIALECTIC databases' : labels[targetMod] + ' database');
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
            dialog.close(); dirty = false;
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
        shared.append(note('Automatic archives can contain all three mod databases, and STOBE’s own backup files are managed here too. Inspect a backup before restoring it; scope is checked from the file.'),el('br'),link('Manage database backups','all','backups'));
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
                playthrough.pinned ? 'This Playthrough Save can then be deleted manually or by eligible automatic cleanup.' : 'Keep this Playthrough Save out of manual and automatic cleanup.',
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
        const list = data.playthroughs, top = toolbar('Playthrough Saves','A Playthrough Save is a saved copy of your mod\u2019s data. Restore it alongside the matching game save.',true);
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
                    [note('A Before-Switch Save of your current database is made before switching.','sm-warning')]));
                restore.disabled = playthrough.loaded;
                const remove = button('Delete',()=>confirmAction('Delete Playthrough Save','Permanently delete \u201c' + playthrough.name + '\u201d from ' + labels[mod] + '. The live database and your game saves are not deleted.',
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
            host.append(note((failed ? 'Last automatic Playthrough Save failed \u00b7 ' : 'Last automatic Playthrough Save \u00b7 ') + date(last.at),failed ? 'sm-error' : 'sm-help'));
            if (last.message) host.append(note(last.message,failed ? 'sm-error' : 'sm-help'));
        } else host.append(note('No automatic save attempt recorded yet.'));
        const form = el('form',null,'sm-form'), row = el('div',null,'sm-grid sm-two');
        const enabled = field('Make automatic Playthrough Saves','backup_enabled','checkbox',isOn,
            'Applies to the ' + games[mod] + ' server on this machine, not to your game saves. This setting stays the same after you restore a Playthrough Save.');
        const minDays = integerField('Minimum in-game days behind','backup_min_days',days,1,3650,
            'There is no timer and nothing is made every few days. A save is only made at the moment you load a game save this far behind.');
        row.append(enabled.wrap,minDays.wrap);
        const save = button('Save automatic save settings',()=>form.requestSubmit(),'sm-primary');
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
                    return {ok:true,message:'Automatic Playthrough Save settings saved.',nextState:next};
                }),false).then(result=>{ if (result?.nextState && ticket === generation) autoSaves(host,result.nextState,ticket); });
        });
        host.append(form);
    }
    function storageBreakdown(data) {
        const box = panel('Where space is used');
        box.append(note(bytes(data.live.database_bytes) + ' total · database storage, not game saves or files on disk'));
        data.live.categories.forEach(item => {
            const row = el('div',null,'sm-category'), bar = el('div',null,'sm-bar'), fill = el('span');
            fill.style.width = Math.min(100,100*item.bytes/Math.max(1,data.live.database_bytes)) + '%'; bar.append(fill);
            row.append(note(item.label,''),bar,el('strong',bytes(item.bytes))); box.append(row);
        });
        const details = el('details',null,'sm-details'); details.append(el('summary','How these numbers work'),
            note('Sizes include table indexes and overhead. “Playthrough Saves & other database storage” is the remaining database size, not an exact Playthrough Save total. Deleting rows makes space reusable; it may not shrink database files.'));
        box.append(details); return box;
    }
    async function cleanup(data,ticket) {
        content.replaceChildren(toolbar('Cleanup','Choose what to keep, preview exactly what would go, then remove it.'),storageBreakdown(data));
        if (!capabilities?.cleanup_api) {
            const box = panel('Manual cleanup');
            const backupsLink = link('Review backups','all','backups');
            if (mod === 'stobe') backupsLink.href += '&scope=stobe';
            box.append(note('Cleanup rules are not available for ' + labels[mod] + ' on this server yet. Remove unwanted Playthrough Saves from the Playthrough Saves tab; database backups are managed under Distro.'),el('br'),
                link('Review Playthrough Saves',mod,'playthroughs'),document.createTextNode(' '),backupsLink);
            content.append(box); return;
        }
        const host = panel('Cleanup settings'); host.append(note('Loading saved settings\u2026')); content.append(host);
        const state = await request(retentionUrl);
        if (ticket !== generation) return;
        retentionForm(host,state);
    }
    function retentionForm(host,state) {
        host.replaceChildren();
        const settings = state.settings && typeof state.settings === 'object' ? state.settings : {};
        const caps = state.capabilities && typeof state.capabilities === 'object' ? state.capabilities : {};
        const categories = (Array.isArray(caps.categories) ? caps.categories : []).filter(item => item && item.key);
        const form = el('form',null,'sm-form'), inputs = {};
        const num = (key,fallback) => { const value = Number(settings[key]); return Number.isFinite(value) ? value : fallback; };
        const flag = key => settings[key] === true || settings[key] === 1 || settings[key] === '1';
        const keepInput = (entry,key) => { inputs[key] = entry.input; return entry.wrap; };
        const values = () => Object.fromEntries(Object.entries(inputs)
            .map(([key,input]) => [key,input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value]));

        // Playthrough Saves have exactly one automatic limit: how many automatic saves to keep.
        const savesGroup = panel('Automatic Playthrough Saves');
        savesGroup.classList.add('sm-settings-group');
        const savesOn = field('Delete extra automatic Playthrough Saves','playthroughs_enabled','checkbox',flag('playthroughs_enabled'),
            'Off by default. Manual Saves and the active, default and protected Playthrough Saves are never deleted automatically.');
        const keep = integerField('Maximum Automatic Playthrough Saves','playthrough_keep',num('playthrough_keep',0),0,10000,
            'The newest are kept. This count is the only limit \u2014 there is no age, storage or minimum-kept setting for Playthrough Saves.');
        const keepState = note('');
        keepState.setAttribute('aria-live','polite');
        const describeKeep = () => {
            const value = Number(keep.input.value);
            keepState.textContent = Number.isFinite(value) && value > 0
                ? 'Keeping the newest ' + number(value) + ' automatic Playthrough Saves.'
                : 'Unlimited \u00b7 no automatic Playthrough Save is removed by this count.';
        };
        keep.input.addEventListener('input',describeKeep); describeKeep();
        savesGroup.append(keepInput(savesOn,'playthroughs_enabled'),keepInput(keep,'playthrough_keep'),keepState,
            note('Automatic Rollback Saves and Before-Switch Saves share this one count.'));

        const diagSection = panel('Diagnostic logs');
        diagSection.classList.add('sm-section');
        diagSection.append(note('Troubleshooting logs only. NPC memories, diaries, relationship history and unfinished work are never listed here.'));
        if (!categories.length) diagSection.append(note('This server reports no diagnostic log types.','sm-empty'));
        else {
            const grid = el('div',null,'sm-grid sm-two');
            categories.forEach(item => {
                const key = String(item.key);
                const group = panel(categoryLabels[key] || item.label || key);
                group.classList.add('sm-settings-group');
                const on = field('Delete old entries',key + '_enabled','checkbox',flag(key + '_enabled'),'Off by default.');
                const days = integerField('Older than (days)',key + '_days',num(key + '_days',7),1,3650,'Real-world days, not in-game days.');
                const size = integerField('Also trim above (MB)',key + '_max_mb',num(key + '_max_mb',0),0,102400,'0 turns off the size target.');
                const pair = el('div',null,'sm-inline-fields');
                pair.append(keepInput(days,key + '_days'),keepInput(size,key + '_max_mb'));
                group.append(keepInput(on,key + '_enabled'),pair);
                if (key === 'requests') {
                    group.append(keepInput(choiceField('Request logs to include','requests_filter',
                        [['all','All request logs'],['relationship','Relationship request logs only']],
                        settings.requests_filter === 'relationship' ? 'relationship' : 'all',
                        'The days and size limits apply to the rows you choose here.'),'requests_filter'));
                }
                grid.append(group);
            });
            diagSection.append(grid);
        }

        const autoGroup = panel('Automatic cleanup');
        autoGroup.classList.add('sm-settings-group');
        autoGroup.append(keepInput(field('Run cleanup automatically','automatic','checkbox',flag('automatic'),
            'Off by default. When on, ' + labels[mod] + ' applies the saved rules in small batches. Events, NPC memories, diaries, relationships and unfinished work are always kept.'),'automatic'));

        const history = el('div',null,'sm-history');
        const last = state.last_run;
        if (last) {
            const outcome = {succeeded:'Cleanup finished',failed:'Cleanup failed',no_work:'Nothing eligible to remove'}[last.status] || 'Previous cleanup result';
            history.append(note(outcome + ' \u00b7 ' + date(last.at),last.status === 'failed' ? 'sm-error' : 'sm-help'));
            if (last.message) history.append(note(last.message,last.status === 'failed' ? 'sm-error' : 'sm-help'));
            if (last.status === 'succeeded' || Number(last.rows) > 0 || Number(last.playthroughs) > 0) {
                history.append(note(number(last.rows || 0) + ' diagnostic log rows and ' + number(last.playthroughs || 0) + ' Playthrough Saves removed.'));
            }
            if (last.more_possible) history.append(note('Another round may be needed. Preview again to check.'));
        } else history.append(note('No cleanup has run yet.'));
        const eventStatus = typeof state.event_status === 'string' ? state.event_status
            : (state.event_status?.description || state.event_status?.message || '');
        if (eventStatus) history.append(note(eventStatus));

        const actions = el('div',null,'sm-actions'), previewArea = el('div');
        const save = button('Save settings',()=>form.requestSubmit(),'sm-primary');
        // Preview uses the values on screen and never saves them or turns automatic cleanup on.
        const preview = button('Preview cleanup',async()=>{
            if (!form.reportValidity()) return;
            const plan = previewOf(await perform(()=>retention('preview',values()),false));
            if (plan) renderPreview(plan,previewArea);
        });
        actions.append(save,preview);
        form.append(savesGroup,diagSection,autoGroup,history,actions);
        host.append(form,previewArea);
        form.addEventListener('input',()=>{
            dirty = true;
            if (previewTimer) clearTimeout(previewTimer);
            if (previewArea.firstChild) previewArea.replaceChildren(note('Settings changed. Preview again to see what these values would remove.','sm-warning'));
        });
        form.addEventListener('submit',event=>{
            event.preventDefault();
            if (!form.reportValidity()) return;
            const chosen = values();
            const run = ()=>retention('save',chosen).then(()=>({ok:true,message:'Cleanup settings saved.'}));
            if (chosen.automatic === '1' && !flag('automatic')) {
                confirmAction('Turn on automatic cleanup',
                    'Automatic cleanup will apply these rules without asking each time. Events, NPC memories, diaries, relationships and unfinished work are kept.',
                    run,true);
            } else perform(run,false);
        });
    }
    function renderPreview(plan,area) {
        const box = panel('Cleanup preview'), diagnostics = plan.diagnostics || [], saves = plan.playthroughs || [];
        box.style.marginTop = '16px';
        box.append(note('This is a one-off preview of the values on screen. Nothing was saved and automatic cleanup was not turned on.'));
        if (plan.message) box.append(note(plan.message));
        if (plan.more_possible) box.append(note('This round reached a batch limit. Another round may be needed afterwards.','sm-warning'));
        if (diagnostics.length) box.append(table(['Diagnostic log','Rows to delete','Estimated size'],
            diagnostics.map(item => [categoryLabels[item.key] || item.label || item.table,number(item.rows),bytes(item.bytes_estimate)])));
        else box.append(note('No diagnostic log rows match these settings.'));
        if (saves.length) {
            const list = el('ul',null,'sm-name-list');
            saves.forEach(item => {
                const row = el('li',item.name);
                if (item.bytes !== null && item.bytes !== undefined) row.append(el('span',' \u00b7 ' + bytes(item.bytes),'sm-help'));
                list.append(row);
            });
            box.append(el('h3','Playthrough Saves to delete (' + saves.length + ')'),list);
        } else box.append(note('No automatic Playthrough Saves match these settings.'));
        const events = plan.events;
        const eventText = typeof events === 'string' ? events : (events?.description || events?.message || '');
        box.append(note(eventText || 'Events, NPC memories, diaries, relationships and unfinished work are kept. Nothing here deletes them.','sm-warning'));
        const run = button('Run this cleanup now',()=>confirmAction('Run this cleanup now',
            'Permanently remove exactly the ' + labels[mod] + ' data listed in this preview. Events, NPC memories, diaries, relationships and the active Playthrough Save are kept.',
            ()=>retention('run',{preview_token:plan.token}).then(result=>({ok:true,message:result.result?.message || 'Cleanup finished.'}))),'sm-danger');
        run.disabled = !(saves.length || diagnostics.some(item => Number(item.rows) > 0));
        if (run.disabled) run.title = 'Nothing in this preview can be removed.';
        box.append(el('br'),run,note('Sizes are estimates. Freed space becomes reusable inside the database; files may not shrink.'));
        area.replaceChildren(box);
        if (previewTimer) clearTimeout(previewTimer);
        const expiry = Date.parse(plan.expires_at);
        if (Number.isFinite(expiry)) {
            previewTimer = setTimeout(()=>{
                run.disabled = true;
                run.title = 'This preview expired.';
                box.append(note('This preview expired. Preview again before running cleanup.','sm-warning'));
            },Math.max(0,expiry - Date.now()));
        }
    }
    async function previewRestore(fields, scope = mod) {
        if (scope === 'all' && !fields.destination) {
            const select = el('select'); select.id = 'sm-restore-destination';
            [['chim','CHIM'],['dialectic','DIALECTIC']].forEach(([value,text]) => { const option=el('option',text);option.value=value;select.append(option); });
            const label=el('label','Destination if the file does not identify a database');label.htmlFor=select.id;
            const field=el('div',null,'sm-field');field.append(label,select,note('Connection markers and recognized filenames take precedence. Use the STOBE backups list for an unlabeled STOBE-only dump.'));
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
        const scope = backupScope;
        const top = toolbar('Database backups','Separate SQL files for recovering database data. Game saves and server files are not included.',true);
        const actions=el('div',null,'sm-actions');
        actions.append(button(scope==='all'?'Export CHIM + STOBE':'Create STOBE backup',()=>confirmAction(scope==='all'?'Export CHIM + STOBE':'Create STOBE backup',
            scope==='all'?'Download a backup containing CHIM and STOBE. This existing manual export does not include DIALECTIC.':'Save a STOBE database backup on the server.',
            ()=>action(scope==='all'?'export_backup':'create_backup',{},scope),false),'sm-primary'),button('Restore from file',()=>uploadBackup(scope)));
        top.append(actions);
        const picker=el('nav',null,'sm-task-tabs'); picker.setAttribute('aria-label','Backup location');
        [['all','Distro archives'],['stobe','STOBE backups']].forEach(([key,label])=>{
            const tab=el('a',label,'sm-task'+(key===scope?' is-active':''));
            tab.href='?mod=all&view=backups'+(key==='stobe'?'&scope=stobe':'');
            if(key===scope)tab.setAttribute('aria-current','page');
            picker.append(tab);
        });
        content.replaceChildren(top,picker);
        const data=await request('api/storage_tools.php?'+new URLSearchParams({mod:scope,view:'backups',q:search,offset}));
        if(ticket!==generation)return;
        if(data.automatic) {
            const box=panel('Automatic database backups'), form=el('form',null,'sm-form');
            const enabled=field('Create automatic backups','enabled','checkbox',data.automatic.enabled,'Off by default. Archives can contain CHIM, STOBE and DIALECTIC.');
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
            const name=el('div');name.append(el('div',item.filename,'sm-name'),note(item.source==='automatic'?'Automatic archive':item.source==='manual'?'Server import folder':'STOBE backup folder'));
            const fields={filename:item.filename,source:item.source}, actions=el('div',null,'sm-actions');
            actions.append(button('Restore',()=>previewRestore(fields,scope)));
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
            actions.append(button('Compact CHIM + STOBE',()=>confirmAction('Compact CHIM + STOBE','Run VACUUM FULL ANALYZE for CHIM and STOBE only. This locks tables and can take a long time. Stop Skyrim, Kenshi and their servers first.',
                ()=>action('maintenance')),'sm-danger'));
            box.append(note('Shared maintenance can reclaim unused disk space. It does not choose or delete old events.'),el('br'),actions);
            content.append(box);
            const legacy=el('details',null,'sm-panel sm-details');legacy.append(el('summary','STOBE rebuild tools — destructive'));
            legacy.append(note('These shared tools preserve the old Dashboard rebuild operations. The STOBE tab also has its own database reset and version-reset controls.'),el('br'),
                button('Reset STOBE from base schema',()=>confirmAction('Reset STOBE from base schema','Replace STOBE’s live tables with its base schema and replay updates. Stop Kenshi and STOBE, and make a backup first.',()=>action('stobe_factory_reset')),'sm-danger'),document.createTextNode(' '),
                button('Replay STOBE updates',()=>confirmAction('Replay STOBE updates','Clear all STOBE version entries and immediately run its database updates. Back up STOBE first.',()=>action('stobe_replay_versions')),'sm-danger'));
            content.append(legacy);
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
        if(previewTimer)clearTimeout(previewTimer);
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
