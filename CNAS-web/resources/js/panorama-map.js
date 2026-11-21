// 360° photo points on a MapLibre map: a full-screen viewer over the map, with arrows on the ground to step to the
// next and previous photos. The photos have no markers of their own: /navigate opens one from a tap on a highlighted
// walkway (street view mode) or from the route. The viewer library (Photo Sphere Viewer, with three.js) is only
// downloaded when a photo is first opened.
import { COLORS } from './map-style.js';
import { createViewerSession, escapeHtml, groundArrow, panoramasFrom } from './panoramas.js';
import { routeTwin, walkLinks } from './photo-links.js';

const RADIANS = Math.PI / 180;
// A link arrow on the ground: a disc with an arrow pointing away along the walkway (the viewer turns it to the link).
const ARROW_SVG =
    '<svg viewBox="0 0 48 48" width="100%" height="100%" aria-hidden="true"><circle cx="24" cy="24" r="21" fill="#ffffff" fill-opacity="0.9" stroke="#5b3fd1" stroke-width="3"/><path d="M24 11 35 30 24 24 13 30Z" fill="#5b3fd1"/></svg>';
// Looking a little down shows the path ahead; on a route, further down so the arrow on the ground is in view.
const PITCH = -0.1;
const ROUTE_PITCH = -0.3;
const ARROW_ID = 'route-arrow';
// The route arrow, pointing up the image (= away from the viewer once laid on the ground), in the route's colour.
const ARROW_IMAGE = `data:image/svg+xml,${encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 24 24"><path d="M12 1.5 21.5 12H16v10.5H8V12H2.5z" fill="${COLORS.entrance}" stroke="#ffffff" stroke-width="1.2" stroke-linejoin="round"/></svg>`,
)}`;

// The full-screen overlay the viewer draws into. Esc or ✕ closes it and focus goes back to where it was. Opened from a
// route it also shows the instruction (and what is ahead) on top and a "Next spot" button.
function overlay({ onClose, onNext }) {
    const root = document.createElement('div');
    root.hidden = true;
    root.dataset.panoramaOverlay = '';
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-modal', 'true');
    root.setAttribute('aria-labelledby', 'panorama-title');
    root.className = 'fixed inset-0 z-[1000] flex flex-col bg-neutral-950 text-white';
    root.innerHTML = `
        <div class="flex items-center justify-between gap-3 px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-3">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold tracking-wide text-white/60 uppercase">360° photo</p>
                <h2 id="panorama-title" data-panorama-title class="truncate text-base font-semibold"></h2>
            </div>
            <button type="button" data-panorama-close aria-label="Close 360° photo"
                class="grid size-10 shrink-0 place-items-center rounded-full bg-white/10 text-white transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-white">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="relative min-h-0 flex-1">
            <div data-panorama-viewer class="absolute inset-0"></div>
            <div data-panorama-guide hidden aria-live="polite" class="pointer-events-none absolute inset-x-3 top-3 rounded-xl bg-black/65 px-3 py-2 shadow-md">
                <p data-panorama-guide-text class="text-sm leading-snug font-semibold"></p>
                <p data-panorama-guide-caption hidden class="mt-0.5 text-sm leading-snug text-white/85"></p>
            </div>
            <p data-panorama-failed hidden class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-white/80">
                This photo can't be shown on this device right now.
            </p>
        </div>
        <div class="flex items-center justify-between gap-3 px-4 pt-2 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
            <p data-panorama-hint class="text-xs text-white/60"></p>
            <button type="button" data-panorama-next hidden
                class="shrink-0 rounded-full bg-white px-4 py-2 text-sm font-semibold text-neutral-900 transition hover:bg-white/85 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white">
                Next spot
            </button>
        </div>`;
    document.body.append(root);

    const title = root.querySelector('[data-panorama-title]');
    const failed = root.querySelector('[data-panorama-failed]');
    const close = root.querySelector('[data-panorama-close]');
    const guideBox = root.querySelector('[data-panorama-guide]');
    const guideText = root.querySelector('[data-panorama-guide-text]');
    const guideCaption = root.querySelector('[data-panorama-guide-caption]');
    const hint = root.querySelector('[data-panorama-hint]');
    const next = root.querySelector('[data-panorama-next]');
    let returnFocus = null;
    close.addEventListener('click', onClose);
    next.addEventListener('click', onNext);
    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') onClose();
    });

    return {
        container: root.querySelector('[data-panorama-viewer]'),
        show(point, guide) {
            title.textContent = point.name;
            guideBox.hidden = !guide;
            guideText.textContent = guide?.text ?? '';
            guideCaption.hidden = !guide?.caption;
            guideCaption.textContent = guide?.caption ?? '';
            hint.textContent = guide
                ? 'The big arrow on the ground shows your route.'
                : 'Drag to look around. Tap an arrow on the ground to walk to the next photo.';
            next.hidden = !guide?.nextId;
            if (root.hidden) {
                returnFocus = document.activeElement;
                failed.hidden = true;
                root.hidden = false;
                document.documentElement.classList.add('overflow-hidden');
                close.focus();
            }
        },
        hide() {
            root.hidden = true;
            document.documentElement.classList.remove('overflow-hidden');
            returnFocus?.focus?.();
        },
        fail() {
            failed.hidden = false;
        },
    };
}

// Photo Sphere Viewer with the virtual tour in GPS mode: each photo's links are placed from the photo points'
// positions, and its pose heading turns it so directions in the viewer are compass bearings.
// One link arrow. While a route is shown, the arrow that points the same way as the route arrow is hidden, so the
// route is the only arrow there. `arrowFor(photoId)` gives the route arrow's bearing for a photo on the route (or null).
function linkArrow(link, arrowFor) {
    const element = document.createElement('div');
    element.innerHTML = ARROW_SVG;
    const route = arrowFor(link.data?.from ?? null);
    if (route !== null && link.data?.bearing !== undefined && routeTwin(link.data.bearing, route)) element.style.display = 'none';
    return element;
}

export async function loadViewer(container, { nodes, startId, yaw, onNode, arrowFor = () => null }) {
    const [{ Viewer }, { VirtualTourPlugin }, { MarkersPlugin }] = await Promise.all([
        import('@photo-sphere-viewer/core'),
        import('@photo-sphere-viewer/virtual-tour-plugin'),
        import('@photo-sphere-viewer/markers-plugin'),
        import('@photo-sphere-viewer/core/index.css'),
        import('@photo-sphere-viewer/virtual-tour-plugin/index.css'),
        import('@photo-sphere-viewer/markers-plugin/index.css'),
    ]);
    const viewer = new Viewer({
        container,
        defaultYaw: yaw * RADIANS,
        defaultPitch: arrowFor(startId) === null ? PITCH : ROUTE_PITCH,
        navbar: ['zoom', 'caption', 'fullscreen'],
        loadingTxt: 'Loading photo…',
        mousewheelCtrlKey: false,
        touchmoveTwoFingers: false,
        plugins: [
            MarkersPlugin,
            [
                VirtualTourPlugin,
                {
                    positionMode: 'gps',
                    renderMode: '3d',
                    nodes,
                    startNodeId: startId,
                    preload: true,
                    transitionOptions: { showLoader: true, effect: 'fade', speed: '20rpm', rotation: true },
                    arrowStyle: { size: { width: 48, height: 48 }, element: (link) => linkArrow(link, arrowFor) },
                    // The label of a link (its walkway's landmark or the next photo's name), on hover or tap.
                    getLinkTooltip: (content, link) => `<p>${escapeHtml(link.data?.label ?? '')}</p>`,
                },
            ],
        ],
    });
    const tour = viewer.getPlugin(VirtualTourPlugin);
    const markers = viewer.getPlugin(MarkersPlugin);
    // The route arrow on the photo in view (the tour clears markers when it changes photo).
    const placeArrow = (id) => {
        if (markers.getMarkers().some((marker) => marker.id === ARROW_ID)) markers.removeMarker(ARROW_ID);
        const bearing = arrowFor(id);
        if (bearing !== null) markers.addMarker({ id: ARROW_ID, imageLayer: ARROW_IMAGE, position: groundArrow(bearing), opacity: 0.9 });
    };
    tour.addEventListener('node-changed', ({ node }) => {
        onNode(node.id);
        placeArrow(node.id);
    });
    await new Promise((resolve) => viewer.addEventListener('ready', resolve, { once: true }));
    return {
        viewer,
        async show(id, nextYaw) {
            await tour.setCurrentNode(id, { rotation: false });
            placeArrow(id);
            viewer.rotate({ yaw: nextYaw * RADIANS, pitch: arrowFor(id) === null ? PITCH : ROUTE_PITCH });
        },
        destroy: () => viewer.destroy(),
    };
}

// Sets up the viewer for the photos of `map` and returns { points, open(id, { yaw, guide }), session }. Nothing is
// added when the map has no photos shown to users. `guide` (see createViewerSession) opens a photo on a route facing
// the way the route goes. The arrows between photos follow the walkway `graph` (photo-links.js; `landmarks` names the
// walkways for their labels); without a graph, nearest photos.
export function addPanoramas(map, collection, { graph = null, landmarks = null } = {}) {
    const points = panoramasFrom(collection);
    const links = walkLinks(graph, points, { landmarks });
    let session = null;
    const ui = points.length ? overlay({ onClose: () => session.close(), onNext: () => session.next() }) : null;
    session = createViewerSession({ points, links, load: loadViewer, ui });

    // The viewer opens facing the way the map is turned, so what is ahead on the map is ahead in the photo.
    const open = (id, options = {}) => session.open(id, { yaw: map.getBearing(), ...options });

    return { points, session, open };
}
