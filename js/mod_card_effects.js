/* Themed hover and click-to-play shows for installed CHIM, LORKHAN, DIALECTIC and STOBE cards.
 * The first plain fine-pointer click on a card plays a ~4s show, then follows the card's
 * registered href; a second plain click on that link skips. Visuals are CSS keyframes over
 * local game art (images/card-effects) in
 * css/mod_card_effects.css. This script builds decorative overlays and card-edge borders, owns
 * the single timer and the Pause effects control; it uses no animation frames, canvas, sounds or
 * network requests. */
(() => {
    'use strict';
    const SHOW_MS = 4000;
    const STORAGE_KEY = 'dashboard-mod-card-effects';
    const svgNs = 'http://www.w3.org/2000/svg';
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    const forced = matchMedia('(forced-colors: active)');
    const fine = matchMedia('(hover: hover) and (pointer: fine)');
    const pauseButton = document.querySelector('.mod-card-effects-toggle');
    let pageActive = true, active = null, userPaused = false;
    const played = new WeakSet();
    const paused = () => userPaused;
    const permitted = () => pageActive && !document.hidden && fine.matches && !reduced.matches && !forced.matches && !paused();
    function readPreference() {
        try { userPaused = localStorage.getItem(STORAGE_KEY) === 'off'; }
        catch { userPaused = false; /* Effects also work without browser storage. */ }
    }
    readPreference();

    function svg(markup, className, viewBox, aspect = 'none') {
        const element = document.createElementNS(svgNs, 'svg');
        element.setAttribute('class', className); if (viewBox) element.setAttribute('viewBox', viewBox);
        element.setAttribute('preserveAspectRatio', aspect); element.setAttribute('focusable', 'false');
        element.innerHTML = markup;
        return element;
    }
    function span(className, children = []) {
        const element = document.createElement('span');
        element.className = className; element.append(...children);
        return element;
    }

    // Decorative layers use real game art from images/card-effects (sources in images/ASSET_SOURCES.md).
    function chim() {
        // FUS RO DAH in the game's dragon script, carved word-wall style in both upper corners.
        const wall = side => span('chim-wall chim-wall-' + side, ['fus', 'ro', 'dah'].map(word => span('chim-word chim-word-' + word)));
        // Each part masks the same silhouette and pivots on its own joint; the hand rides on the arm.
        const part = className => span('chim-dragon-part ' + className);
        return [
            span('chim-snowfield', [span('chim-snow chim-snow-far'), span('chim-snow chim-snow-near')]),
            // Odahviing in flight, cut from an in-game screenshot; one pass across the night sky.
            span('chim-dragon', [span('chim-dragon-art', [
                span('chim-dragon-far', [part('chim-dragon-far-wing')]),
                part('chim-dragon-body'),
                span('chim-dragon-near', [part('chim-dragon-arm'), span('chim-dragon-hand', [part('chim-dragon-tip')])]),
            ])]),
            span('chim-frost'),
            wall('left'), wall('right'),
        ];
    }
    function lorkhan() {
        // Dagoth Ur as he appears in Morrowind, rising through the ash.
        return [
            span('lork-ashfield', [span('lork-ash lork-ash-far'), span('lork-ash lork-ash-near')]),
            span('lork-du', [span('lork-du-aura'), span('lork-du-art'), span('lork-du-veil', [span('lork-ash lork-ash-thick')])]),
            span('lork-ashedge', [span('lork-ash lork-ash-thick')]),
            span('lork-edge'),
            span('lork-volcano'),
            span('lork-ring'), span('lork-ring'), span('lork-ring'),
            span('lork-heart', [span('lork-heart-glow')]),
        ];
    }
    function dialectic() {
        // A Strip slot machine: The Tops crown, cabinet wood and the game's own reel strip.
        const reels = [0, 1, 2].map(() => span('dia-reel', [span('dia-strip')]));
        return [
            svg('<rect class="dia-bulbs-base" x="1.6" y="1.6" width="156.8" height="86.8" rx="3" pathLength="240"/>'
                + '<rect class="dia-bulbs-lit" x="1.6" y="1.6" width="156.8" height="86.8" rx="3" pathLength="240"/>'
                + '<rect class="dia-bulbs-win" x="1.6" y="1.6" width="156.8" height="86.8" rx="3" pathLength="240"/>', 'dia-bulbs', '0 0 160 90'),
            span('dia-machine', [span('dia-crown'), span('dia-window', [...reels, span('dia-payline')])]),
            span('dia-glow'),
        ];
    }
    function stobe() {
        // Each dune path repeats every 100 units, so a -50% drift loops seamlessly.
        const dune = (className, d, fill) => span('stobe-dune ' + className, [svg('<path d="' + d + '" fill="' + fill + '"/>', 'stobe-dune-art', '0 0 200 40')]);
        return [
            span('stobe-sand stobe-sand-fine'),
            span('stobe-pall'),
            dune('stobe-dune-back', 'M0 40V20C18 9 34 9 52 17S80 26 100 20C118 9 134 9 152 17S180 26 200 20V40Z', '#8f7656'),
            dune('stobe-dune-mid', 'M0 40V24C12 14 28 12 44 20S76 30 100 24C112 14 128 12 144 20S176 30 200 24V40Z', '#77593b'),
            span('stobe-gust', [span('stobe-drift'), span('stobe-sand stobe-sand-gust')]),
            dune('stobe-dune-front', 'M0 40V26C20 16 40 18 58 25S86 31 100 26C120 16 140 18 158 25S186 31 200 26V40Z', '#56402b'),
            span('stobe-veil'),
        ];
    }
    const themes = {chim, lorkhan, dialectic, stobe};

    // Card-edge borders sit just outside the card, over a rect that follows its rounded corners.
    const ring = (...classes) => classes.map(name => '<rect class="' + name + '" rx="10" pathLength="400"/>').join('');
    const borders = {
        chim: () => svg(ring('chim-b-stone', 'chim-b-steel', 'chim-b-rime', 'chim-b-glint'), 'mod-fx-border mod-fx-border-chim', null),
        lorkhan: () => svg('<defs><linearGradient id="lork-b-bronze" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#5e4220"/>'
            + '<stop offset=".3" stop-color="#b8904c"/><stop offset=".55" stop-color="#6e4c22"/><stop offset=".8" stop-color="#a98043"/><stop offset="1" stop-color="#4c3216"/></linearGradient></defs>'
            + ring('lork-b-heat', 'lork-b-bronze', 'lork-b-embers', 'lork-b-ash'), 'mod-fx-border mod-fx-border-lorkhan', null),
        dialectic: () => svg(ring('dia-b-enamel', 'dia-b-bulbs', 'dia-b-bulbs-lit'), 'mod-fx-border mod-fx-border-dialectic', null),
        stobe: () => span('mod-fx-border mod-fx-border-stobe', [span('stobe-b-haze'), span('stobe-b-rust'), span('stobe-b-grit')]),
    };

    // Only installed cards (links with an href) are decorated; disabled cards stay inert.
    for (const [product, build] of Object.entries(themes)) {
        const link = document.querySelector('.mod-card-' + product + ' > .mod-card-open[href]');
        if (!link) continue;
        const fx = span('mod-fx mod-fx-' + product, build());
        fx.setAttribute('aria-hidden', 'true');
        link.classList.add('mod-fx-host'); link.dataset.modFxState = 'idle';
        link.insertBefore(fx, link.querySelector('.mod-card-label'));
        const border = borders[product]();
        border.setAttribute('aria-hidden', 'true');
        link.parentElement.classList.add('mod-fx-card'); link.parentElement.append(border);
    }

    // Accept any registered HTTP(S) href; each server listens on its own port, so no same-origin rule.
    function destination(link) {
        try {
            const target = new URL(link.getAttribute('href') || '', location.href);
            if (!['http:', 'https:'].includes(target.protocol) || target.username || target.password) return null;
            return target.href;
        } catch { return null; }
    }
    function start(link, url) {
        played.add(link); // Once per card per document; later clicks navigate natively.
        link.classList.add('mod-fx-playing'); link.parentElement.classList.add('mod-fx-card-playing'); link.dataset.modFxState = 'playing';
        active = {link, url, timer: setTimeout(go, SHOW_MS)};
    }
    function end() {
        if (!active) return;
        clearTimeout(active.timer);
        active.link.classList.remove('mod-fx-playing'); active.link.parentElement.classList.remove('mod-fx-card-playing');
        active.link.dataset.modFxState = 'idle';
        active = null;
    }
    function go() {
        if (!active) return;
        const url = active.url;
        end();
        location.assign(url);
    }
    const plainClick = event => event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey;
    function consume(event) { event.preventDefault(); event.stopImmediatePropagation(); }
    // Window capture sees every click first. A second plain pointer click on the playing link skips;
    // any other plain link activation (including keyboard on the same link) cancels and proceeds natively.
    addEventListener('click', event => {
        const link = event.target?.closest?.('a[href]');
        if (active) {
            if (link === active.link && plainClick(event) && event.detail !== 0) { consume(event); go(); return; }
            if (link && plainClick(event)) end();
        }
        if (!link?.classList.contains('mod-fx-host') || played.has(link) || !plainClick(event) || event.detail === 0 || !permitted()) return;
        const url = destination(link);
        if (!url) return;
        start(link, url);
        consume(event);
    }, {capture: true});

    // The control appears only when a themed card is installed; aria-pressed mirrors the saved preference.
    const hosts = document.querySelectorAll('.mod-fx-host').length > 0;
    function sync() {
        document.documentElement.classList.toggle('mod-fx-paused', paused());
        if (pauseButton && hosts) {
            pauseButton.hidden = false;
            pauseButton.textContent = reduced.matches ? 'Reduced motion' : forced.matches ? 'Effects unavailable' : userPaused ? 'Resume effects' : 'Pause effects';
            pauseButton.setAttribute('aria-pressed', String(userPaused));
            pauseButton.disabled = reduced.matches || forced.matches;
        }
        if (!permitted()) end();
    }
    pauseButton?.addEventListener('click', () => {
        userPaused = !userPaused;
        try { localStorage.setItem(STORAGE_KEY, userPaused ? 'off' : 'on'); } catch { /* Optional storage. */ }
        sync();
    });
    addEventListener('storage', event => { if (event.key === STORAGE_KEY || event.key === null) { readPreference(); sync(); } });
    addEventListener('keydown', event => { if (event.key === 'Escape') end(); });
    addEventListener('blur', end);
    addEventListener('pagehide', () => { pageActive = false; end(); });
    addEventListener('pageshow', () => { pageActive = true; sync(); });
    document.addEventListener('visibilitychange', sync);
    for (const preference of [reduced, forced, fine]) preference.addEventListener('change', sync);
    sync();
})();
