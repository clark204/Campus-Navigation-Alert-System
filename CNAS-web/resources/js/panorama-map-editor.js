// The admin "Edit on map" page: every 360° photo pin on the campus map for the floor in view. Click a pin to select its
// photo; the side panel shows the photo's viewer (while "Line it up" is on, with an overlay of the walkways, their
// links and the nearest gate), its name, floor, north and "Show to users". Drag a pin to move it (the links redraw as it moves).
// Nothing is saved until "Save all" (or the selected photo's "Save"), which is one request. Desktop only.
//
// The state (saved photos, edits, selection) is in panorama-drafts.js; the overlay's maths is in panorama-overlay.js.
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { loadMapData } from './map-data.js';
import { COLORS } from './map-style.js';
import { buildGraph } from './navigation/graph.js';
import { meters } from './navigation/geo.js';
import {
    changedCodes,
    createDrafts,
    discard,
    hasUnsaved,
    isChanged,
    markSaved,
    move,
    payloadFor,
    select,
    setFloor,
    setName,
    setNorth,
    setVisible,
} from './panorama-drafts.js';
import { groundView, guessNorth, inView, nearestWalkBearing, overlayLines, walkSegments } from './panorama-overlay.js';
import {
    bearingBetween,
    createNorthSpot,
    distanceBetween,
    normalize,
    offset,
    SPOT_TOO_CLOSE,
    viewBearing,
    northOffsetFrom,
} from './panoramas.js';
import { walkLinks } from './photo-links.js';

const PATH_FLOOR = ['coalesce', ['get', 'floor'], 1];
const RADIANS = Math.PI / 180;
const CONE_METERS = 22;
const CONE_HALF_ANGLE = 25;
// A click this soon after a drag ends is the end of the drag, not a pick.
const DRAG_CLICK_MS = 300;
const SVG_NS = 'http://www.w3.org/2000/svg';
const round = (value, places) => Math.round(value * 10 ** places) / 10 ** places;
const empty = { type: 'FeatureCollection', features: [] };
const CAMERA_ICON =
    '<svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>';

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

// Where the photo of a draft stands, in [lng, lat].
const coordOf = (draft) => [draft.longitude, draft.latitude];

// The photos as the link code takes them (walkLinks): the drafts, so a dragged pin moves its links too.
const pointsOf = (state) =>
    [...state.drafts.values()].map((draft) => ({ id: draft.code, name: draft.name, floor: draft.floor, coord: coordOf(draft), northOffset: draft.north_offset }));

function pinElement(name) {
    const element = document.createElement('button');
    element.type = 'button';
    element.setAttribute('aria-label', `Photo: ${name}`);
    element.title = name;
    element.className = 'grid size-8 cursor-grab place-items-center rounded-full border-2 border-white text-white shadow-md';
    element.innerHTML = CAMERA_ICON;
    return element;
}

// Turns a pin's classes on or off one by one. Never set a pin's className after it is on the map: that removes
// MapLibre's own marker classes, and without them every pin after the first is drawn in the wrong place.
const setClasses = (element, names, on) => names.split(' ').forEach((name) => element.classList.toggle(name, on));

