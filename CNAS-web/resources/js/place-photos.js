// Admin building and room pages: the upload form's file types (JPEG only for a 360° photo) and the 360° previews.
// Photo Sphere Viewer is loaded only when a "Look around" preview is opened. Hovering a photo shows a larger still.

import { initStillPreview } from './still-preview.js';

const PHOTO_ACCEPT = 'image/jpeg,image/png,image/webp';
const PANORAMA_ACCEPT = 'image/jpeg';

// The file types the upload input should accept for a kind of photo ('photo' or 'panorama').
export function acceptFor(kind) {
    return kind === 'panorama' ? PANORAMA_ACCEPT : PHOTO_ACCEPT;
}

export function initPlacePhotos(section) {
    initStillPreview(section);
    const input = section.querySelector('[data-photo-input]');
    const kinds = section.querySelectorAll('[data-photo-kind]');

    if (input) {
        kinds.forEach((radio) => {
            radio.addEventListener('change', () => {
                if (radio.checked) input.accept = acceptFor(radio.value);
            });
        });
        const checked = section.querySelector('[data-photo-kind]:checked');
        if (checked) input.accept = acceptFor(checked.value);
    }

    section.querySelectorAll('[data-panorama-preview]').forEach((details) => {
        details.addEventListener('toggle', () => {
            if (details.open && !details.dataset.ready) {
                details.dataset.ready = 'true';
                showPanorama(details);
            }
        });
    });
}

async function showPanorama(details) {
    const [{ Viewer }] = await Promise.all([import('@photo-sphere-viewer/core'), import('@photo-sphere-viewer/core/index.css')]);

    new Viewer({
        container: details.querySelector('[data-panorama-viewer]'),
        panorama: details.dataset.src,
        navbar: ['zoom', 'fullscreen'],
        loadingTxt: 'Loading photo…',
        mousewheelCtrlKey: true,
        touchmoveTwoFingers: true,
    });
}
