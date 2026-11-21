// Hover previews for photo thumbnails: hovering (or focusing) an element with data-still shows a larger still of it
// on the page, not inside the table, so a scrolling table box cannot clip it. Used by the 360° photos list, the
// buildings list and the photo editors on the building and room pages.
import { previewPlacement } from './panorama-preview-position.js';

export function initStillPreview(section) {
    const still = document.createElement('img');
    still.alt = '';
    still.hidden = true;
    still.className = 'pointer-events-none fixed z-[950] w-[28rem] max-w-[70vw] rounded-xl border border-border bg-surface p-1.5 shadow-xl';
    document.body.append(still);

    const show = (anchor) => {
        if (!anchor.dataset.still) return;
        still.src = anchor.dataset.still;
        still.hidden = false;
        const rect = anchor.getBoundingClientRect();
        const size = { width: still.offsetWidth, height: still.offsetHeight };
        const at = previewPlacement(rect, size, { width: innerWidth, height: innerHeight });
        still.style.left = `${at.left}px`;
        still.style.top = `${at.top}px`;
    };
    const hide = () => {
        still.hidden = true;
    };

    section.querySelectorAll('[data-still]').forEach((anchor) => {
        anchor.addEventListener('mouseenter', () => show(anchor));
        anchor.addEventListener('mouseleave', hide);
        anchor.addEventListener('focus', () => show(anchor));
        anchor.addEventListener('blur', hide);
    });
    // Scrolling moves the rows, so the preview goes until the pointer is over a thumbnail again.
    window.addEventListener('scroll', hide, { passive: true, capture: true });
}
