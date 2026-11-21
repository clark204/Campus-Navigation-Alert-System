// Toast notifications (partials/toasts). Each toast is popover="manual": opened here, closed by its button or when its
// timer bar's animation ends (the CSS pauses it on hover and focus, so the toast waits while someone reads or tabs to
// it). The exit is a short fade (data-closing), then the toast is removed, which also takes it out of the top layer.
// A warning toast has no timer: it stays until closed. Several toasts stack upwards, the first one at the bottom.

/** How long the closing fade plays before the toast is removed (matches the .toast transition). */
export const CLOSE_MS = 250;

/** Gap between stacked toasts, in px. */
export const STACK_GAP = 12;

/** Each toast's distance from the bottom spot: the heights of the toasts below it plus the gaps. */
export function stackOffsets(heights, gap = STACK_GAP) {
    let offset = 0;
    return heights.map((height) => {
        const mine = offset;
        offset += height + gap;
        return mine;
    });
}

/** The words a screen reader hears: the toast's visible text only, never its button names. */
export function announcementText(messageElement) {
    return [...messageElement.querySelectorAll('p')]
        .map((line) => line.textContent.trim())
        .filter(Boolean)
        .join('. ');
}

function announce(region, text) {
    if (typeof document.ariaNotify === 'function') {
        document.ariaNotify(text);
        return;
    }
    // Fallback: the empty live region was in the page from the start; filling it after load gets it read out.
    const live = region.querySelector('[data-toast-live]');
    if (live) setTimeout(() => { live.textContent = text; }, 150);
}

function restack(region) {
    const toasts = [...region.querySelectorAll('[data-toast]:not([data-closing])')];
    const offsets = stackOffsets(toasts.map((toast) => toast.offsetHeight));
    toasts.forEach((toast, index) => toast.style.setProperty('--toast-offset', `${offsets[index]}px`));
}

function closeToast(toast, region) {
    if (!toast.isConnected || toast.hasAttribute('data-closing')) return;
    toast.setAttribute('data-closing', '');
    restack(region);
    setTimeout(() => toast.remove(), CLOSE_MS);
}

export function initToasts(region) {
    const takeover = document.querySelector('[data-alert-takeover]');

    // One announcement for all of them: a second live-region update could cut the first one off.
    const announcements = [];

    region.querySelectorAll('[data-toast]').forEach((toast) => {
        // An emergency comes first: never show a toast over the alarm takeover, and step aside if it opens.
        if (takeover && !takeover.hidden) {
            toast.remove();
            return;
        }
        if (takeover) {
            new MutationObserver(() => {
                if (!takeover.hidden) closeToast(toast, region);
            }).observe(takeover, { attributes: true, attributeFilter: ['hidden'] });
        }

        if (typeof toast.showPopover === 'function') toast.showPopover();
        announcements.push(announcementText(toast.querySelector('[data-toast-message]')));

        toast.querySelector('[data-toast-close]').addEventListener('click', () => closeToast(toast, region));
        toast.querySelector('[data-toast-timer]')?.addEventListener('animationend', () => closeToast(toast, region));
    });

    if (announcements.length) announce(region, announcements.join('. '));
    restack(region);
    // Phone rotation or a wider window changes the toasts' heights (their text wraps differently).
    window.addEventListener('resize', () => restack(region));
}
