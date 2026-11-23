// Admin "Send emergency alert" (admin/alert-send-page): tap the campus map to drop the incident pin. The building under
// the pin is ticked automatically (more can be ticked by hand), the danger zone is drawn as a red circle, and picking a
// type swaps in its wording unless the admin has already changed it, and preselects its level.
import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { loadMapData } from './map-data.js';
import { COLORS } from './map-style.js';
import { circlePolygon } from './navigation/evacuation.js';
import { AFFECTED_TYPES, buildingAt as buildingUnder, locationLabel } from './navigation/incident.js';

const ALARM_COLOR = '#B3121B';
const SAFE_COLOR = '#0F6B3A';
const WORDING_FIELDS = ['title', 'message', 'instructions'];

function ringBounds(ring) {
    const lngs = ring.map(([lng]) => lng);
    const lats = ring.map(([, lat]) => lat);
    return [[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]];
}

// Shared with the mobile app's send form (navigation/incident.js).
export { locationLabel };

export async function initAlertPicker(form) {
    const $ = (selector) => form.querySelector(selector);
    const $$ = (selector) => [...form.querySelectorAll(selector)];
    const templates = JSON.parse(form.dataset.templates);
    const campusWide = new Set(JSON.parse(form.dataset.campusWide));
    const latitude = $('[data-picker-latitude]');
    const longitude = $('[data-picker-longitude]');
    const radiusField = $('[data-picker-radius]');
    const hint = $('[data-picker-hint]');
    const clearButton = $('[data-picker-clear]');
    const buildingBoxes = $$('input[name="building_codes[]"]');
    const wording = Object.fromEntries(WORDING_FIELDS.map((name) => [name, form.elements.namedItem(name)]));
    const selectedType = () => form.querySelector('input[name="type"]:checked')?.value;
    const radius = () => Number(form.querySelector('input[name="danger_radius_m"]:checked')?.value ?? 50);
    const pin = () => (latitude.value && longitude.value ? [Number(longitude.value), Number(latitude.value)] : null);

    // The wording opens by itself on wide screens; on phones it stays folded so the form fits on one screen.
    if (window.matchMedia('(min-width: 1024px)').matches) $('[data-wording]').open = true;

    // ---------- Wording follows the type until the admin edits it
    let wordingType = selectedType();
    const untouched = () => WORDING_FIELDS.every((name) => wording[name].value.trim() === (templates[wordingType]?.[name] ?? '').trim());
    const refreshPreview = () => ($('[data-wording-preview]').textContent = wording.title.value || 'No title yet');
    form.addEventListener('change', (event) => {
        if (event.target.name !== 'type') return;
        const type = event.target.value;
        if (untouched()) WORDING_FIELDS.forEach((name) => (wording[name].value = templates[type]?.[name] ?? ''));
        // Choosing a type preselects its level (fire is an emergency, flood a warning, other an advisory). The admin can change it.
        const level = form.querySelector(`input[name="severity"][value="${event.target.dataset.defaultSeverity}"]`);
        if (level) {
            level.checked = true;
            // A script-made change doesn't fire 'change' by itself; the level's line under the cards needs it.
            level.dispatchEvent(new Event('change', { bubbles: true }));
        }
        wordingType = type;
        refreshPreview();
        refresh();
    });
    wording.title.addEventListener('input', refreshPreview);
    $('[data-picker-drill]').addEventListener('change', () => refresh());

    // ---------- Location summary, confirm text and buildings
    let autoBuilding = null; // ticked because the pin is on it (unticked again if the pin moves off)

    function refresh() {
        const checked = buildingBoxes.filter((box) => box.checked);
        const label = locationLabel(checked.map((box) => box.dataset.buildingName), pin() !== null);
        $('[data-picker-location]').textContent = label;
        $('[data-picker-building-count]').textContent = checked.length ? `(${checked.length})` : '';
        radiusField.disabled = pin() === null;
        clearButton.hidden = pin() === null;
        // Short: on a phone the hint shares the top of the map with "Remove pin".
        hint.textContent = pin()
            ? 'Drag the pin to move it'
            : campusWide.has(selectedType())
              ? 'Whole campus. Tap only if one area is hit.'
              : "Tap where it's happening";

        const typeName = form.querySelector('input[name="type"]:checked')?.closest('label').textContent.trim().toLowerCase();
        const drill = form.elements.namedItem('is_drill').checked ? 'DRILL ' : '';
        form.dataset.confirm = `Send this ${drill}${typeName} alert (${label}) to everyone now?`;

        if (map?.isStyleLoaded()) {
            const affectedFilter = ['in', ['get', 'id'], ['literal', checked.map((box) => box.value)]];
            map.setFilter('affected', affectedFilter);
            map.setFilter('affected-outline', affectedFilter);
            map.getSource('danger').setData(
                pin() ? circlePolygon(pin(), radius()) : { type: 'FeatureCollection', features: [] },
            );
        }
    }
    buildingBoxes.forEach((box) =>
        box.addEventListener('change', () => {
            if (box.value === autoBuilding) autoBuilding = null; // the admin decided about it
            refresh();
        }),
    );
    radiusField.addEventListener('change', refresh);

    // ---------- Map
    let map = null;
    const container = $('[data-picker-map]');
    let buildings;
    try {
        ({ buildings } = await loadMapData(form.dataset.mapUrl));
    } catch (error) {
        console.error('Alert map failed to load', error);
        container.parentElement.setAttribute('data-failed', '');
        hint.hidden = true;
        refresh();
        return;
    }
    const pickable = buildings.features
        .filter((f) => AFFECTED_TYPES.includes(f.properties.type))
        .map((f) => ({ id: f.properties.id, type: f.properties.type, ring: f.geometry.coordinates[0] }));
    const campus = buildings.features.find((f) => f.properties.type === 'campus');
    const campusBounds = ringBounds(campus.geometry.coordinates[0]);

    map = new maplibregl.Map({
        container,
        style: {
            version: 8,
            sources: {
                buildings: { type: 'geojson', data: buildings },
                danger: { type: 'geojson', data: { type: 'FeatureCollection', features: [] } },
            },
            layers: [
                { id: 'ground', type: 'background', paint: { 'background-color': COLORS.ground } },
                { id: 'campus', type: 'fill', source: 'buildings', filter: ['==', ['get', 'type'], 'campus'], paint: { 'fill-color': COLORS.campus, 'fill-outline-color': '#7e9c6a' } },
                { id: 'evacuation', type: 'fill', source: 'buildings', filter: ['==', ['get', 'type'], 'evacuation'], paint: { 'fill-color': SAFE_COLOR, 'fill-opacity': 0.35 } },
                {
                    id: 'buildings',
                    type: 'fill',
                    source: 'buildings',
                    filter: ['in', ['get', 'type'], ['literal', ['building', 'facility']]],
                    paint: { 'fill-color': COLORS.building, 'fill-outline-color': '#7f3229' },
                },
                { id: 'affected', type: 'fill', source: 'buildings', filter: ['in', ['get', 'id'], ['literal', []]], paint: { 'fill-color': ALARM_COLOR, 'fill-opacity': 1 } },
                { id: 'affected-outline', type: 'line', source: 'buildings', filter: ['in', ['get', 'id'], ['literal', []]], paint: { 'line-color': '#3d0a10', 'line-width': 3.5 } },
                { id: 'danger-fill', type: 'fill', source: 'danger', paint: { 'fill-color': ALARM_COLOR, 'fill-opacity': 0.18 } },
                { id: 'danger-outline', type: 'line', source: 'danger', paint: { 'line-color': ALARM_COLOR, 'line-width': 2.5, 'line-dasharray': [2, 1.5] } },
            ],
        },
        bounds: campusBounds,
        minZoom: 15.5,
        maxZoom: 21,
        attributionControl: false,
        dragRotate: false,
        pitchWithRotate: false,
    });
    map.touchZoomRotate.disableRotation();
    await new Promise((resolve) => map.once('load', resolve));
    map.fitBounds(campusBounds, { padding: 24, duration: 0 });

    const pinEl = document.createElement('div');
    pinEl.className = 'text-[#B3121B] drop-shadow-md';
    pinEl.innerHTML =
        '<svg viewBox="0 0 24 24" class="size-10" fill="currentColor" stroke="#fff" stroke-width="1.5" aria-hidden="true"><path d="M12 22s-7-6.4-7-12a7 7 0 0 1 14 0c0 5.6-7 12-7 12z"/><circle cx="12" cy="10" r="2.5" fill="#fff"/></svg>';
    const marker = new maplibregl.Marker({ element: pinEl, anchor: 'bottom', draggable: true });

    const buildingAt = (point) => buildingUnder(point, pickable);

    function placePin(point) {
        longitude.value = point[0].toFixed(7);
        latitude.value = point[1].toFixed(7);
        marker.setLngLat(point).addTo(map);

        const under = buildingAt(point);
        if (autoBuilding && autoBuilding !== under) {
            const previous = buildingBoxes.find((box) => box.value === autoBuilding);
            if (previous) previous.checked = false;
            autoBuilding = null;
        }
        const box = under && buildingBoxes.find((b) => b.value === under);
        if (box && !box.checked) {
            box.checked = true;
            autoBuilding = under;
        }
        refresh();
    }

    function clearPin() {
        latitude.value = '';
        longitude.value = '';
        marker.remove();
        if (autoBuilding) {
            const box = buildingBoxes.find((b) => b.value === autoBuilding);
            if (box) box.checked = false;
            autoBuilding = null;
        }
        refresh();
    }

    map.on('click', (event) => placePin([event.lngLat.lng, event.lngLat.lat]));
    marker.on('dragend', () => {
        const { lng, lat } = marker.getLngLat();
        placePin([lng, lat]);
    });
    clearButton.addEventListener('click', clearPin);

    // Back from a failed submit: show the pin that was sent.
    if (pin()) marker.setLngLat(pin()).addTo(map);
    refresh();
}
