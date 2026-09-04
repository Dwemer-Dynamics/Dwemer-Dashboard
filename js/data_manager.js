/* Storage & Cleanup — shared UI for the CHIM, STOBE and Dialectic databases.
   Each mod is fetched independently so an unavailable mod cannot block the others.
   Views that render a server's own management page are server-rendered fragments:
   navigating to or away from one always uses a full page load. */
(function () {
    'use strict';

    var body = document.body;
    var PAGE = body.dataset.page || 'data_manager.php';
    var ENDPOINT = body.dataset.endpoint || 'api/data_manager.php';
    var MODS = ['chim', 'stobe', 'dialectic'];
    var LIMIT = 50;
    var MAX_QUERY = 120;

    /* Routing table shared with the server so both sides resolve links the same way. */
    var ROUTES = (function () {
        var fallback = {
            views: {all: [], chim: ['playthroughs', 'storage'], stobe: ['playthroughs', 'storage'],
                dialectic: ['playthroughs', 'storage'], shared: ['databases']},
            aliases: {},
            fragments: {}
        };
        try {
            var parsed = JSON.parse(body.dataset.routes || '');
            if (parsed && parsed.views) {
                return {views: parsed.views, aliases: parsed.aliases || {}, fragments: parsed.fragments || {}};
            }
        } catch (error) {
            /* fall through */
        }
        return fallback;
    }());
    var IS_FRAGMENT = body.dataset.fragment === '1';

    function viewsFor(mod) {
        var list = ROUTES.views[mod];
        return Array.isArray(list) ? list : [];
    }

    function defaultView(mod) {
        var list = viewsFor(mod);
        return list.length === 0 ? 'playthroughs' : list[0];
    }

    function isFragmentRoute(route) {
        var list = ROUTES.fragments[route.mod];
        return Array.isArray(list) && list.indexOf(route.view) !== -1;
    }

    var overview = document.getElementById('dm-overview');
    var detail = document.getElementById('dm-detail');
    var viewTabs = document.getElementById('dm-view-tabs');
    var readonlyNote = document.getElementById('dm-readonly');
    var liveRegion = document.getElementById('dm-live-region');
    var summary = document.getElementById('dm-summary');
    var summaryState = document.getElementById('dm-summary-state');
    var summaryFacts = document.getElementById('dm-summary-facts');
    var snapshots = document.getElementById('dm-snapshots');
    var storage = document.getElementById('dm-storage');
    var paging = document.getElementById('dm-paging');
    var prevLink = document.getElementById('dm-prev');
    var nextLink = document.getElementById('dm-next');
    var rangeLabel = document.getElementById('dm-range');
    var searchForm = document.getElementById('dm-search');
    var searchInput = document.getElementById('dm-q');
    var searchMod = document.getElementById('dm-search-mod');
    var clearLink = document.getElementById('dm-clear');

    var navToken = 0;
    var controllers = [];
    var state = parseQuery(window.location.search);
    /* Last successful payload, so switching task tabs does not re-query the server
       for data it already has. Keyed by everything the request depends on. */
    var lastKey = '';
    var lastData = null;

    /* ---------- small helpers ---------- */

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = String(text);
        }
        return node;
    }

    function clear(node) {
        while (node.firstChild) {
            node.removeChild(node.firstChild);
        }
    }

    function str(value) {
        return typeof value === 'string' ? value.trim() : '';
    }

    function formatBytes(value) {
        if (typeof value !== 'number' || !isFinite(value) || value < 0) {
            return '';
        }
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var size = value;
        var unit = 0;
        while (size >= 1024 && unit < units.length - 1) {
            size = size / 1024;
            unit += 1;
        }
        return size.toFixed(unit === 0 || size >= 100 ? 0 : 1) + ' ' + units[unit];
    }

    function formatCount(value) {
        if (typeof value !== 'number' || !isFinite(value) || value < 0) {
            return '';
        }
        return Math.round(value).toLocaleString();
    }

    // Preserve the server's clock rather than interpreting an unzoned date as browser time.
    function formatSaved(value) {
        var match = str(value).match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}:\d{2})/);
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        if (!match || !months[Number(match[2]) - 1]) return str(value);
        return Number(match[3]) + ' ' + months[Number(match[2]) - 1] + ' ' + match[1] + ', ' + match[4];
    }

    /* Missing values stay visible as "Unknown" rather than being hidden. */
    function valueNode(text) {
        var span = el('span', 'dm-cellv');
        if (text) {
            span.textContent = text;
        } else {
            span.appendChild(el('span', 'dm-unknown', 'Unknown'));
        }
        return span;
    }

    function addFact(list, term, text) {
        var dd = el('dd');
        dd.appendChild(valueNode(text));
        var pair = el('div', 'dm-fact');
        pair.appendChild(el('dt', null, term));
        pair.appendChild(dd);
        list.appendChild(pair);
    }

    function cell(label, text, numeric) {
        var td = el('td', numeric ? 'dm-num' : null);
        td.setAttribute('data-label', label);
        td.appendChild(valueNode(text));
        return td;
    }

    function headRow(titles, firstNumericIndex) {
        var row = el('tr');
        titles.forEach(function (title, index) {
            var th = el('th', index >= firstNumericIndex ? 'dm-num' : null, title);
            th.setAttribute('scope', 'col');
            row.appendChild(th);
        });
        var head = el('thead');
        head.appendChild(row);
        return head;
    }

    function announce(text) {
        liveRegion.textContent = text;
    }

    /* ---------- url + state ---------- */

    /* Mirrors dm_resolve_route(): retired view names stay valid as aliases. */
    function resolveRoute(mod, view) {
        if (!ROUTES.views.hasOwnProperty(mod)) {
            mod = 'all';
        }
        var alias = (ROUTES.aliases[mod] || {})[view];
        if (Array.isArray(alias) && alias.length === 2) {
            return {mod: alias[0], view: alias[1]};
        }
        if (mod === 'all') {
            return {mod: 'all', view: 'playthroughs'};
        }
        if (viewsFor(mod).indexOf(view) === -1) {
            return {mod: mod, view: defaultView(mod)};
        }
        return {mod: mod, view: view};
    }

    function parseQuery(search) {
        var params = new URLSearchParams((search || '').split('?').pop() || '');
        var resolved = resolveRoute(
            (params.get('mod') || 'all').toLowerCase(),
            (params.get('view') || '').toLowerCase()
        );
        var offset = parseInt(params.get('offset') || '0', 10);
        return {
            mod: resolved.mod,
            view: resolved.view,
            q: (params.get('q') || '').trim().slice(0, MAX_QUERY),
            offset: isFinite(offset) && offset > 0 ? offset : 0
        };
    }

    function pageUrl(next) {
        var url = PAGE + '?mod=' + encodeURIComponent(next.mod) + '&view=' + encodeURIComponent(next.view);
        if (next.q) {
            url += '&q=' + encodeURIComponent(next.q);
        }
        if (next.offset > 0) {
            url += '&offset=' + String(next.offset);
        }
        return url;
    }

    function go(next, push) {
        state = next;
        if (push) {
            window.history.pushState(null, '', pageUrl(state));
        }
        render();
    }

    /* ---------- requests ---------- */

    function abortInflight() {
        controllers.forEach(function (controller) {
            controller.abort();
        });
        controllers = [];
    }

    function load(mod, offset, query) {
        var controller = new AbortController();
        controllers.push(controller);
        var url = ENDPOINT + '?mod=' + encodeURIComponent(mod) + '&offset=' + String(offset);
        if (query) {
            url += '&q=' + encodeURIComponent(query);
        }
        return fetch(url, {
            signal: controller.signal,
            credentials: 'same-origin',
            headers: {Accept: 'application/json'}
        }).then(function (response) {
            return response.json().catch(function () {
                return null;
            }).then(function (data) {
                var message = str(data && data.error);
                if (response.status === 503) {
                    return {unavailable: true, message: message || 'This mod is not available right now.'};
                }
                if (!response.ok || !data || data.ok !== true) {
                    throw new Error(message || 'Could not load data (HTTP ' + response.status + ').');
                }
                if (data.available === false) {
                    return {unavailable: true, message: 'This mod is not installed or its database is not reachable.'};
                }
                return data;
            });
        });
    }

    function isStale(token, error) {
        return token !== navToken || (error && error.name === 'AbortError');
    }

    /* ---------- overview (mod=all, read-only) ---------- */

    function renderOverview(token) {
        var pending = MODS.length;

        function settle() {
            pending -= 1;
            if (pending === 0 && token === navToken) {
                announce('Overview loaded.');
            }
        }

        MODS.forEach(function (mod) {
            var card = overview.querySelector('[data-card="' + mod + '"]');
            var cardState = card.querySelector('[data-card-state]');
            var facts = card.querySelector('[data-card-facts]');
            card.setAttribute('aria-busy', 'true');
            cardState.className = 'dm-state';
            cardState.hidden = false;
            cardState.textContent = 'Loading…';
            clear(facts);

            load(mod, 0, '').then(function (data) {
                if (token !== navToken) {
                    return;
                }
                card.setAttribute('aria-busy', 'false');
                if (data.unavailable) {
                    cardState.textContent = data.message;
                } else {
                    cardState.hidden = true;
                    var live = data.live || {};
                    var snaps = data.snapshots || {};
                    addFact(facts, 'Database total', formatBytes(live.database_bytes));
                    addFact(facts, 'Saved snapshots',
                        snaps.metadata_available === false ? '' : formatCount(snaps.total));
                    addFact(facts, 'Loaded snapshot', str(live.loaded_snapshot));
                }
                settle();
            }).catch(function (error) {
                if (isStale(token, error)) {
                    return;
                }
                card.setAttribute('aria-busy', 'false');
                cardState.className = 'dm-state is-error';
                cardState.textContent = error.message;
                settle();
            });
        });

        announce('Loading overview for all mods.');
    }

    /* ---------- one mod ---------- */

    function requestKey() {
        return state.mod + '|' + String(state.offset) + '|' + state.q;
    }

    function fillDetail(data) {
        summary.setAttribute('aria-busy', 'false');
        summaryState.hidden = true;
        fillSummary(data);
        fillSnapshots(data);
        fillStorage(data);
    }

    function resetDetail() {
        summary.setAttribute('aria-busy', 'true');
        summaryState.className = 'dm-state';
        summaryState.hidden = false;
        summaryState.textContent = 'Loading…';
        clear(summaryFacts);
        clear(snapshots);
        clear(storage);
        removeWarnings();
        paging.hidden = true;
    }

    function renderDetail(token) {
        resetDetail();

        if (lastKey === requestKey() && lastData) {
            fillDetail(lastData);
            return;
        }

        announce('Loading data.');
        load(state.mod, state.offset, state.q).then(function (data) {
            if (token !== navToken) {
                return;
            }
            summary.setAttribute('aria-busy', 'false');
            if (data.unavailable) {
                summaryState.textContent = data.message;
                announce(data.message);
                return;
            }
            lastKey = requestKey();
            lastData = data;
            fillDetail(data);
            announce((str(data.label) || 'Mod') + ' data loaded.');
        }).catch(function (error) {
            if (isStale(token, error)) {
                return;
            }
            summary.setAttribute('aria-busy', 'false');
            summaryState.className = 'dm-state is-error';
            summaryState.textContent = error.message;
            announce(error.message);
        });
    }

    function removeWarnings() {
        Array.prototype.forEach.call(summary.querySelectorAll('.dm-warn'), function (node) {
            node.remove();
        });
    }

    function fillSummary(data) {
        var live = data.live || {};
        var snaps = data.snapshots || {};
        addFact(summaryFacts, 'Game', str(data.game));
        addFact(summaryFacts, 'Database total', formatBytes(live.database_bytes));
        addFact(summaryFacts, 'Saved snapshots', snaps.metadata_available === false ? '' : formatCount(snaps.all_total ?? snaps.total));
        addFact(summaryFacts, 'Loaded snapshot', str(live.loaded_snapshot));

        var warnings = Array.isArray(data.warnings) ? data.warnings : [];
        warnings.forEach(function (warning) {
            var text = str(warning);
            if (text) {
                summary.appendChild(el('p', 'dm-warn', text));
            }
        });
    }

    function fillSnapshots(data) {
        var snaps = data.snapshots || {};
        var items = Array.isArray(snaps.items) ? snaps.items : [];
        clear(snapshots);

        if (snaps.metadata_available === false) {
            snapshots.appendChild(el('p', 'dm-state',
                'Snapshot details are not available for ' + (str(data.label) || 'this mod') + '.'));
            return;
        }
        if (items.length === 0) {
            var emptyText = state.q ? 'No snapshots match “' + state.q + '”.' : 'No saved snapshots yet.';
            if (snaps.total > 0) emptyText = 'There are no snapshots on this page. Go back to an earlier page.';
            snapshots.appendChild(el('p', 'dm-state', emptyText));
            fillPaging(snaps, 0);
            return;
        }

        var table = el('table', 'dm-table');
        table.appendChild(el('caption', null, 'Saved snapshots. Dates come from the snapshot, not from live play.'));
        table.appendChild(headRow(['Snapshot', 'Player', 'Game date', 'Saved', 'Size', 'Events'], 4));

        var tbody = el('tbody');
        items.forEach(function (item) {
            var row = el('tr');
            var nameCell = cell('Snapshot', str(item.name));
            var nameValue = nameCell.firstChild;
            if (item.loaded === true) {
                nameValue.appendChild(el('span', 'dm-badge', 'Loaded'));
            }
            if (item.pinned === true) {
                nameValue.appendChild(el('span', 'dm-badge is-quiet', 'Pinned'));
            }
            if (str(item.kind)) {
                nameValue.appendChild(el('span', 'dm-badge is-quiet', str(item.kind)));
            }
            row.appendChild(nameCell);
            row.appendChild(cell('Player', str(item.player)));
            row.appendChild(cell('Game date', str(item.game_date)));
            row.appendChild(cell('Saved', formatSaved(item.created_at)));
            row.appendChild(cell('Size', formatBytes(item.size_bytes), true));
            row.appendChild(cell('Events', formatCount(item.event_count), true));
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        snapshots.appendChild(table);

        fillPaging(snaps, items.length);
    }

    function fillPaging(snaps, shown) {
        var offset = typeof snaps.offset === 'number' && snaps.offset > 0 ? snaps.offset : 0;
        var total = typeof snaps.total === 'number' && snaps.total > 0 ? snaps.total : shown;
        var limit = typeof snaps.limit === 'number' && snaps.limit > 0 ? snaps.limit : LIMIT;
        var last = shown > 0 ? offset + shown : 0;

        paging.hidden = false;
        rangeLabel.textContent = 'Showing ' + formatCount(offset + 1) + '–' + formatCount(last)
            + ' of ' + formatCount(total);

        prevLink.hidden = offset <= 0;
        prevLink.href = pageUrl({
            mod: state.mod,
            view: state.view,
            q: state.q,
            offset: Math.max(0, offset - limit)
        });
        nextLink.hidden = offset + shown >= total;
        nextLink.href = pageUrl({mod: state.mod, view: state.view, q: state.q, offset: offset + limit});
    }

    function fillStorage(data) {
        var live = data.live || {};
        var categories = Array.isArray(live.categories) ? live.categories : [];
        clear(storage);

        if (categories.length === 0) {
            storage.appendChild(el('p', 'dm-state', 'No stored data categories were reported.'));
            return;
        }

        var total = formatBytes(live.database_bytes);
        var table = el('table', 'dm-table');
        table.appendChild(el('caption', null, total
            ? 'Database total: ' + total
            : 'Database total is unknown.'));
        table.appendChild(headRow(['Category', 'Size', 'Rows (approx.)'], 1));

        var tbody = el('tbody');
        categories.forEach(function (category) {
            var row = el('tr');
            row.appendChild(cell('Category', str(category.label) || str(category.key)));
            row.appendChild(cell('Size', formatBytes(category.bytes), true));
            row.appendChild(cell('Rows (approx.)', formatCount(category.rows_estimate), true));
            tbody.appendChild(row);
        });
        table.appendChild(tbody);
        storage.appendChild(table);
    }

    /* ---------- chrome ---------- */

    function markActive(link, active) {
        link.classList.toggle('is-active', active);
        if (active) {
            link.setAttribute('aria-current', 'page');
        } else {
            link.removeAttribute('aria-current');
        }
    }

    /* Keep the current task selected when it exists for the target mod. */
    function modUrl(mod) {
        var target = resolveRoute(mod, state.view);
        var url = PAGE + '?mod=' + encodeURIComponent(target.mod);
        if (target.mod !== 'all') {
            url += '&view=' + encodeURIComponent(target.view);
        }
        return url;
    }

    function updateChrome() {
        var isAll = state.mod === 'all';

        Array.prototype.forEach.call(document.querySelectorAll('[data-mod-link]'), function (link) {
            var mod = link.dataset.modLink;
            link.href = modUrl(mod);
            if (link.closest('.dm-tabs-mod')) {
                markActive(link, mod === state.mod);
            }
        });

        Array.prototype.forEach.call(viewTabs.querySelectorAll('[data-view-link]'), function (link) {
            var view = link.dataset.viewLink;
            link.href = pageUrl({mod: state.mod, view: view, q: state.q, offset: state.offset});
            markActive(link, view === state.view);
        });

        viewTabs.hidden = viewsFor(state.mod).length === 0;
        readonlyNote.hidden = !isAll;
        overview.hidden = !isAll;
        detail.hidden = isAll || IS_FRAGMENT;

        Array.prototype.forEach.call(detail.querySelectorAll('.dm-view'), function (pane) {
            pane.hidden = pane.dataset.view !== state.view;
        });

        searchMod.value = state.mod;
        if (searchInput.value !== state.q) {
            searchInput.value = state.q;
        }
        clearLink.hidden = state.q === '';
        clearLink.href = pageUrl({mod: state.mod, view: 'playthroughs', q: '', offset: 0});
    }

    function render() {
        abortInflight();
        navToken += 1;
        updateChrome();
        if (IS_FRAGMENT) {
            /* The tools on this page are server-rendered; there is nothing to fetch. */
            return;
        }
        if (state.mod === 'all') {
            renderOverview(navToken);
        } else if (MODS.indexOf(state.mod) !== -1) {
            renderDetail(navToken);
        }
    }

    /* ---------- events ---------- */

    document.addEventListener('click', function (event) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        var target = event.target;
        if (!target || typeof target.closest !== 'function') {
            return;
        }
        var link = target.closest('a[data-mod-link], a[data-view-link], a.dm-page-link, a.dm-clear');
        if (!link) {
            return;
        }
        var next = parseQuery(link.getAttribute('href'));
        /* Server-rendered tools and mod switches replace the whole document, so
           let the browser navigate instead of swapping panes in place. */
        if (IS_FRAGMENT || isFragmentRoute(next) || next.mod !== state.mod) {
            return;
        }
        event.preventDefault();
        go(next, true);
    });

    searchForm.addEventListener('submit', function (event) {
        if (IS_FRAGMENT) {
            return;
        }
        event.preventDefault();
        // Submitting is an explicit request for fresh results, so never reuse the cache.
        lastKey = '';
        go({
            mod: state.mod,
            view: 'playthroughs',
            q: searchInput.value.trim().slice(0, MAX_QUERY),
            offset: 0
        }, true);
    });

    window.addEventListener('popstate', function () {
        go(parseQuery(window.location.search), false);
    });

    document.getElementById('dm-refresh').addEventListener('click', function () {
        if (IS_FRAGMENT) {
            window.location.reload();
            return;
        }
        lastKey = '';
        lastData = null;
        render();
    });

    render();
}());
