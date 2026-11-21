// The 360° photos list (admin): the "Preview" buttons and the thumbnails open a modal with the photo's 360° viewer, its
// link arrows included, hidden photos too. The viewer library loads when a preview is first opened. Links are found the
// way the public viewer finds them (walkLinks), over every photo, so the chain shows as it will on the walkway.
import { loadMapData } from './map-data.js';
import { buildGraph } from './navigation/graph.js';
import { loadViewer } from './panorama-map.js';
import { createViewerSession } from './panoramas.js';
import { walkLinks } from './photo-links.js';
import { initStillPreview } from './still-preview.js';

// The photos from the page's data (Editor/list JSON): the shape the viewer code uses.
export function previewPoints(list) {
    return list.map((photo) => ({
        id: photo.code,
        name: photo.name,
        floor: photo.floor ?? 1,
        northOffset: photo.north_offset ?? null,
        image: photo.image,
        thumb: photo.thumb,
        coord: [Number(photo.longitude), Number(photo.latitude)],
    }));
}

export function initPanoramasList(section) {
    initStillPreview(section);
    const points = previewPoints(JSON.parse(section.dataset.points));
    const modal = section.querySelector('[data-preview-modal]');
    const title = modal.querySelector('[data-preview-title]');
    const failed = modal.querySelector('[data-preview-failed]');
    const close = modal.querySelector('[data-preview-close]');
    const viewerEl = modal.querySelector('[data-preview-viewer]');
    let returnFocus = null;
    let session = null;
    let loading = null;

    const ui = {
        container: viewerEl,
        show(point) {
            title.textContent = point.name;
        },
        hide() {
            modal.hidden = true;
            document.documentElement.classList.remove('overflow-hidden');
            returnFocus?.focus?.();
        },
        fail() {
            failed.hidden = false;
        },
    };

    // The viewer and its links, built the first time a preview opens (the map data loads with it).
    const ready = () => {
        loading ??= loadMapData(section.dataset.mapUrl).then((data) => {
            const links = walkLinks(buildGraph(data), points, { landmarks: data.landmarks });
            session = createViewerSession({ points, links, load: loadViewer, ui });
            return session;
        });
        return loading;
    };

    const open = async (code, trigger) => {
        returnFocus = trigger ?? null;
        failed.hidden = true;
        title.textContent = points.find((p) => p.id === code)?.name ?? code;
        modal.hidden = false;
        document.documentElement.classList.add('overflow-hidden');
        close.focus();
        const current = await ready();
        await current.open(code);
    };

    section.querySelectorAll('[data-preview]').forEach((button) =>
        button.addEventListener('click', () => open(button.dataset.preview, button).catch((error) => {
            console.error('Preview failed', error);
            failed.hidden = false;
        })),
    );
    close.addEventListener('click', () => session?.close());
    modal.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') session?.close();
    });
}