export async function initPanoramaMapEditor(root) {
    const $ = (selector) => root.querySelector(selector);
    const $$ = (selector) => [...root.querySelectorAll(selector)];
    const saveUrl = root.dataset.saveUrl;
    const mapContainer = $('[data-editor-map]');
    const panel = $('[data-panel]');
    const status = $('[data-editor-status]');
    const saveAllButton = $('[data-save-all]');
    const discardButton = $('[data-discard]');
    const errorBox = $('[data-save-errors]');
    const floorButtons = $$('[data-floor-button]');
    const initial = JSON.parse(root.dataset.points);

    let state = createDrafts(initial);
    state = select(state, root.dataset.selected || initial[0]?.code || null);
    let floor = Number(root.dataset.floor) || 1;
    let graph = null;
    let map = null;
    let markers = new Map();
    let viewer = null;
    let viewerCode = null;
    let imageYaw = 0;
    let lineUp = false;
    let candidate = null; // the north the overlay draws while lining up
    let spot = createNorthSpot();
    let lastDragEnd = 0;
    let saving = false;

    const selected = () => (state.selected ? state.drafts.get(state.selected) : null);

    // ---------- Status and toolbar
    function renderToolbar() {
        floorButtons.forEach((button) => button.setAttribute('aria-pressed', String(Number(button.dataset.floorButton) === floor)));
        const count = changedCodes(state).length;
        saveAllButton.disabled = count === 0 || saving;
        saveAllButton.querySelector('[data-changed-count]').textContent = count ? `(${count})` : '';
        discardButton.disabled = count === 0 || saving;
        const draft = selected();
        $('[data-discard-one]').disabled = !draft || !isChanged(state, draft.code) || saving;
        $('[data-save-one]').disabled = !draft || !isChanged(state, draft.code) || saving;
    }

    const say = (text) => {
        status.textContent = text;
    };

    // ---------- Markers
    // Pins for every photo (all floors, shown on the floor in view). Dragging one moves its draft.
    function renderMarkers() {
        for (const [code, marker] of markers) {
            const draft = state.drafts.get(code);
            const element = marker.getElement();
            element.style.display = draft.floor === floor ? '' : 'none';
            const isSelected = code === state.selected;
            const changed = isChanged(state, code);
            setClasses(element, 'bg-[#1a4fd1] ring-4 ring-[#1a4fd1]/40', isSelected);
            setClasses(element, 'bg-[#5b3fd1]', !isSelected);
            setClasses(element, 'outline-3 outline-amber-500', changed);
            element.dataset.state = isSelected ? 'selected' : changed ? 'changed' : '';
            element.title = `${draft.name}${changed ? ' (not saved)' : ''}`;
        }
    }

    // ---------- The map layers: the links between photos, the cone of the selected photo, the north spot's line.
    function linkLines() {
        if (!graph) return empty;
        const byId = new Map(pointsOf(state).map((p) => [p.id, p]));
        const seen = new Set();
        const features = [];
        for (const [from, targets] of walkLinks(graph, [...byId.values()])) {
            for (const link of targets) {
                const key = [from, link.id].sort().join('|');
                if (seen.has(key)) continue;
                seen.add(key);
                const [a, b] = [byId.get(from), byId.get(link.id)];
                const mine = from === state.selected || link.id === state.selected;
                features.push({
                    type: 'Feature',
                    properties: { mine },
                    geometry: { type: 'LineString', coordinates: [a.coord, b.coord] },
                });
            }
        }
        return { type: 'FeatureCollection', features };
    }

    function coneShape() {
        const draft = selected();
        const north = draft?.north_offset;
        if (!draft || north === null || north === undefined) return empty;
        const facing = viewBearing(north, imageYaw);
        const pin = coordOf(draft);
        const ring = [pin];
        for (let a = -CONE_HALF_ANGLE; a <= CONE_HALF_ANGLE; a += 5) ring.push(offset(pin, facing + a, CONE_METERS));
        ring.push(pin);
        return { type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [ring] } };
    }

    function spotLine() {
        const draft = selected();
        return draft && spot.spot ? { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: [coordOf(draft), spot.spot] } } : empty;
    }

    function redrawMap() {
        if (!map || !map.isStyleLoaded()) return;
        map.getSource('links').setData(linkLines());
        map.getSource('cone').setData(coneShape());
        map.getSource('spot-line').setData(spotLine());
        map.setFilter('walkways', ['==', PATH_FLOOR, floor]);
    }

    // ---------- The panel: the selected photo's details and north.
    function fillPanel() {
        const draft = selected();
        $('[data-panel-empty]').hidden = Boolean(draft);
        panel.hidden = !draft;
        if (!draft) return;
        $('[data-panel-title]').textContent = draft.name;
        $('[data-panel-code]').textContent = `${draft.code} · ${draft.floor === 1 ? '1st' : `${draft.floor}`} floor`;
        $('[data-panel-coords]').textContent = `${draft.latitude.toFixed(6)}, ${draft.longitude.toFixed(6)}`;
        setValue('[data-field="name"]', draft.name);
        setValue('[data-field="floor"]', String(draft.floor));
        setValue('[data-field="north"]', draft.north_offset === null ? '' : String(draft.north_offset));
        const visible = $('[data-field="visible"]');
        visible.checked = draft.is_visible;
        visible.disabled = draft.north_offset === null;
        $('[data-visible-why]').textContent = draft.north_offset === null ? 'Set north first.' : '';
        $('[data-line-up]').setAttribute('aria-pressed', String(lineUp));
        $('[data-looks-right]').hidden = !lineUp;
        $('[data-centre-line]').hidden = lineUp;
        $('[data-set-north]').disabled = !spot.spot || !viewer;
    }

    // Writes a field's value unless the user is typing in it (so the cursor does not jump).
    function setValue(selector, value) {
        const element = $(selector);
        if (document.activeElement !== element && element.value !== value) element.value = value;
    }

    function renderNorthStatus() {
        const draft = selected();
        const el = $('[data-north-status]');
        if (!draft) return (el.textContent = '');
        if (lineUp) return (el.textContent = 'Turn the drawn walkways with the arrows until they lie on the real ones, then press Looks right.');
        if (!spot.spot) return (el.textContent = draft.north_offset === null ? 'Click a far building or the gate on the map, then turn the photo until it is on the red line in the photo.' : `North is ${draft.north_offset}°. Click a spot on the map to check it, or use Line it up.`);
        el.textContent = `Spot ${Math.round(distanceBetween(coordOf(draft), spot.spot))} m away. Turn the photo until it is on the red line in the photo, then press the button.`;
    }

    // ---------- The overlay: the walkways near the pin, drawn on the ground as the photo is seen. Only while
    // "Line it up" is on (with the candidate north); otherwise the photo is shown clean.
    function redrawOverlay() {
        const svg = $('[data-overlay]');
        svg.replaceChildren();
        const draft = selected();
        if (!lineUp || !viewer || !draft || !graph) return;
        const north = candidate;
        if (north === null || north === undefined) return;
        const width = viewerEl().clientWidth;
        const height = viewerEl().clientHeight;
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        const pin = coordOf(draft);
        const viewYaw = normalize(viewer.getPosition().yaw / RADIANS);
        const toScreen = (yaw, pitch) => viewer.dataHelper.sphericalCoordsToViewerCoords({ yaw: yaw * RADIANS, pitch: pitch * RADIANS });

        for (const line of overlayLines(pin, walkSegments(graph, pin, draft.floor), north)) {
            let run = [];
            const flush = () => {
                if (run.length > 1) svg.append(svgElement('polyline', { points: run.map((p) => `${p.x},${p.y}`).join(' '), fill: 'none', stroke: '#ffffff', 'stroke-width': 4, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', opacity: 0.9 }));
                run = [];
            };
            for (const point of line) {
                if (!inView(point.yaw, viewYaw)) {
                    flush();
                    continue;
                }
                const at = toScreen(point.yaw, point.pitch);
                run.push(at);
            }
            flush();
        }

        // The photos this one links to, and the nearest gate, each labelled where it stands on the ground.
        const labelled = [];
        for (const link of walkLinks(graph, pointsOf(state)).get(draft.code) ?? []) {
            const target = state.drafts.get(link.id);
            if (target) labelled.push({ name: target.name, coord: coordOf(target) });
        }
        const gate = nearestGate(pin, draft.floor);
        if (gate) labelled.push({ name: 'Gate', coord: gate });
        for (const item of labelled) {
            const view = groundView(bearingBetween(pin, item.coord), north, distanceBetween(pin, item.coord));
            if (!inView(view.yaw, viewYaw)) continue;
            const at = toScreen(view.yaw, view.pitch);
            svg.append(svgElement('circle', { cx: at.x, cy: at.y, r: 6, fill: '#ffffff', stroke: '#5b3fd1', 'stroke-width': 2.5 }));
            const label = svgElement('text', { x: at.x + 9, y: at.y + 4, 'font-size': 13, 'font-weight': 600, fill: '#ffffff', stroke: '#000000', 'stroke-width': 3, 'paint-order': 'stroke' });
            label.textContent = item.name;
            svg.append(label);
        }
    }

    const viewerEl = () => $('[data-pano-viewer]');

    // The nearest gate on the floor (walkway gates are on the ground floor), or null.
    function nearestGate(pin, level) {
        if (!graph) return null;
        let best = null;
        for (const edge of graph.edges.values()) {
            if (edge.type !== 'gate' || graph.nodes.get(edge.a).floor !== level) continue;
            for (const nodeId of [edge.a, edge.b]) {
                const coord = graph.nodes.get(nodeId).coord;
                const distance = meters(pin, coord);
                if (!best || distance < best.distance) best = { distance, coord };
            }
        }
        return best?.coord ?? null;
    }

    function svgElement(name, attributes) {
        const element = document.createElementNS(SVG_NS, name);
        for (const [key, value] of Object.entries(attributes)) element.setAttribute(key, String(value));
        return element;
    }

    // ---------- The viewer (loaded once, when the first photo is selected).
    async function showViewer(draft) {
        if (!viewer) {
            const [{ Viewer }] = await Promise.all([import('@photo-sphere-viewer/core'), import('@photo-sphere-viewer/core/index.css')]);
            viewer = new Viewer({
                container: viewerEl(),
                panorama: draft.image,
                navbar: ['zoom', 'fullscreen'],
                loadingTxt: 'Loading photo…',
                mousewheelCtrlKey: true,
                touchmoveTwoFingers: true,
                defaultPitch: -0.05,
            });
            viewer.addEventListener('position-updated', ({ position }) => {
                imageYaw = normalize(position.yaw / RADIANS);
                redrawOverlay();
                redrawMap();
            });
            viewerCode = draft.code;
            return;
        }
        if (viewerCode !== draft.code) {
            viewerCode = draft.code;
            viewer.setPanorama(draft.image, { showLoader: true });
        }
    }

    // ---------- Changes of selection and state.
    function render() {
        renderToolbar();
        renderMarkers();
        fillPanel();
        renderNorthStatus();
        redrawOverlay();
        redrawMap();
        if (hasUnsaved(state)) saveAllButton.dataset.unsaved = '';
        else delete saveAllButton.dataset.unsaved;
    }

    function choose(code) {
        if (code === state.selected) return;
        state = select(state, code);
        showSelected();
    }

    // Opens the selected photo in the panel. Also run once at the start, for the photo the page opens with.
    function showSelected() {
        lineUp = false;
        errorBox.hidden = true;
        const draft = selected();
        spot = createNorthSpot();
        if (draft) {
            const failed = $('[data-viewer-failed]');
            failed.hidden = true;
            showViewer(draft)
                .then(() => {
                    candidate = draft.north_offset ?? guessNorth(imageYaw, nearestWalkBearing(graph, coordOf(draft), draft.floor) ?? imageYaw);
                    render();
                })
                .catch((error) => {
                    console.error('The 360° photo could not be shown', error);
                    failed.hidden = false;
                });
            say(`Selected ${draft.name}. ${draft.north_offset === null ? 'Set its north on the photo.' : 'Drag its pin to move it.'}`);
        }
        candidate = null;
        render();
    }

    function apply(next) {
        state = next;
        render();
    }

    // Moves a pin while it is dragged: the draft, its links and the overlay follow it.
    function dragging(code, marker) {
        const { lng, lat } = marker.getLngLat();
        state = move(state, code, { latitude: round(lat, 8), longitude: round(lng, 8) });
        if (code === state.selected) redrawOverlay();
        redrawMap();
        renderMarkers();
    }

    // ---------- Saving.
    async function save(codes) {
        saving = true;
        renderToolbar();
        errorBox.hidden = true;
        try {
            const response = await fetch(saveUrl, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ photos: payloadFor(state, codes) }),
            });
            if (response.ok) {
                state = markSaved(state, codes);
                say(`Saved ${codes.length === 1 ? 'the photo' : `${codes.length} photos`}.`);
                render();
                return;
            }
            if (response.status === 422) {
                const body = await response.json();
                const order = payloadFor(state, codes).map((p) => p.code);
                const lines = Object.entries(body.errors ?? {}).map(([key, messages]) => {
                    const match = key.match(/^photos\.(\d+)\./);
                    const code = match ? order[Number(match[1])] : null;
                    const name = code ? state.drafts.get(code)?.name : null;
                    if (code && state.drafts.get(code)) state = select(state, code);
                    return name ? `${name}: ${messages[0]}` : messages[0];
                });
                errorBox.textContent = `Nothing was saved. ${lines.join(' ')}`;
                errorBox.hidden = false;
                render();
                return;
            }
            throw new Error(`HTTP ${response.status}`);
        } catch (error) {
            console.error('Saving the photos failed', error);
            errorBox.textContent = 'The photos could not be saved. Check your connection and try again. Your changes are still here.';
            errorBox.hidden = false;
        } finally {
            saving = false;
            renderToolbar();
        }
    }

    // ---------- Map.
    async function createMap() {
        const data = await loadMapData(root.dataset.mapUrl);
        graph = buildGraph(data);
        map = new maplibregl.Map({
            container: mapContainer,
            style: {
                version: 8,
                sources: {
                    buildings: { type: 'geojson', data: data.buildings },
                    paths: { type: 'geojson', data: data.paths },
                    links: { type: 'geojson', data: empty },
                    cone: { type: 'geojson', data: empty },
                    'spot-line': { type: 'geojson', data: empty },
                },
                layers: [
                    { id: 'ground', type: 'background', paint: { 'background-color': COLORS.ground } },
                    { id: 'campus', type: 'fill', source: 'buildings', filter: ['==', ['get', 'type'], 'campus'], paint: { 'fill-color': COLORS.campus } },
                    { id: 'buildings', type: 'fill', source: 'buildings', filter: ['==', ['get', 'type'], 'building'], paint: { 'fill-color': COLORS.building, 'fill-outline-color': '#7f3229' } },
                    {
                        id: 'walkways',
                        type: 'line',
                        source: 'paths',
                        filter: ['==', PATH_FLOOR, floor],
                        layout: { 'line-cap': 'round', 'line-join': 'round' },
                        paint: { 'line-color': COLORS.walkway, 'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 2, 20, 14] },
                    },
                    { id: 'cone', type: 'fill', source: 'cone', paint: { 'fill-color': '#5b3fd1', 'fill-opacity': 0.25 } },
                    {
                        id: 'links',
                        type: 'line',
                        source: 'links',
                        paint: { 'line-color': '#5b3fd1', 'line-width': ['case', ['get', 'mine'], 2.5, 1.5], 'line-opacity': ['case', ['get', 'mine'], 0.9, 0.45] },
                    },
                    { id: 'spot-line-casing', type: 'line', source: 'spot-line', layout: { 'line-cap': 'round' }, paint: { 'line-color': '#ffffff', 'line-width': 6 } },
                    { id: 'spot-line', type: 'line', source: 'spot-line', layout: { 'line-cap': 'round' }, paint: { 'line-color': '#dc2626', 'line-width': 3 } },
                ],
            },
            center: state.selected ? coordOf(selected()) : undefined,
            zoom: 18,
            minZoom: 15.5,
            maxZoom: 21.5,
            attributionControl: false,
            dragRotate: false,
            pitchWithRotate: false,
        });
        map.touchZoomRotate.disableRotation();
        await new Promise((resolve) => map.once('load', resolve));

        for (const draft of state.drafts.values()) {
            const element = pinElement(draft.name);
            const marker = new maplibregl.Marker({ element, draggable: true }).setLngLat(coordOf(draft)).addTo(map);
            marker.on('dragstart', () => (element.dataset.dragging = ''));
            marker.on('drag', () => dragging(draft.code, marker));
            marker.on('dragend', () => {
                delete element.dataset.dragging;
                lastDragEnd = Date.now();
                const { lng, lat } = marker.getLngLat();
                state = move(state, draft.code, { latitude: round(lat, 8), longitude: round(lng, 8) });
                say(`Moved ${draft.name}. ${isChanged(state, draft.code) ? 'Save all to keep the new position.' : ''}`.trim());
                render();
            });
            element.addEventListener('click', (event) => {
                if (Date.now() - lastDragEnd < DRAG_CLICK_MS) return;
                event.stopPropagation();
                choose(draft.code);
            });
            markers.set(draft.code, marker);
        }

        // A click on the map (not on a pin) picks the north spot for the selected photo.
        map.on('click', (event) => {
            const draft = selected();
            if (!draft || Date.now() - lastDragEnd < DRAG_CLICK_MS) return;
            const picked = spot.pick(coordOf(draft), [event.lngLat.lng, event.lngLat.lat]);
            if (!picked.ok) {
                say(SPOT_TOO_CLOSE);
                render();
                return;
            }
            say('Spot picked.');
            render();
        });
        renderMarkers();
        redrawMap();
    }

    // ---------- Wiring.
    floorButtons.forEach((button) =>
        button.addEventListener('click', () => {
            floor = Number(button.dataset.floorButton);
            render();
        }),
    );
    $('[data-field="name"]').addEventListener('change', (event) => apply(setName(state, state.selected, event.target.value)));
    $('[data-field="floor"]').addEventListener('change', (event) => {
        apply(setFloor(state, state.selected, Number(event.target.value)));
        floor = Number(event.target.value);
        render();
    });
    $('[data-field="north"]').addEventListener('change', (event) => {
        apply(setNorth(state, state.selected, event.target.value));
        lineUp = false;
    });
    $('[data-field="visible"]').addEventListener('change', (event) => apply(setVisible(state, state.selected, event.target.checked)));
    $('[data-clear-north]').addEventListener('click', () => {
        apply(setNorth(state, state.selected, ''));
        spot = createNorthSpot();
        lineUp = false;
        candidate = null;
    });
    $$('[data-nudge]').forEach((button) =>
        button.addEventListener('click', () => {
            const step = Number(button.dataset.nudge);
            if (!viewer) return;
            if (lineUp) {
                candidate = normalize((candidate ?? 0) + step);
                redrawOverlay();
                return;
            }
            const { pitch } = viewer.getPosition();
            viewer.rotate({ yaw: (imageYaw + step) * RADIANS, pitch });
        }),
    );
    $('[data-line-up]').addEventListener('click', () => {
        const draft = selected();
        if (!draft) return;
        lineUp = !lineUp;
        if (lineUp) candidate = draft.north_offset ?? candidate ?? guessNorth(imageYaw, nearestWalkBearing(graph, coordOf(draft), draft.floor) ?? imageYaw);
        render();
    });
    $('[data-looks-right]').addEventListener('click', () => {
        const draft = selected();
        if (!draft || candidate === null) return;
        apply(setNorth(state, draft.code, round(candidate, 2)));
        lineUp = false;
        say(`North set to ${round(candidate, 2)}°.`);
    });
    $('[data-set-north]').addEventListener('click', () => {
        const draft = selected();
        if (!draft || !spot.spot) return;
        apply(setNorth(state, draft.code, round(northOffsetFrom(imageYaw, bearingBetween(coordOf(draft), spot.spot)), 2)));
        say(`North set to ${selected().north_offset}°. Check it against another spot, then save.`);
    });
    saveAllButton.addEventListener('click', () => save(changedCodes(state)));
    discardButton.addEventListener('click', () => {
        apply(discard(state));
        spot = createNorthSpot();
        lineUp = false;
        say('Discarded every change.');
    });
    $('[data-save-one]').addEventListener('click', () => state.selected && save([state.selected]));
    $('[data-discard-one]').addEventListener('click', () => {
        apply(discard(state, state.selected));
        lineUp = false;
        say('Discarded this photo\'s changes.');
    });

    // Leaving with changes that are not saved asks first.
    window.addEventListener('beforeunload', (event) => {
        if (!hasUnsaved(state)) return;
        event.preventDefault();
        event.returnValue = '';
    });

    await createMap();
    showSelected();
}
