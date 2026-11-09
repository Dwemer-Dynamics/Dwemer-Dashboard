// Custom mod card artwork fallback. A banner or icon that fails to load is removed, so the card shows
// its next layer (icon emblem, then monogram) instead of a broken image. No requests of its own.
(() => {
    'use strict';
    const selector = '.custom-mod-card-art > img';

    function drop(image) {
        if (image instanceof HTMLImageElement && image.matches(selector)) image.remove();
    }

    // Errors do not bubble; listening in the capture phase also covers lazy images that load later.
    document.addEventListener('error', (event) => drop(event.target), true);
    // Images that already failed before this deferred script ran; a lazy image not yet requested has no currentSrc.
    document.querySelectorAll(selector).forEach((image) => {
        if (image.complete && image.naturalWidth === 0 && image.currentSrc !== '') drop(image);
    });
})();
