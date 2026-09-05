(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('sm-config').textContent);
    const content = document.getElementById('sm-content');
    const status = document.getElementById('sm-status');
    const dialog = document.getElementById('sm-dialog');
    const dialogBody = document.getElementById('sm-dialog-body');
    const dialogActions = document.getElementById('sm-dialog-actions');
    const labels = {all:'Distro', chim:'CHIM', stobe:'STOBE', dialectic:'DIALECTIC'};
    const query = new URLSearchParams(location.search);
    let mod = query.get('mod') || 'all';
    let view = query.get('view') || '';
    if (mod === 'shared') { mod = 'all'; view = 'backups'; }
    if (!labels[mod]) mod = 'all';
    view = ({manage:'snapshots', playthroughs:'snapshots', storage:'cleanup', databases:'backups'})[view] || view;
    if (location.hash === '#retention-section' && mod === 'chim') view = 'cleanup';
    // Backups live under Distro only; legacy per-mod backup URLs land there with the matching list.
    const backupScope = query.get('scope') === 'stobe' || (view === 'backups' && mod === 'stobe') ? 'stobe' : 'all';
    if (view === 'backups') mod = 'all';
    const views = mod === 'all' ? {overview:'Overview',backups:'Backups',advanced:'Advanced'}
        : {snapshots:'Snapshots',cleanup:'Cleanup',advanced:'Advanced'};
    if (!views[view]) view = Object.keys(views)[0];
    let search = (query.get('q') || '').slice(0,120);
    let offset = Math.max(0, Math.min(1000000, Number(query.get('offset')) || 0));
    let busy = false, dirty = false, generation = 0, previewTimer = null;
    const retentionUrl = config.prefix + '/HerikaServer/ui/api/playthrough_retention.php?summary=1';

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
            const blob = await response.blob(), target = URL.createObjectURL(blob), anchor = el('a');
            const disposition = response.headers.get('Content-Disposition');
            const encoded = disposition.match(/filename\*=UTF-8''([^;]+)/i);
            const plain = disposition.match(/filename="?([^";]+)"?/i);
            anchor.download = encoded ? decodeURIComponent(encoded[1]) : plain ? plain[1] : 'database-backup.sql';
            anchor.href = target; anchor.click(); setTimeout(() => URL.revokeObjectURL(target), 60000);
            return {ok:true,message:'Backup download prepared.'};
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
        const body = new FormData();
        body.set('mod',targetMod); body.set('operation',operation); body.set('_sm_csrf',config.csrf);
        body.set('_sm_scope',targetMod === 'all' ? 'CHIM, STOBE and DIALECTIC databases' : labels[targetMod] + ' database');
        Object.entries(fields).forEach(([key,value]) => body.set(key,value));
        return request('api/storage_action.php', {method:'POST',body});
    }
    function retention(actionName, fields = {}) {
        return request(retentionUrl, {method:'POST',body:new URLSearchParams({csrf_token:config.retentionCsrf,action:actionName,...fields})});
    }
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
            if (refresh) await load();
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

    async function overview(ticket) {
        const grid = el('div',null,'sm-grid');
        content.replaceChildren(toolbar('Your mod databases','Choose a mod to manage its snapshots and cleanup settings.'),grid);
        await Promise.all(['chim','stobe','dialectic'].map(async key => {
            const card = panel(labels[key]); grid.append(card); card.append(note('Loading…'));
            try {
                const data = await request('api/data_manager.php?mod=' + key);
                if (ticket !== generation) return;
                card.replaceChildren(el('h3',labels[key]),note(data.game),metrics([['Database',bytes(data.live.database_bytes)],['Snapshots',number(data.snapshots.all_total)]]),
                    note('Loaded snapshot: ' + (data.live.loaded_snapshot || 'None recorded')),el('br'),link('Manage ' + labels[key],key,'snapshots'));
            } catch (error) { card.replaceChildren(el('h3',labels[key]),note(error.message,'sm-error'),link('Open tools',key,'snapshots')); }
        }));
        if (ticket !== generation) return;
        const shared = panel('Backups for the whole setup');
        shared.style.marginTop = '16px';
        shared.append(note('Automatic archives can contain all three mod databases, and STOBE’s own backup files are managed here too. Inspect a backup before restoring it; scope is checked from the file.'),el('br'),link('Manage database backups','all','backups'));
        content.append(shared);
    }
    function snapshotDetails(snapshot) {
        const details = el('div',null,'sm-details'), list = el('dl');
        const entries = [['Name',snapshot.name],['Player',snapshot.player || 'Not recorded'],['Saved on',date(snapshot.created_at)],['In-game date',snapshot.game_date || 'Not recorded'],
            ['Events at save',number(snapshot.event_count)],['Size at save',bytes(snapshot.size_bytes)],['Type',snapshot.kind],['Notes',snapshot.notes || 'No notes']];
        if (mod === 'stobe') {
            let members = snapshot.members;
            if (typeof members === 'string') { try { members = JSON.parse(members); } catch { members = []; } }
            if (Array.isArray(members)) entries.push(['Faction members',members.map(member => typeof member === 'string' ? member : member.name || member.actor_name || 'Unnamed').join(', ') || 'Not recorded']);
        }
        entries.forEach(([label,value]) => list.append(el('dt',label),el('dd',value))); details.append(list);
        const actions = [];
        if (mod === 'chim' && !snapshot.loaded && snapshot.name.toLowerCase() !== 'default') {
            actions.push(button(snapshot.pinned ? 'Remove protection' : 'Protect snapshot', () => confirmAction(snapshot.pinned ? 'Remove protection' : 'Protect snapshot',
                snapshot.pinned ? 'This snapshot can then be deleted manually or by eligible automatic cleanup.' : 'Keep this snapshot out of manual and automatic cleanup.',
                () => retention('pin',{profile_id:snapshot.id,pinned:snapshot.pinned ? '0':'1'}).then(() => ({ok:true,message:'Snapshot protection updated.'})), snapshot.pinned)));
        }
        openDialog(snapshot.name,[details],actions);
        dialogActions.firstChild.textContent = 'Close';
    }
    function newSnapshot(setup = false) {
        if (setup) {
            confirmAction('Set up snapshots','Save the current database as the protected default recovery snapshot. This does not create a game save.',
                () => action('setup'),false); return;
        }
        const form = el('form',null,'sm-form'), name = field('Snapshot name','name'), notes = field('Notes (optional)','notes','textarea');
        name.input.required = true; name.input.maxLength = 128; notes.input.maxLength = 4000;
        form.append(name.wrap,notes.wrap,note('Saves the current mod database. Your game save is separate.'));
        form.addEventListener('submit',event=>{event.preventDefault();if(form.reportValidity())perform(()=>action('create_snapshot',{name:name.input.value,notes:notes.input.value}));});
        openDialog('Save a snapshot',[form],[button('Save snapshot',()=>form.requestSubmit(),'sm-primary')]); name.input.focus();
    }
    function snapshots(data) {
        const list = data.snapshots, top = toolbar('Snapshots','Saved copies of this mod’s database. Use a matching game save when restoring.',true);
        const create = button('Save snapshot',()=>newSnapshot(),'sm-primary'); top.append(create);
        content.replaceChildren(top);
        if (!list.metadata_available && mod !== 'stobe') {
            content.append(note('Snapshots have not been set up for this database yet.','sm-warning'),button('Set up snapshots',()=>newSnapshot(true),'sm-primary')); return;
        }
        const summary = panel(); summary.classList.add('sm-summary');
        summary.append(metrics([['Loaded snapshot',data.live.loaded_snapshot || 'None recorded',true],['Saved snapshots',number(list.all_total)],['Database',bytes(data.live.database_bytes)]]));
        content.append(summary);
        if (!list.items.length) { content.append(note(search ? 'No snapshots match your search.' : 'No snapshots saved yet. Save one before making major changes.','sm-empty')); return; }
        const rows = list.items.map(snapshot => {
            const name = el('div'); name.append(el('div',snapshot.name,'sm-name'),note(snapshot.player || 'Player not recorded'));
            if (snapshot.loaded) name.append(el('span','Loaded','sm-badge sm-loaded'));
            else if (snapshot.protected) name.append(el('span','Protected','sm-badge'));
            const when = el('div'); when.append(note(date(snapshot.created_at),''));
            when.append(note(snapshot.game_date || 'In-game date not recorded'));
            const actions = el('div',null,'sm-actions');
            actions.append(button('Details',()=>snapshotDetails(snapshot)));
            const restore = button('Restore',()=>confirmAction('Restore snapshot',
                'Restore “' + snapshot.name + '” in ' + labels[mod] + '. Stop the game first, then load the matching game save after restoring.',
                ()=>action('restore_snapshot',{profile_id:snapshot.id}),true,
                [note(mod === 'stobe' ? 'STOBE saves your current database as a new automatic snapshot before switching.' : 'The currently loaded snapshot is updated from your live database before switching.','sm-warning')]));
            restore.disabled = snapshot.loaded;
            const remove = button('Delete',()=>confirmAction('Delete snapshot','Permanently delete “' + snapshot.name + '” from ' + labels[mod] + '. The live database and your game saves are not deleted.',
                ()=>action('delete_snapshot',{profile_id:snapshot.id})), 'sm-danger');
            remove.disabled = snapshot.protected; remove.title = snapshot.protected ? 'Loaded, default and protected snapshots cannot be deleted.' : '';
            actions.append(restore,remove);
            return [name,when,bytes(snapshot.size_bytes),actions];
        });
        content.append(table(['Snapshot','Saved / in-game date','Size at save','Actions'],rows),pager(list));
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
            note('Sizes include table indexes and overhead. “Snapshots & other database storage” is the remaining database size, not an exact snapshot total. Deleting rows makes space reusable; it may not shrink database files.'));
        box.append(details); return box;
    }
    async function cleanup(data,ticket) {
        content.replaceChildren(toolbar('Cleanup','Choose what to keep. Preview eligible data before deleting anything.'),storageBreakdown(data));
        if (mod !== 'chim') {
            const box = panel('Manual cleanup');
            const backupsLink = link('Review backups','all','backups');
            if (mod === 'stobe') backupsLink.href += '&scope=stobe';
            box.append(note('Automatic data-retention rules are not available for ' + labels[mod] + '. Remove unwanted snapshots from the Snapshots tab; database backups are managed under Distro.'),el('br'),
                link('Review snapshots',mod,'snapshots'),document.createTextNode(' '),backupsLink);
            content.append(box); return;
        }
        const host = panel('Cleanup settings'); host.append(note('Loading saved settings…')); content.append(host);
        const state = await request(retentionUrl);
        if (ticket !== generation) return;
        retentionForm(host,state);
    }
    function retentionForm(host,state) {
        host.replaceChildren();
        const form = el('form',null,'sm-form'), grid = el('div',null,'sm-grid sm-two'), inputs = {};
        const add = (parent,label,name,type,help,min,max) => {
            const f = field(label,name,type,state.settings[name],help); inputs[name]=f.input;
            if (min !== undefined) { f.input.min=min;f.input.max=max;f.input.step=1;f.input.required=true; }
            parent.append(f.wrap);
        };
        const diagnostic = panel('Debug logs'), snapshotsPanel = panel('Snapshots & event preview');
        diagnostic.classList.add('sm-settings-group'); snapshotsPanel.classList.add('sm-settings-group');
        add(diagnostic,'Clean up debug logs','diagnostics_enabled','checkbox','Off by default. Only database debug logs are eligible; events, memories and diaries are kept.');
        add(diagnostic,'Delete debug logs older than','diagnostic_days','number','Real-world days, not in-game days.',1,3650);
        add(diagnostic,'Also target this size per log table (MB)','diagnostic_max_mb','number','0 turns off the size target. Cleanup runs in small batches, so a large table may need several rounds.',0,102400);
        add(snapshotsPanel,'Clean up automatic snapshots','snapshots_enabled','checkbox','Only CHIM Dragon Break snapshots are eligible. Manual, default, loaded and protected snapshots are kept.');
        add(snapshotsPanel,'Automatic snapshots to keep','snapshot_keep','number','Keep this many recent eligible snapshots.',1,100);
        add(snapshotsPanel,'Preview events older than','event_days','number','In-game days back from the latest recorded game time. 0 turns off this preview.',0,3650);
        snapshotsPanel.append(note('Preview only — events are never deleted. CHIM may still need them to build NPC memories.','sm-warning'));
        grid.append(diagnostic,snapshotsPanel); form.append(grid);
        add(form,'Run cleanup automatically','automatic','checkbox','Off by default. When enabled, CHIM periodically applies the saved debug-log and automatic-snapshot rules.');
        const last = state.last_run;
        form.append(note(last ? 'Last cleanup: ' + date(last.at) + ' · ' + number(last.rows || 0) + ' log rows and ' + number(last.snapshots || 0) + ' snapshots deleted' : 'No cleanup has run yet.'));
        const actions = el('div',null,'sm-actions'), previewArea = el('div');
        const save = button('Save settings',()=>form.requestSubmit(),'sm-primary');
        const preview = button('Preview cleanup',async()=>{
            const result = await perform(()=>retention('preview'),false);
            if (result?.preview) renderPreview(result.preview,previewArea);
        });
        actions.append(save,preview); form.append(actions); host.append(form,previewArea);
        form.addEventListener('input',()=>{
            dirty=true;preview.disabled=true;previewArea.replaceChildren(note('Settings changed. Save them before previewing cleanup.','sm-warning'));
            if(previewTimer)clearTimeout(previewTimer);
        });
        form.addEventListener('submit',event=>{
            event.preventDefault();if(!form.reportValidity())return;
            const values = Object.fromEntries(Object.entries(inputs).map(([key,input])=>[key,input.type==='checkbox'?(input.checked?'1':'0'):input.value]));
            confirmAction('Save cleanup settings',values.automatic==='1' ? 'Automatic cleanup will use these rules without asking each time. Events and memories remain untouched.' : 'Save these rules. Automatic cleanup will remain off; you can preview and run cleanup manually.',
                ()=>retention('save',values).then(()=>({ok:true,message:'Cleanup settings saved.'})),values.automatic==='1');
        });
    }
    function renderPreview(plan,area) {
        const box = panel('Cleanup preview'), diagnostics = plan.diagnostics || [], snapshots = plan.snapshots || [];
        box.style.marginTop='16px';
        box.append(note('This preview expires in five minutes. Only the listed data can be removed in this round.'));
        if(plan.message)box.append(note(plan.message));
        if (diagnostics.length) box.append(table(['Debug log table','Rows to delete','Estimated size'],diagnostics.map(item=>[item.table,number(item.rows),bytes(item.bytes_estimate)])));
        else box.append(note('No debug-log rows to delete with the saved settings.'));
        box.append(note(snapshots.length ? 'Automatic snapshots to delete: '+snapshots.map(item=>item.name).join(', ') : 'No automatic snapshots to delete.'));
        box.append(note(plan.events?.cutoff_gamets ? number(plan.events.older_rows)+' events are older than the cutoff. None will be deleted.' : 'Event preview is off. No events will be deleted.','sm-warning'));
        const run = button('Run cleanup now',()=>confirmAction('Run cleanup now','Permanently remove the debug-log rows and automatic snapshots listed in this preview. Events and memories are kept.',
            ()=>retention('run',{preview_token:plan.token}).then(result=>({ok:true,message:result.result?.message || 'Cleanup finished.'}))), 'sm-danger');
        run.disabled=!(snapshots.length || diagnostics.some(item=>Number(item.rows)>0));
        box.append(el('br'),run,note('Sizes are estimates. Freed space becomes reusable inside the database; files may not shrink.'));
        area.replaceChildren(box);
        if(previewTimer)clearTimeout(previewTimer);
        previewTimer=setTimeout(()=>{run.disabled=true;box.append(note('Preview expired. Run a new preview before cleanup.','sm-warning'));},Math.max(0,Date.parse(plan.expires_at)-Date.now()));
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
        content.setAttribute('aria-busy','true');
        content.replaceChildren(note('Loading '+views[view].toLowerCase()+'…','sm-empty'));
        try {
            if(view==='overview')await overview(ticket);
            else if(view==='backups')await backups(ticket);
            else if(view==='advanced')await advanced(ticket);
            else {
                const data=await request('api/data_manager.php?'+new URLSearchParams({mod,q:search,offset}));
                if(ticket!==generation)return;
                if(view==='snapshots')snapshots(data);else await cleanup(data,ticket);
                if(data.warnings?.length)content.append(note(data.warnings.join(' '),'sm-warning'));
            }
        } catch(error) {
            if(ticket===generation)content.append(note(error.message,'sm-error'),button('Try loading again',()=>load()));
        } finally { if(ticket===generation)content.setAttribute('aria-busy','false'); }
    }
    document.querySelectorAll('[data-mod]').forEach(anchor=>{
        const key=anchor.dataset.mod;anchor.classList.toggle('is-active',key===mod);
        if(key===mod)anchor.setAttribute('aria-current','page');
        anchor.href='?mod='+key+'&view='+(key==='all'?(view==='backups'||view==='advanced'?view:'overview'):(view==='overview'||view==='backups'?'snapshots':view));
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
