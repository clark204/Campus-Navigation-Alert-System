import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { loadMapData } from '../map-data.js';
import { COLORS } from '../map-style.js';
import { addPanoramas } from '../panorama-map.js';
import {
    addEvacuationTargets,
    blockedEdges,
    circlePolygon,
    dangerFromAlert,
    findEvacuationRoute,
    hasDangerArea,
    NO_SAFE_ROUTE,
} from './evacuation.js';
import { meters } from './geo.js';
import { attachPoint, buildGraph, graphToGeoJSON, listPlaces } from './graph.js';
import { buildManeuvers, doorHeading, ordinal } from './instructions.js';
import { currentLeg, floorParts, legIntro, legLabel, legLine, routeLegs } from './legs.js';
import { rerouteFrom } from './position.js';
import { createRouteGuide, debugLines } from './route-panoramas.js';
import { findRoute } from './router.js';
import { focusFor } from './focus.js';
import {
    DRAW_STEP_MS,
    drawInFraction,
    FLOW_TICK_MS,
    flowDashOffset,
    glidePoint,
    GLIDE_MS,
    motionFor,
    partialLines,
    pathData,
} from './motion.js';
import {
    alongPathFacing,
    coverageLines,
    mapPadding,
    mapTapAction,
    photoAtTap,
    streetViewAvailable,
    streetViewCoverage,
    TAP_PIXELS,
} from './street-view.js';
import { createPlaceStore } from './saved-places.js';
import { CAUGHT_UP_METERS, createSimulator, SIM_INTERVAL_MS } from './simulate.js';
import { getStride, getThreshold, setStride, setThreshold, StepDetector } from './steps.js';
import { forgetTrip, recallTrip, rememberTrip } from './storage.js';
import { initVoiceDirections } from '../voice-directions.js';
import { GPS_MAX_ACCURACY, OFF_ROUTE_METERS, Tracker } from './tracker.js';
import { createPromptPlanner, Voice } from './voice.js';
import { ScreenWakeLock } from './wakelock.js';

const ROUTE_COLOR = COLORS.entrance;
const WALKED_COLOR = '#a8a29e';
// Emergency mode (resources/css/app.css: --color-alarm, --color-safe).
const ALARM_COLOR = '#B3121B';
const SAFE_COLOR = '#0F6B3A';
const EVACUATION_NAME = 'the evacuation area';
// A GPS fix farther than this from any walkway is treated as off campus.
const OFF_CAMPUS_METERS = 40;
const WALKING_SPEED = 1.2; // m/s, for the time estimate
const FOLLOW_ZOOM = 19.3;
// "My location" as the start of a route to safety needs a fix this precise, to pick the right walkway.
const EVACUATION_START_ACCURACY = 10;
// Without a usable GPS fix (±GPS_MAX_ACCURACY m or better) this long after starting, the person taps "Next" instead.
const GPS_WAIT_MS = 20000;
// Steps this recent tell the GPS filter the person is walking when the phone gives no speed.
const RECENT_STEP_MS = 2000;
const WRONG_WAY_TEXT = "You're going the wrong way, turn around";
const REROUTING_TEXT = 'Rerouting';
const NO_REROUTE_TEXT = 'No other way from here. Go back to the marked route';
// GPS fixes after a reroute (or a failed one) before the next can start.
const REROUTE_GRACE_FIXES = 3;
const REROUTE_NOTE_MS = 4000;
const NO_HTTPS_NOTE = "This page isn't on a secure (https) connection, so your phone won't share its location.";
const NO_GPS_NOTE = "This browser can't share your location.";
const LOCATION_DENIED_NOTE =
    'Location access is turned off for this site, so the dot can\'t follow you. Allow location for this site in your browser\'s settings, then reload the page.';
const noFixNote = (accuracy) =>
    accuracy
        ? `Your location is only accurate to about ${Math.round(accuracy)} m here (it needs ${GPS_MAX_ACCURACY} m or better). Step into the open and the dot will start following you.`
        : "Your phone hasn't found your location yet. Step into the open and the dot will start following you.";
// The file name of this script, shown in the debug panel to tell which build a phone is running.
const BUILD = new URL(import.meta.url).pathname.split('/').pop();

const ICONS = {
    straight: '<path d="M12 20V4M6 10l6-6 6 6"/>',
    depart: '<path d="M12 20V4M6 10l6-6 6 6"/>',
    right: '<path d="M6 20v-7a4 4 0 0 1 4-4h9M15 5l4 4-4 4"/>',
    left: '<path d="M18 20v-7a4 4 0 0 0-4-4H5M9 5 5 9l4 4"/>',
    'slight right': '<path d="M8 20v-6l8-8M10 6h6v6"/>',
    'slight left': '<path d="M16 20v-6L8 6M14 6H8v6"/>',
    uturn: '<path d="M16 20V9a4 4 0 0 0-8 0v6M4 11l4 4 4-4"/>',
    stairs: '<path d="M3 20h5v-5h5v-5h5V5h3"/>',
    arrive: '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
};

// Turns, and a first instruction that says which way to go, use the direction's arrow.
// A path's floor in map filters (paths from an older cached map have none: the ground floor).
const PATH_FLOOR = ['coalesce', ['get', 'floor'], 1];
// Ground walkways fade to this while an upper floor is shown.
const GROUND_FADED = 0.3;
// After confirming a floor the map shows the whole next part of the route this long, then follows the dot again.
const LEG_FIT_MS = 4000;

const iconFor = (m) => ICONS[m.type === 'turn' || (m.type === 'depart' && m.modifier) ? m.modifier : m.type] ?? ICONS.straight;

export const formatDistance = (m) => (m < 10 ? `${Math.max(0, Math.round(m))} m` : `${Math.round(m / 5) * 5} m`);
const formatMinutes = (m) => `${Math.max(1, Math.round(m / WALKING_SPEED / 60))} min`;

const titleCase = (name) => {
    const plain = name.replace(/^the\s+/i, '');
    return plain.charAt(0).toUpperCase() + plain.slice(1);
};

// The campus map from the database (GET /api/map), as a fresh object this page can add its routing graph to.
async function loadData(url) {
    return { ...(await loadMapData(url)) };
}

function mapStyle(data, debug) {
    const empty = { type: 'FeatureCollection', features: [] };
    const layers = [
        { id: 'ground', type: 'background', paint: { 'background-color': COLORS.ground } },
        {
            id: 'campus',
            type: 'fill',
            source: 'buildings',
            filter: ['==', ['get', 'type'], 'campus'],
            paint: { 'fill-color': COLORS.campus, 'fill-outline-color': '#7e9c6a' },
        },
        {
            id: 'grounds',
            type: 'fill',
            source: 'buildings',
            filter: ['in', ['get', 'type'], ['literal', ['evacuation', 'facility']]],
            paint: { 'fill-color': ['match', ['get', 'type'], 'evacuation', COLORS.evacuation, COLORS.facility] },
        },
        {
            id: 'buildings',
            type: 'fill',
            source: 'buildings',
            filter: ['==', ['get', 'type'], 'building'],
            paint: { 'fill-color': COLORS.building, 'fill-outline-color': '#7f3229' },
        },
        // Emergency mode (empty or hidden otherwise): affected buildings, the evacuation area and the danger zone.
        {
            id: 'affected',
            type: 'fill',
            source: 'buildings',
            filter: ['in', ['get', 'id'], ['literal', []]],
            paint: { 'fill-color': ALARM_COLOR, 'fill-opacity': 1, 'fill-outline-color': '#7A0A10' },
        },
        // A thick dark edge keeps alarm buildings apart from the brick-red ones.
        {
            id: 'affected-outline',
            type: 'line',
            source: 'buildings',
            filter: ['in', ['get', 'id'], ['literal', []]],
            paint: { 'line-color': '#3d0a10', 'line-width': 3.5 },
        },
        {
            id: 'evacuation-fill',
            type: 'fill',
            source: 'buildings',
            filter: ['==', ['get', 'type'], 'evacuation'],
            layout: { visibility: 'none' },
            paint: { 'fill-color': SAFE_COLOR, 'fill-opacity': 0.4 },
        },
        {
            id: 'evacuation-outline',
            type: 'line',
            source: 'buildings',
            filter: ['==', ['get', 'type'], 'evacuation'],
            layout: { visibility: 'none', 'line-join': 'round' },
            paint: { 'line-color': SAFE_COLOR, 'line-width': 3 },
        },
        { id: 'danger-fill', type: 'fill', source: 'danger', paint: { 'fill-color': ALARM_COLOR, 'fill-opacity': 0.2 } },
        {
            id: 'danger-outline',
            type: 'line',
            source: 'danger',
            paint: { 'line-color': ALARM_COLOR, 'line-width': 2.5, 'line-dasharray': [2, 1.5] },
        },
        { id: 'stairs', type: 'fill', source: 'stairs', paint: { 'fill-color': COLORS.gate, 'fill-opacity': 0.45 } },
        {
            id: 'walkways',
            type: 'line',
            source: 'paths',
            filter: ['==', PATH_FLOOR, 1],
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: {
                'line-color': ['match', ['get', 'type'], 'gate', COLORS.gate, COLORS.walkway],
                'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 2, 20, 18],
                'line-opacity': 1,
            },
        },
        // Corridors of one upper floor, only while the route or the person is on that floor (showFloor).
        {
            id: 'corridors',
            type: 'line',
            source: 'paths',
            filter: ['==', PATH_FLOOR, -1],
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: {
                'line-color': COLORS.walkway,
                'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 1.5, 20, 12],
                'line-dasharray': [1.2, 0.8],
            },
        },
        // Walkways closed by the emergency.
        {
            id: 'blocked',
            type: 'line',
            source: 'blocked',
            layout: { 'line-cap': 'butt' },
            paint: {
                'line-color': ALARM_COLOR,
                'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 1.5, 20, 6],
                'line-dasharray': [1, 1],
            },
        },
        {
            id: 'route-casing',
            type: 'line',
            source: 'route',
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: { 'line-color': '#ffffff', 'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 5, 20, 16] },
        },
        {
            id: 'route',
            type: 'line',
            source: 'route',
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: {
                'line-gradient': routeGradient(0),
                'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 3, 20, 10],
            },
        },
        // The upstairs part of a route, dashed, in the preview before Start (navigation draws one floor at a time).
        {
            id: 'route-upper-casing',
            type: 'line',
            source: 'route-upper',
            layout: { 'line-cap': 'round', 'line-join': 'round' },
            paint: { 'line-color': '#ffffff', 'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 5, 20, 16] },
        },
        {
            id: 'route-upper',
            type: 'line',
            source: 'route-upper',
            layout: { 'line-join': 'round' },
            paint: {
                'line-color': ROUTE_COLOR,
                'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 3, 20, 10],
                'line-dasharray': [1.4, 1.1],
            },
        },
        {
            id: 'entrances',
            type: 'circle',
            source: 'entrances',
            minzoom: 18,
            paint: {
                'circle-radius': 3.5,
                'circle-color': COLORS.entrance,
                'circle-stroke-color': '#ffffff',
                'circle-stroke-width': 1.5,
            },
        },
    ];
    if (debug) {
        layers.push(
            {
                id: 'graph-edges',
                type: 'line',
                source: 'graph',
                filter: ['==', ['get', 'kind'], 'edge'],
                paint: {
                    'line-width': 1.5,
                    'line-color': ['match', ['get', 'type'], 'gate', '#b45309', 'link', '#16a34a', 'stairs', '#dc2626', '#2563eb'],
                },
            },
            {
                id: 'graph-nodes',
                type: 'circle',
                source: 'graph',
                filter: ['==', ['get', 'kind'], 'node'],
                paint: {
                    'circle-radius': 3,
                    'circle-color': ['match', ['get', 'nodeKind'], 'room', '#16a34a', 'stair', '#dc2626', '#1e3a8a'],
                    'circle-stroke-color': '#ffffff',
                    'circle-stroke-width': 1,
                },
            },
        );
    }
    return {
        version: 8,
        sources: {
            buildings: { type: 'geojson', data: data.buildings },
            paths: { type: 'geojson', data: data.paths },
            stairs: { type: 'geojson', data: data.stairs },
            entrances: { type: 'geojson', data: data.entrances },
            route: { type: 'geojson', data: empty, lineMetrics: true },
            'route-upper': { type: 'geojson', data: empty },
            danger: { type: 'geojson', data: empty },
            blocked: { type: 'geojson', data: empty },
            ...(debug ? { graph: { type: 'geojson', data: graphToGeoJSON(data.graph) } } : {}),
        },
        layers,
    };
}

// Walked part of the route turns grey; `fraction` is progress / length. Routes to safety are green.
function routeGradient(fraction, color = ROUTE_COLOR) {
    const f = Math.min(1, Math.max(0.00001, fraction));
    return ['step', ['line-progress'], WALKED_COLOR, f, color];
}

function boundsOf(coords) {
    const lngs = coords.map(([lng]) => lng);
    const lats = coords.map(([, lat]) => lat);
    return [[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]];
}

function markerElement(className, html = '') {
    const el = document.createElement('div');
    el.className = className;
    el.innerHTML = html;
    return el;
}

async function init(root) {
    const $ = (selector) => root.querySelector(selector);
    const $$ = (selector) => [...root.querySelectorAll(selector)];
    const debugPanel = $('[data-debug]');
    const params = new URLSearchParams(location.search);

    const data = await loadData(root.dataset.mapUrl);
    data.graph = buildGraph(data);
    const places = listPlaces(data.graph, data.paths);
    const placeById = new Map(places.map((p) => [p.id, p]));
    const evacuationArea = data.buildings.features.find((f) => f.properties.type === 'evacuation');
    // Route-to-safety destinations: points on the edge of the evacuation area, linked to the nearby walkways.
    const evacuationTargets = evacuationArea ? addEvacuationTargets(data.graph, evacuationArea.geometry.coordinates[0]) : new Set();

    // ---------- Map
    const campus = data.buildings.features.find((f) => f.properties.type === 'campus');
    const campusBounds = boundsOf(campus.geometry.coordinates[0]);
    const map = new maplibregl.Map({
        container: $('[data-nav-map]'),
        style: mapStyle(data, !!debugPanel),
        bounds: campusBounds,
        minZoom: 15.5,
        maxZoom: 21,
        maxPitch: 60,
        attributionControl: false,
    });
    await new Promise((resolve) => map.once('load', resolve));

    const sheet = $('[data-sheet]');
    // From 1024 px the sheet is a card at the top right of the map (navigate-page.blade.php).
    const desktop = window.matchMedia('(min-width: 1024px)');
    // The emergency banner (resources/views/partials/alerts) covers the top of the map while an alert is up.
    const bannerBottom = () => {
        const banner = document.querySelector('[data-alert-banner]');
        return banner && !banner.hidden ? Math.max(0, banner.getBoundingClientRect().bottom) : 0;
    };
    // On desktops the card starts below the banner.
    const placeSheet = () => {
        const top = desktop.matches && bannerBottom() ? `${bannerBottom() + 12}px` : '';
        sheet.style.top = top;
        sheet.style.maxHeight = top ? `calc(100dvh - ${top} - 0.75rem)` : '';
    };
    placeSheet();
    // Switching between the sheet and the card (a window resized across 1024 px).
    desktop.addEventListener('change', () => {
        placeSheet();
        map.resize();
    });
    // Keeps the route and the user dot in the part of the map not covered by the sheet or card (or debug panel).
    const padding = () => {
        const debug = debugPanel?.getBoundingClientRect();
        return mapPadding({
            desktop: desktop.matches,
            viewport: { width: innerWidth, height: innerHeight },
            sheetHeight: sheet.offsetHeight,
            card: sheet.getBoundingClientRect(),
            debug: debug ? { right: debug.right, bottom: debug.bottom } : null,
            bannerBottom: bannerBottom(),
        });
    };
    map.fitBounds(campusBounds, { padding: padding(), bearing: -50, duration: 0 });
    // Applied after the first fit: setting it up front makes the rotated fit throw inside MapLibre.
    map.setMaxBounds([
        [campusBounds[0][0] - 0.003, campusBounds[0][1] - 0.003],
        [campusBounds[1][0] + 0.003, campusBounds[1][1] + 0.003],
    ]);

    const startMarker = new maplibregl.Marker({
        element: markerElement('size-4 rounded-full border-[3px] border-primary bg-white shadow'),
    });
    const destMarker = new maplibregl.Marker({
        anchor: 'bottom',
        element: markerElement(
            'text-primary drop-shadow',
            `<svg viewBox="0 0 24 24" class="size-9" fill="currentColor" stroke="#fff" stroke-width="1.5"><path d="M12 22s-7-6.4-7-12a7 7 0 0 1 14 0c0 5.6-7 12-7 12z"/><circle cx="12" cy="10" r="2.5" fill="#fff"/></svg>`,
        ),
    });
    const userEl = markerElement(
        'relative size-5',
        `<span data-cone hidden class="absolute bottom-1/2 left-1/2 h-10 w-9 -translate-x-1/2 bg-linear-to-t from-[#1a73e8]/45 to-transparent [clip-path:polygon(50%_100%,0_0,100%_0)]"></span>
         <span class="dot-pulse absolute inset-0 rounded-full bg-[#1a73e8]/40" aria-hidden="true"></span>
         <span class="absolute inset-0 rounded-full border-[3px] border-white bg-[#1a73e8] shadow-md"></span>`,
    );
    const userMarker = new maplibregl.Marker({ element: userEl, rotationAlignment: 'map' });
    // 360° photo points: the viewer opens over the map (navigation goes on), from street view mode or the route.
    const photos = addPanoramas(map, data.panoramas, { graph: data.graph, landmarks: data.landmarks });

    // ---------- 360° photos along the route (route-panoramas.js): a row of them in the preview, "See this spot" on
    // the instruction card while walking. Only photos shown to users (GET /api/map) are used. The debug panel can
    // also check every photo point, hidden ones too, when an admin is signed in.
    const routePhotos = createRouteGuide(photos.points);
    let debugPhotos = null;
    let debugPhotosNote = debugPanel?.dataset.dbgPanoramas ? 'loading the admin photo list…' : 'hidden photos: sign in as admin to list them';
    // The viewer's guide on the route: which way each photo faces, what it says, the next one. The photo offered while
    // walking shows the current instruction.
    const routeGuide = (offeredId = null) => (id) => {
        const spot = routePhotos.spot(id);
        if (!spot) return null;
        const text = id === offeredId && session ? session.tracker.next.text : spot.text;
        return { bearing: spot.bearing, text, caption: spot.caption, nextId: routePhotos.nextAfter(id)?.id ?? null };
    };
    const openRoutePhoto = (id, offered = false) => photos.open(id, { guide: routeGuide(offered ? id : null) });

    const routePhotoRow = $('[data-route-photos]');
    const renderRoutePhotoRow = () => {
        const spots = routePhotos.spots;
        routePhotoRow.hidden = !spots.length;
        $('[data-route-photos-list]').replaceChildren(
            ...spots.map((spot) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.routePhoto = spot.id;
                button.className =
                    'group/photo w-24 shrink-0 text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary rounded-lg';
                button.setAttribute('aria-label', `360° photo: ${spot.point.name}, ${formatDistance(spot.along)} along the route`);
                const thumb = document.createElement('img');
                thumb.src = spot.point.thumb;
                thumb.alt = '';
                thumb.loading = 'lazy';
                thumb.className = 'h-12 w-24 rounded-lg bg-bg-light object-cover transition group-hover/photo:opacity-85';
                const label = document.createElement('span');
                label.className = 'mt-0.5 block truncate text-[11px] text-text-muted';
                label.textContent = spot.point.name;
                button.append(thumb, label);
                button.addEventListener('click', () => openRoutePhoto(spot.id));
                return button;
            }),
        );
    };

    // Called for every new route (preview, route to safety, reroute) and with null when there is none.
    const setRoutePhotos = (route) => {
        const maneuvers = route ? plan.maneuvers : [];
        const destinationName = plan?.to.name;
        routePhotos.setRoute(route, maneuvers, destinationName);
        debugPhotos?.setRoute(route, maneuvers, destinationName);
        renderRoutePhotoRow();
        renderDebug();
    };

    // ---------- Street view mode (street-view.js): walkways near a photo light up; a tap on one opens the nearest photo,
    // facing the route when the photo is on it, else along the path.
    const streetViewButton = $('[data-street-view]');
    const streetViewHint = $('[data-street-view-hint]');
    const streetViewToast = $('[data-street-view-toast]');
    const coverage = streetViewCoverage(data.graph, photos.points, { paths: data.paths });
    let streetView = false;
    let toastTimer = null;
    map.addSource('street-view', { type: 'geojson', data: { type: 'FeatureCollection', features: [] } });
    for (const [id, paint] of [
        ['street-view-casing', { 'line-color': '#ffffff', 'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 5, 20, 22] }],
        ['street-view', { 'line-color': '#1a73e8', 'line-opacity': 0.85, 'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 3, 20, 16] }],
    ]) {
        map.addLayer({ id, type: 'line', source: 'street-view', layout: { visibility: 'none', 'line-cap': 'round', 'line-join': 'round' }, paint }, 'route-casing');
    }
    // Declared with `function` so showFloor (above) can call it before this point runs.
    function renderStreetView() {
        if (!streetView || !map.getSource('street-view')) return;
        map.getSource('street-view').setData(coverageLines(coverage, shownFloor));
    }
    const setStreetView = (on) => {
        streetView = on;
        streetViewButton.setAttribute('aria-pressed', String(on));
        streetViewHint.hidden = !on;
        if (!on) streetViewToast.hidden = true;
        map.getCanvas().style.cursor = '';
        applyFocus();
        renderStreetView();
    };
    // What is shown on the map for navigating or not, and street view on or off (focus.js). Room dots hide while
    // navigating; the highlights follow street view mode.
    function applyFocus() {
        const shown = focusFor({ navigating: Boolean(session), streetView });
        // Read by the browser check (what is shown); no other use.
        root.dataset.focus = `rooms:${shown.entrances ? 'on' : 'off'} highlights:${shown.streetViewLayers ? 'on' : 'off'}`;
        map.setLayoutProperty('entrances', 'visibility', shown.entrances ? 'visible' : 'none');
        ['street-view-casing', 'street-view'].forEach((id) => map.setLayoutProperty(id, 'visibility', shown.streetViewLayers ? 'visible' : 'none'));
    }
    streetViewButton.hidden = !streetViewAvailable(photos.points);
    streetViewButton.addEventListener('click', () => setStreetView(!streetView));
    // Esc leaves the mode; Esc inside the open photo only closes the photo.
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !streetView || event.target.closest?.('[data-panorama-overlay]')) return;
        if (photos.session.openId !== null) return;
        setStreetView(false);
    });
    // How many meters `pixels` screen pixels are at a point of the map.
    const metersPer = (point, pixels) => {
        const a = map.unproject(point);
        const b = map.unproject([point.x + pixels, point.y]);
        return meters([a.lng, a.lat], [b.lng, b.lat]);
    };
    const photoAt = (event, pixels) =>
        photoAtTap(coverage, [event.lngLat.lng, event.lngLat.lat], { toleranceMeters: metersPer(event.point, pixels), floor: shownFloor });
    map.on('click', (event) => {
        if (mapTapAction({ streetView, simulating, navigating: Boolean(session) }) !== 'street-view') return;
        const photo = photoAt(event, TAP_PIXELS);
        if (!photo) {
            streetViewToast.hidden = false;
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => (streetViewToast.hidden = true), 2000);
            return;
        }
        streetViewToast.hidden = true;
        // On the route being previewed or walked: facing the way the route goes (with its arrow and instruction).
        if (routePhotos.spot(photo.id)) openRoutePhoto(photo.id, session?.offeredPhoto === photo.id);
        else photos.open(photo.id, { yaw: alongPathFacing(coverage.snaps.get(photo.id), map.getBearing()) });
    });
    map.on('mousemove', (event) => {
        if (streetView) map.getCanvas().style.cursor = photoAt(event, TAP_PIXELS) ? 'pointer' : '';
    });

    if (debugPanel?.dataset.dbgPanoramas) {
        fetch(debugPanel.dataset.dbgPanoramas, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`HTTP ${response.status}`))))
            .then(({ data: list }) => {
                debugPhotos = createRouteGuide(
                    list.map((p) => ({ id: p.id, name: p.name, floor: p.floor, northOffset: p.north_offset ?? null, isVisible: p.is_visible, coord: p.coord })),
                );
                debugPhotosNote = null;
                if (plan) debugPhotos.setRoute(plan.route, plan.maneuvers, plan.to.name);
                renderDebug();
            })
            .catch((error) => {
                debugPhotosNote = `the admin photo list could not be loaded (${error.message})`;
                renderDebug();
            });
    }

    // ---------- Pickers
    const startSelect = $('[data-start]');
    const destSelect = $('[data-destination]');
    const fillPicker = (select) => {
        const gates = document.createElement('optgroup');
        gates.label = 'Gates';
        places.filter((p) => p.kind === 'gate').forEach((p) => gates.append(new Option(p.name, p.id)));
        select.append(gates);

        const byBuilding = new Map();
        for (const p of places.filter((place) => place.kind === 'room')) {
            byBuilding.set(p.buildingId, [...(byBuilding.get(p.buildingId) ?? []), p]);
        }
        const groups = [...byBuilding].map(([id, rooms]) => {
            const feature = data.buildings.features.find((f) => f.properties.id === id);
            const name = data.landmarks.buildings?.[id] ?? feature?.properties.name ?? 'Other rooms';
            return { label: titleCase(name), rooms };
        });
        groups.sort((a, b) => a.label.localeCompare(b.label, undefined, { numeric: true }));
        for (const { label, rooms } of groups) {
            const group = document.createElement('optgroup');
            group.label = label;
            rooms
                .sort((a, b) => a.floor - b.floor || a.name.localeCompare(b.name, undefined, { numeric: true }))
                .forEach((p) => group.append(new Option(p.floor > 1 ? `${p.name} · ${ordinal(p.floor)} floor` : p.name, p.id)));
            select.append(group);
        }
    };
    fillPicker(startSelect);
    fillPicker(destSelect);
    // ?from=<place id> preselects the start (for QR codes posted around campus); ?to= the destination.
    const preset = (select, id) => {
        if (id && placeById.has(id)) select.value = id;
    };
    preset(startSelect, params.get('from') ?? 'p1');
    // A checkpoint scanned on the way (?from= alone) keeps the destination of the trip under way.
    preset(destSelect, params.get('to') ?? (params.has('from') ? recallTrip() : null));

    // ---------- Saved places (signed in only): "Save" for a room destination; Start adds it to Recent.
    const saveButton = $('[data-save-place]');
    const placeStore = saveButton
        ? createPlaceStore({
              saved: JSON.parse(saveButton.dataset.saved || '[]'),
              saveUrl: saveButton.dataset.saveUrl,
              recentUrl: saveButton.dataset.recentUrl,
              csrfToken: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
          })
        : null;
    let saveMessageTimer = 0;
    const renderSave = (message = null) => {
        if (!saveButton) return;
        const to = placeById.get(destSelect.value);
        saveButton.hidden = to?.kind !== 'room';
        if (saveButton.hidden) return;
        const saved = placeStore.isSaved(to.id);
        saveButton.toggleAttribute('data-saved', saved);
        saveButton.setAttribute('aria-label', saved ? `Remove ${to.name} from saved places` : `Save ${to.name}`);
        saveButton.querySelector('[data-save-label]').textContent = message ?? (saved ? 'Saved' : 'Save');
    };
    saveButton?.addEventListener('click', async () => {
        const to = placeById.get(destSelect.value);
        if (to?.kind !== 'room') return;
        clearTimeout(saveMessageTimer);
        const toggled = placeStore.toggle(to.id);
        renderSave();
        try {
            await toggled;
        } catch {
            renderSave(placeStore.isSaved(to.id) ? 'Couldn’t remove' : 'Couldn’t save');
            saveMessageTimer = setTimeout(() => renderSave(), 3000);
            return;
        }
        renderSave();
    });

    // ---------- Planning
    let plan = null; // { from, to, route, maneuvers }
    const summary = $('[data-summary]');
    const startButton = $('[data-start-nav]');

    const showPanel = (name) => {
        $$('[data-panel]').forEach((panel) => (panel.hidden = panel.dataset.panel !== name));
    };

    const routeColor = () => (plan?.evacuation ? SAFE_COLOR : ROUTE_COLOR);

    // Upper-floor corridors are drawn only for the floor in use, with its name on the map; ground walkways fade.
    let shownFloor = 1;
    const floorLabel = $('[data-floor-label]');
    const showFloor = (floor) => {
        const upper = floor > 1 ? floor : null;
        if ((upper ?? 1) === shownFloor) return;
        shownFloor = upper ?? 1;
        renderStreetView();
        map.setFilter('corridors', ['==', PATH_FLOOR, upper ?? -1]);
        map.setPaintProperty('walkways', 'line-opacity', upper ? GROUND_FADED : 1);
        floorLabel.hidden = !upper;
        floorLabel.textContent = upper ? `${ordinal(upper)} floor` : '';
    };
    // While planning: the destination's floor, else the start's.
    const routeFloor = (route) => (route.nodes.at(-1).floor > 1 ? route.nodes.at(-1).floor : route.nodes[0].floor);

    const lines = (parts) => ({
        type: 'FeatureCollection',
        features: parts.map((coordinates) => ({ type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates } })),
    });

    // The route draws in from the start over DRAW_IN_MS (motion.js); with reduced motion it appears at once.
    let growTimer = 0;
    function growRoute(parts) {
        clearTimeout(growTimer);
        if (!parts.length || !motionFor({ reducedMotion: reducedMotion.matches, hidden: false, viewerOpen: false }).drawIn) {
            map.getSource('route').setData(lines(parts));
            return;
        }
        const started = performance.now();
        const step = () => {
            const fraction = drawInFraction(performance.now() - started);
            map.getSource('route').setData(lines(partialLines(parts, fraction)));
            if (fraction < 1) growTimer = setTimeout(step, DRAW_STEP_MS);
        };
        step();
    }

    // The preview: the whole route, its upstairs part dashed.
    const drawRoute = (route) => {
        const parts = route ? floorParts(route) : { ground: [], upper: [] };
        growRoute(parts.ground);
        map.getSource('route-upper').setData(lines(parts.upper));
        map.setPaintProperty('route', 'line-gradient', routeGradient(0, routeColor()));
        map.setPaintProperty('route-upper', 'line-color', routeColor());
        showFloor(route ? routeFloor(route) : 1);
        setRoutePhotos(route);
    };

    // ---------- Emergency (an active alert): danger zone, blocked walkways, route to safety
    let alertNow = null;
    let alertVersion = '';
    let danger = null;
    let blocked = new Set();
    // Ordinary directions stay off blocked walkways too.
    const routeCost = () => (blocked.size ? { cost: (edge) => (blocked.has(edge.id) ? Infinity : edge.weight) } : undefined);

    const updatePlan = () => {
        const from = placeById.get(startSelect.value);
        const to = placeById.get(destSelect.value);
        renderSave();
        plan = null;
        startButton.disabled = true;
        $('[data-steps-preview]').hidden = true;
        startMarker.remove();
        destMarker.remove();
        drawRoute(null);

        if (from) startMarker.setLngLat(data.graph.nodes.get(from.nodeId).coord).addTo(map);
        if (to) destMarker.setLngLat(data.graph.nodes.get(to.nodeId).coord).addTo(map);
        if (!from || !to) {
            summary.textContent = 'Pick where you are and where you want to go.';
            return;
        }
        if (from.id === to.id) {
            summary.textContent = 'You are already there. Pick a different destination.';
            return;
        }
        const route = findRoute(data.graph, from.nodeId, to.nodeId, routeCost());
        if (!route) {
            summary.textContent = blocked.size
                ? 'No safe route between these places while the emergency alert is active.'
                : 'No walkable route between these places has been mapped yet.';
            return;
        }
        const maneuvers = buildManeuvers(route, data.graph, {
            landmarks: data.landmarks,
            buildings: data.buildings,
            originName: from.name,
            destinationName: to.name,
        });
        plan = { from, to, route, maneuvers };
        drawRoute(route);

        const floors = maneuvers.filter((m) => m.type === 'stairs').length;
        const total = document.createElement('strong');
        total.className = 'text-text-primary';
        total.textContent = formatDistance(route.length);
        summary.replaceChildren(total, ` · about ${formatMinutes(route.length)} walk${floors ? ' · includes stairs' : ''}`);
        $('[data-steps-list]').replaceChildren(
            ...maneuvers.map((m, i) => {
                const li = document.createElement('li');
                li.textContent = m.text;
                if (maneuvers[i + 1]) {
                    const leg = document.createElement('span');
                    leg.className = 'text-text-muted';
                    leg.textContent = ` · ${formatDistance(maneuvers[i + 1].legBefore)}`;
                    li.append(leg);
                }
                return li;
            }),
        );
        $('[data-steps-preview]').hidden = false;
        startButton.disabled = false;
        map.fitBounds(boundsOf(route.line.geometry.coordinates), {
            padding: padding(),
            bearing: map.getBearing(),
            maxZoom: 19.5,
            duration: 600,
        });
    };
    startSelect.addEventListener('change', updatePlan);
    destSelect.addEventListener('change', updatePlan);
    $('[data-swap]').addEventListener('click', () => {
        [startSelect.value, destSelect.value] = [destSelect.value, startSelect.value];
        updatePlan();
    });

    // ---------- Sensors & helpers shared by a navigation session
    const voice = new Voice();
    // Guests and admins get the "Voice directions" switch here (signed-in students and faculty have it in Settings).
    const voiceSwitch = $('[data-voice-directions]');
    if (voiceSwitch) initVoiceDirections(voiceSwitch);
    const wakeLock = new ScreenWakeLock();
    const secure = window.isSecureContext;
    if (!secure) $('[data-insecure]').hidden = false;

    const muteButton = $('[data-mute]');
    const renderMute = () => {
        muteButton.setAttribute('aria-pressed', String(voice.muted));
        muteButton.setAttribute('aria-label', voice.muted ? 'Unmute voice' : 'Mute voice');
        muteButton.querySelector('[data-icon-on]').classList.toggle('hidden', voice.muted);
        muteButton.querySelector('[data-icon-off]').classList.toggle('hidden', !voice.muted);
    };
    renderMute();
    muteButton.addEventListener('click', () => {
        voice.setMuted(!voice.muted);
        renderMute();
    });

    let rotate = false;
    const rotateButton = $('[data-rotate]');
    rotateButton.addEventListener('click', () => {
        rotate = !rotate;
        rotateButton.setAttribute('aria-pressed', String(rotate));
        if (!rotate) map.easeTo({ bearing: 0, duration: 400 });
        followCamera(true);
    });

    let following = true;
    const recenterButton = $('[data-recenter]');
    map.on('dragstart', () => {
        if (!session) return;
        following = false;
        recenterButton.hidden = false;
    });
    recenterButton.addEventListener('click', () => {
        following = true;
        recenterButton.hidden = true;
        followCamera(true);
    });

    // ---------- Navigation session
    let session = null;
    let cameraQueued = false;

    // GPS simulation, only with the debug panel (?debug=1): a fake position sent through applyFix like a real fix, while
    // the real GPS and step sensor are ignored. Off on every page load.
    let simulating = false;
    let simTimer = null;
    let simWalkOffArmed = false;
    const sim = debugPanel
        ? createSimulator({
              emit: (fix) => applyFix(fix, { simulated: true }),
              caughtUp: (coord) => !session || meters(session.tracker.filter.coord ?? coord, coord) < CAUGHT_UP_METERS,
          })
        : null;
    // The fake GPS position itself (before smoothing and snapping), next to the dot.
    const simMarker = debugPanel
        ? new maplibregl.Marker({ element: markerElement('size-3.5 rounded-full border-2 border-amber-500 bg-amber-200/70 shadow') })
        : null;

    function followCamera(immediate = false) {
        // Just after confirming a floor the map shows the next part of the route for a moment.
        if (!session || !following || session.cameraHoldUntil > Date.now()) return;
        if (cameraQueued && !immediate) return;
        cameraQueued = true;
        requestAnimationFrame(() => {
            cameraQueued = false;
            if (!session) return;
            const { tracker } = session;
            map.easeTo({
                center: tracker.position(),
                zoom: Math.max(map.getZoom(), FOLLOW_ZOOM),
                pitch: 45,
                bearing: rotate ? (tracker.heading.value ?? tracker.routeBearing() ?? 0) : map.getBearing(),
                padding: padding(),
                duration: immediate ? 600 : 400,
            });
        });
    }

    const speak = (prompt) => prompt && session.voice.say(prompt.text);

    // A step fills in between GPS fixes (tracker.js). Also the debug panel's "+1 step".
    function applyStep() {
        if (session && !session.manual) session.tracker.step();
    }

    // Steps from the sensor; ignored while simulating, so walking around at home doesn't move the dot.
    const sensorStep = () => !simulating && applyStep();

    // A real fix from watchPosition; ignored while simulating.
    const realFix = (position) => !simulating && applyFix(position);

    // A GPS fix: the main source of position and direction. Simulated fixes (debug only) take this same path.
    function applyFix(position, { simulated = false } = {}) {
        if (!session) return;
        const tracker = session.tracker;
        session.sensors.gps = simulated ? 'simulated' : 'on';
        const recentSteps = !simulated && session.steps.receiving ? Date.now() - tracker.lastStepAt < RECENT_STEP_MS : null;
        const usable = position.coords.accuracy <= GPS_MAX_ACCURACY;
        if (!simulated) {
            session.bestAccuracy = Math.min(session.bestAccuracy ?? Infinity, position.coords.accuracy);
            if (usable) session.hadUsableFix = true;
        }
        // A usable fix ends the wait for one; the dot then follows GPS again.
        if (usable && session.manual === 'no fix') exitManualMode();
        if (session.manual) {
            // Still shown in the debug panel while waiting for a precise fix.
            tracker.lastGps = { accuracy: position.coords.accuracy, speed: null, moving: false, used: false, reason: 'not precise enough yet' };
            renderDebug();
            return;
        }
        const result = tracker.gps(position, { recentSteps });
        // Said after the fix's own render, so it replaces any turn prompt that render queued.
        if (result.wrongWayStarted) session.voice.say(WRONG_WAY_TEXT);
        if (result.rerouteDue) reroute();
    }

    // render is attached once the tracker is in the session.
    const newTracker = (route, maneuvers, options = {}) => new Tracker(route, maneuvers, { stride: getStride(), graph: data.graph, ...options });

    function showRerouteNote(text) {
        const el = $('[data-rerouting]');
        el.textContent = text;
        el.hidden = false;
        clearTimeout(session.rerouteTimer);
        session.rerouteTimer = setTimeout(() => (el.hidden = true), REROUTE_NOTE_MS);
    }

    // The person took another way: new directions from where the dot is to the same destination.
    function reroute() {
        const here = session.tracker.position();
        // The new directions start from the way the person is walking (or name a landmark when that is unknown).
        const heading = session.tracker.heading;
        const start = attachPoint(data.graph, here);
        // Splitting a walkway for the new start adds edges, so the blocked set is worked out again.
        blocked = blockedEdges(data.graph, danger);
        const route = !start
            ? null
            : plan.evacuation
              ? findEvacuationRoute(data.graph, start.nodeId, { danger, targets: evacuationTargets, blocked })?.route
              : rerouteFrom(data.graph, here, plan.to.nodeId, routeCost());
        if (!route) {
            session.tracker.graceFixes = REROUTE_GRACE_FIXES * 3;
            session.voice.say(NO_REROUTE_TEXT);
            showRerouteNote(NO_REROUTE_TEXT);
            return;
        }
        const maneuvers = buildManeuvers(route, data.graph, {
            landmarks: data.landmarks,
            buildings: data.buildings,
            destinationName: plan.to.name,
            heading: heading.value,
        });
        plan = { ...plan, route, maneuvers };
        // The photos on the new route are worked out again.
        setRoutePhotos(route);
        session.reroutes++;
        session.planner = createPromptPlanner(maneuvers);
        // Reroutes only happen on the ground, so the new route starts its own first leg.
        session.legs = routeLegs(route, maneuvers, { destinationName: plan.to.name });
        session.legIndex = 0;
        session.pinPlaced = false;
        const opening = session.planner.next(0);
        session.voice.say([REROUTING_TEXT, legIntro(session.legs, session.legs[0]), maneuvers[0].now, opening?.text].filter(Boolean).join('. '));
        showRerouteNote(`${REROUTING_TEXT}…`);
        const previous = session.tracker;
        session.tracker = newTracker(route, maneuvers, { filter: previous.filter, graceFixes: REROUTE_GRACE_FIXES, startHeading: heading });
        session.tracker.steps = previous.steps;
        session.tracker.lastStepAt = previous.lastStepAt;
        session.tracker.lastGps = previous.lastGps;
        session.tracker.onChange = render;
        render(session.tracker);
        growRoute([legLine(route, session.legs[0], 0).geometry.coordinates]);
    }

    // Without GPS the dot can't follow the person, so they tap "Next" at each instruction. `reason` is 'no fix'
    // while waiting for a usable fix (a later one ends manual mode), or 'off' when location can't be had at all.
    function enterManualMode(note, reason = 'off') {
        if (!session) return;
        // Remembered so turning simulation off brings the message back.
        if (reason === 'off') session.gpsOffNote = note;
        if (session.manual || simulating) return;
        session.manual = reason;
        const noteEl = $('[data-sensor-note]');
        noteEl.textContent = `${note} ${reason === 'off' ? 'Tap' : 'Until then, tap'} “Next” each time you reach the instruction shown.`;
        noteEl.hidden = false;
        session.tracker.clearWrongWay();
    }

    function exitManualMode() {
        session.manual = false;
        $('[data-sensor-note]').hidden = true;
        render(session.tracker);
    }

    // "See this spot": the photo ahead on the route (30 m before it to 5 m past it; in manual mode, on the current step).
    const seeSpot = $('[data-see-spot]');
    function renderSeeSpot(tracker) {
        const spot = routePhotos.offer({ progress: tracker.progress, next: tracker.next, manual: Boolean(session.manual) });
        session.offeredPhoto = spot?.id ?? null;
        seeSpot.hidden = !spot;
        if (!spot) return;
        const thumb = seeSpot.querySelector('[data-see-spot-thumb]');
        if (thumb.getAttribute('src') !== spot.point.thumb) thumb.src = spot.point.thumb;
        seeSpot.querySelector('[data-see-spot-name]').textContent = spot.point.name;
    }
    seeSpot.addEventListener('click', () => {
        if (session?.offeredPhoto) openRoutePhoto(session.offeredPhoto, true);
    });

    // ---------- Motion (motion.js; CSS for the pulse and the pin's drop in navigate-page.blade.php and app.css)
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let glideFrame = 0;
    const motionNow = () => motionFor({ reducedMotion: reducedMotion.matches, hidden: document.hidden, viewerOpen: photos.session.openId !== null });
    // The route's flowing dashes: an SVG over the map, placed with map.project when the map moves, and animated in CSS
    // (app.css) so the map itself is not repainted for them. Hidden when no route is being walked.
    const flowSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    flowSvg.setAttribute('class', 'route-flow-svg');
    flowSvg.setAttribute('aria-hidden', 'true');
    const flowPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    flowSvg.append(flowPath);
    map.getCanvasContainer().append(flowSvg);
    let flowCoords = null;
    // The SVG covers only the route's box (a full-screen layer would be repainted in full for every dash step).
    function updateFlow() {
        flowSvg.style.display = flowCoords ? '' : 'none';
        if (!flowCoords) return;
        const points = flowCoords.map((coord) => {
            const point = map.project(coord);
            return [point.x, point.y];
        });
        const pad = 8;
        const xs = points.map(([x]) => x);
        const ys = points.map(([, y]) => y);
        const [left, top] = [Math.min(...xs) - pad, Math.min(...ys) - pad];
        const [width, height] = [Math.max(...xs) - left + pad, Math.max(...ys) - top + pad];
        Object.assign(flowSvg.style, { left: `${left}px`, top: `${top}px`, width: `${width}px`, height: `${height}px` });
        flowSvg.setAttribute('viewBox', `${left} ${top} ${width} ${height}`);
        flowPath.setAttribute('d', pathData(points));
    }
    map.on('move', updateFlow);
    // The dashes move on a timer, a few times a second: each step repaints only the route's box, and nothing animates
    // in between. Paused while the tab is hidden or a photo is open; off with reduced motion (CSS hides the SVG).
    let flowElapsed = 0;
    let flowLast = performance.now();
    setInterval(() => {
        const now = performance.now();
        const elapsed = Math.min(now - flowLast, 1000);
        flowLast = now;
        if (!session || !flowCoords || reducedMotion.matches || !motionNow().flow) return;
        flowElapsed += elapsed;
        flowPath.style.strokeDashoffset = `${flowDashOffset(flowElapsed)}px`;
    }, FLOW_TICK_MS);
    // The dot glides from its old spot to the new one (a jump when reduced motion is set).
    function moveDot(coord) {
        cancelAnimationFrame(glideFrame);
        const previous = userMarker.getLngLat?.();
        if (!previous || reducedMotion.matches || !motionNow().glide) {
            userMarker.setLngLat(coord);
            return;
        }
        const from = [previous.lng, previous.lat];
        const started = performance.now();
        const step = (now) => {
            const elapsed = now - started;
            userMarker.setLngLat(glidePoint(from, coord, elapsed));
            if (elapsed < GLIDE_MS) glideFrame = requestAnimationFrame(step);
        };
        glideFrame = requestAnimationFrame(step);
    }
    // The pin drops in when it moves to the room (CSS pin-drop; off with reduced motion).
    function dropPin() {
        if (!motionFor({ reducedMotion: reducedMotion.matches, hidden: false, viewerOpen: false }).pin) return;
        const element = destMarker.getElement();
        element.classList.remove('pin-drop');
        void element.offsetWidth;
        element.classList.add('pin-drop');
    }
    // Pausing the CSS pulse: the navigation page is marked while the tab is hidden or a photo is open.
    const syncPaused = () => root.toggleAttribute('data-motion-paused', document.hidden || photos.session.openId !== null);
    document.addEventListener('visibilitychange', syncPaused);
    const photoOverlay = document.querySelector('[data-panorama-overlay]');
    if (photoOverlay) new MutationObserver(syncPaused).observe(photoOverlay, { attributes: true, attributeFilter: ['hidden'] });
    syncPaused();

    function render(tracker) {
        if (!session) return;
        const next = tracker.next;
        const coord = tracker.position();
        moveDot(coord);
        // The cone shows which way the person is going, when that is known (GPS movement or the start door).
        const heading = tracker.heading;
        userEl.querySelector('[data-cone]').hidden = heading.value === null;
        if (heading.value !== null) userMarker.setRotation(heading.value);
        // One floor at a time: the rest of the current leg is drawn and the pin is on its end (the stairs, or on the
        // last leg the destination), with the corridors of the floor the person is on (on the stairs: the floor they
        // are leaving, until they confirm the next one).
        const leg = currentLeg(session.legs, tracker);
        const legChanged = leg.index !== session.legIndex;
        session.legIndex = leg.index;
        clearTimeout(growTimer);
        const legRoute = legLine(tracker.route, leg, tracker.progress);
        map.getSource('route').setData(legRoute);
        flowCoords = legRoute.geometry.coordinates;
        updateFlow();
        if (legChanged || !session.pinPlaced) {
            session.pinPlaced = true;
            // Routes to safety have no pin at the end: the evacuation area itself is highlighted.
            if (plan.evacuation && leg.target.kind === 'destination') destMarker.remove();
            else {
                destMarker.setLngLat(leg.target.coord).addTo(map);
                dropPin();
            }
        }
        $('[data-dest]').textContent = legLabel(session.legs, leg);
        showFloor(tracker.waitingAt ? tracker.waitingAt.fromFloor : tracker.floorAt());
        if (legChanged) {
            // A floor was just confirmed: show the whole next part, then follow the dot again.
            const shown = legLine(tracker.route, leg, tracker.progress).geometry.coordinates;
            map.fitBounds(boundsOf([...shown, leg.target.coord]), { padding: padding(), bearing: map.getBearing(), maxZoom: 19.5, duration: 600 });
            session.cameraHoldUntil = Date.now() + LEG_FIT_MS;
        }

        if (tracker.arrived) return finish(true);

        $('[data-icon]').innerHTML = `<svg viewBox="0 0 24 24" class="size-8" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">${iconFor(next)}</svg>`;
        $('[data-instruction]').textContent = next.text;
        $('[data-next-distance]').textContent = tracker.waitingAt ? 'Now' : formatDistance(tracker.distanceToNext);
        const after = plan.maneuvers[next.index + 1];
        $('[data-then]').textContent = after ? `Then: ${after.text}` : '';
        $('[data-remaining]').textContent = formatDistance(tracker.remaining);
        $('[data-eta]').textContent = formatMinutes(tracker.remaining);

        const stairs = tracker.waitingAt;
        $('[data-stairs]').hidden = !stairs;
        $('[data-im-here]').hidden = !!stairs || Boolean(session.manual);
        $('[data-next-step]').hidden = !!stairs || !session.manual;
        $('[data-wrong-way]').hidden = !tracker.wrongWay;
        if (stairs) $('[data-confirm-floor]').textContent = `I'm on the ${ordinal(stairs.toFloor)} floor`;
        renderSeeSpot(tracker);

        // While on the stairs only the stairs prompt itself may play.
        // Only a heading from GPS movement may skip a turn the person is already walking out of.
        const walking = heading.source === 'gps' ? heading.value : null;
        const prompt = !stairs || !session.planner.said.has(`near:${stairs.index}`) ? session.planner.next(tracker.progress, walking) : null;
        // A new leg that ends at stairs again is introduced first ("Go to the stairs near …, then …").
        const intro = legChanged ? legIntro(session.legs, leg) : null;
        if (intro) session.voice.say([intro, prompt?.text].filter(Boolean).join('. '));
        else speak(prompt);

        followCamera();
        renderDebug();
    }

    async function start(planner, motionPermission) {
        const { maneuvers, route, to } = plan;
        const facing = doorHeading(route);
        const tracker = newTracker(route, maneuvers, { startHeading: facing === null ? null : { value: facing, source: 'door' } });
        // A QR checkpoint scanned on the way reopens this page; it then offers the same destination.
        if (to.id) rememberTrip(to.id);
        session = {
            tracker,
            planner,
            voice,
            // The route in legs, one per floor stretch (legs.js); the first one was introduced when Start was tapped.
            legs: plan.legs,
            legIndex: 0,
            pinPlaced: false,
            cameraHoldUntil: 0,
            steps: new StepDetector(sensorStep),
            manual: false,
            reroutes: 0,
            rerouteTimer: null,
            watchId: null,
            gpsTimer: null,
            hadUsableFix: false,
            bestAccuracy: null,
            gpsOffNote: null,
            startedAt: Date.now(),
            sensors: { motion: 'waiting', gps: secure ? 'waiting' : 'needs HTTPS' },
            gpsError: '',
        };
        const current = session;
        // Starting navigation turns street view off (its highlights and markers are part of what is hidden).
        setStreetView(false);
        tracker.onChange = render;
        // The preview's dashed upstairs part goes: navigation draws one floor at a time.
        map.getSource('route-upper').setData({ type: 'FeatureCollection', features: [] });

        showPanel('nav');
        $('[data-sensor-note]').hidden = true;
        startMarker.remove();
        userMarker.setLngLat(tracker.position()).addTo(map);
        following = true;
        recenterButton.hidden = true;
        wakeLock.acquire();
        render(tracker);
        if (simulating) startSimClock();

        // GPS is the main source of position and direction.
        if (!secure) {
            enterManualMode(NO_HTTPS_NOTE);
        } else if (!('geolocation' in navigator)) {
            enterManualMode(NO_GPS_NOTE);
        } else {
            session.watchId = navigator.geolocation.watchPosition(
                realFix,
                (error) => {
                    if (session !== current) return;
                    session.sensors.gps = error.code === error.PERMISSION_DENIED ? 'denied' : 'error';
                    session.gpsError = error.message;
                    if (error.code === error.PERMISSION_DENIED) enterManualMode(LOCATION_DENIED_NOTE);
                    renderDebug();
                },
                { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 },
            );
            session.gpsTimer = setTimeout(() => {
                if (session === current && !session.hadUsableFix && !simulating) enterManualMode(noFixNote(session.bestAccuracy), 'no fix');
            }, GPS_WAIT_MS);
        }

        // The step sensor only helps between fixes; without it nothing else changes.
        const motion = await motionPermission;
        // The person may have ended navigation while the permission prompt was open.
        if (session !== current) return;
        session.sensors.motion = motion;
        if (secure && motion === 'granted') session.steps.start();
        renderDebug();
    }

    function stop() {
        if (!session) return;
        clearTimeout(session.gpsTimer);
        clearTimeout(session.rerouteTimer);
        $('[data-rerouting]').hidden = true;
        forgetTrip();
        session.steps.stop();
        if (session.watchId !== null) navigator.geolocation.clearWatch(session.watchId);
        wakeLock.release();
        session = null;
        flowCoords = null;
        updateFlow();
        stopSimClock();
        applyFocus();
    }

    // The fake GPS starts where the dot is and sends a fix every second.
    function startSimClock() {
        if (session.manual) exitManualMode();
        sim.place(session.tracker.position());
        sim.stop();
        clearInterval(simTimer);
        simTimer = setInterval(() => {
            sim.tick();
            renderSim();
        }, SIM_INTERVAL_MS);
        renderSim();
    }

    function stopSimClock() {
        clearInterval(simTimer);
        simTimer = null;
        sim?.stop();
        simWalkOffArmed = false;
        renderSim();
    }

    function setSimulating(on) {
        simulating = on;
        stopSimClock();
        if (!session) return;
        if (on) {
            startSimClock();
            return;
        }
        // Back to the real GPS: the same messages as without simulation.
        if (session.sensors.gps === 'simulated') session.sensors.gps = 'waiting';
        if (session.gpsOffNote) enterManualMode(session.gpsOffNote);
        else if (!session.hadUsableFix && Date.now() - session.startedAt >= GPS_WAIT_MS) enterManualMode(noFixNote(session.bestAccuracy), 'no fix');
        renderDebug();
    }

    function finish(arrived) {
        const tracker = session.tracker;
        const arrive = tracker.arriveManeuver;
        if (arrived && !session.planner.said.has(`near:${arrive.index}`)) session.voice.say(arrive.now);
        stop();
        if (arrived) {
            $('[data-arrived-text]').textContent = arrive.text;
            showPanel('arrived');
        } else {
            voice.silence();
            backToPlan();
        }
    }

    function backToPlan() {
        userMarker.remove();
        showPanel(alertNow ? 'emergency' : 'plan');
        map.easeTo({ pitch: 0, duration: 400 });
        updatePlan();
        renderDebug();
    }

    const requestPermission = (Api) =>
        typeof Api?.requestPermission === 'function'
            ? Api.requestPermission().catch(() => 'denied')
            : Promise.resolve(Api ? 'granted' : 'unsupported');

    // Must run inside a tap: iOS only allows the sensor prompts and speech there.
    function beginNavigation(intro, { voiceAlwaysOn = false } = {}) {
        // These run before anything is awaited.
        const motionPermission = requestPermission(window.DeviceMotionEvent);
        // Voice follows the "Voice directions" setting (off by default) for each new trip; mute only changes this one.
        voice.startTrip({ alwaysOn: voiceAlwaysOn });
        renderMute();
        // Speaking here also unlocks speech on iOS; the first due prompt joins the opening line.
        const planner = createPromptPlanner(plan.maneuvers);
        const opening = planner.next(0);
        plan.legs = routeLegs(plan.route, plan.maneuvers, { destinationName: plan.to.name });
        voice.unlock([intro, legIntro(plan.legs, plan.legs[0]), plan.maneuvers[0].now, opening?.text].filter(Boolean).join('. '));
        start(planner, motionPermission);
    }

    startButton.addEventListener('click', () => {
        if (!plan) return;
        if (plan.to.kind === 'room') placeStore?.recordRecent(plan.to.id);
        beginNavigation(`Starting navigation to ${plan.to.name}`);
    });

    // ---------- Emergency mode
    const evacStart = $('[data-evac-start]');
    const evacNote = $('[data-evac-note]');
    const note = (text, isError = false) => {
        evacNote.textContent = text;
        evacNote.toggleAttribute('data-error', isError);
    };
    const incidentEl = markerElement('grid size-10 place-items-center rounded-full bg-[#B3121B] text-white shadow-lg ring-4 ring-white');
    const incidentMarker = new maplibregl.Marker({ element: incidentEl });
    const evacuationLabel = new maplibregl.Marker({
        element: markerElement(
            'pointer-events-none rounded-full bg-[#0F6B3A] px-2.5 py-1 text-xs font-semibold whitespace-nowrap text-white shadow-md',
            'Evacuation area',
        ),
    });
    const buildingName = (feature) => titleCase(data.landmarks.buildings?.[feature.properties.id] ?? feature.properties.name);
    const centroid = (ring) => {
        const points = ring.slice(0, -1);
        return [points.reduce((sum, [lng]) => sum + lng, 0) / points.length, points.reduce((sum, [, lat]) => sum + lat, 0) / points.length];
    };

    // Start choices: the QR code's place (?from=), "My location" once found, or a building.
    const scannedPlace = placeById.get(params.get('from'));
    if (scannedPlace) {
        const group = document.createElement('optgroup');
        group.label = 'Where you scanned';
        group.append(new Option(scannedPlace.floor > 1 ? `${scannedPlace.name} · ${ordinal(scannedPlace.floor)} floor` : scannedPlace.name, `place:${scannedPlace.id}`));
        evacStart.append(group);
    }
    const buildingGroup = document.createElement('optgroup');
    buildingGroup.label = 'Buildings';
    data.buildings.features
        .filter((f) => ['building', 'facility'].includes(f.properties.type))
        .map((f) => ({ id: f.properties.id, name: buildingName(f) }))
        .sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }))
        .forEach(({ id, name }) => buildingGroup.append(new Option(name, `building:${id}`)));
    evacStart.append(buildingGroup);
    if (scannedPlace) evacStart.value = `place:${scannedPlace.id}`;

    let gpsStart = null; // { nodeId, accuracy }
    const buildingStarts = new Map();

    // Graph nodes a start choice can begin from. A building: its ground-floor doors, or the nearest walkway point.
    function startNodes(choice) {
        const [kind, id] = choice.split(':');
        if (kind === 'place') return [placeById.get(id)?.nodeId].filter(Boolean);
        if (kind === 'gps') return gpsStart ? [gpsStart.nodeId] : [];
        if (kind !== 'building') return [];
        if (!buildingStarts.has(id)) {
            const doors = places.filter((p) => p.kind === 'room' && p.buildingId === id && p.floor === 1).map((p) => p.nodeId);
            const feature = data.buildings.features.find((f) => f.properties.id === id);
            const fallback = doors.length || !feature ? null : attachPoint(data.graph, centroid(feature.geometry.coordinates[0]))?.nodeId;
            buildingStarts.set(id, doors.length ? doors : [fallback].filter(Boolean));
        }
        return buildingStarts.get(id);
    }

    const showStartMarker = () => {
        const nodeId = evacStart.value ? startNodes(evacStart.value)[0] : null;
        if (nodeId) startMarker.setLngLat(data.graph.nodes.get(nodeId).coord).addTo(map);
        else startMarker.remove();
    };
    evacStart.addEventListener('change', () => {
        note('');
        showStartMarker();
    });

    function evacuate() {
        if (!alertNow || session) return;
        if (!evacStart.value) {
            note('Choose where you are, or tap “My location”.', true);
            evacStart.focus();
            return;
        }
        // Splitting a walkway for a GPS start adds edges, so the blocked set is worked out now.
        blocked = blockedEdges(data.graph, danger);
        const results = startNodes(evacStart.value)
            .map((nodeId) => findEvacuationRoute(data.graph, nodeId, { danger, targets: evacuationTargets, blocked }))
            .filter(Boolean);
        if (!results.length) {
            note(NO_SAFE_ROUTE, true);
            voice.unlock(NO_SAFE_ROUTE);
            return;
        }
        // Prefer starts outside the danger, then the shortest.
        const best = results.reduce((a, b) => (a.startsInDanger !== b.startsInDanger ? (a.startsInDanger ? b : a) : a.route.cost <= b.route.cost ? a : b));
        const fromName = evacStart.selectedOptions[0].textContent;
        const maneuvers = buildManeuvers(best.route, data.graph, {
            landmarks: data.landmarks,
            buildings: data.buildings,
            originName: fromName,
            destinationName: EVACUATION_NAME,
        });
        plan = { from: { name: fromName }, to: { name: EVACUATION_NAME }, route: best.route, maneuvers, evacuation: true };
        drawRoute(best.route);
        note('');
        // The route to safety always speaks, even with "Voice directions" off (it can still be muted for this trip).
        beginNavigation(best.startsInDanger ? 'You are in the danger area. Leave it now' : 'Route to safety', { voiceAlwaysOn: true });
    }

    $('[data-evac-go]').addEventListener('click', evacuate);
    $('[data-evac-plan]').addEventListener('click', () => {
        showPanel('plan');
        updatePlan();
    });
    $('[data-evac-back]').addEventListener('click', () => {
        showPanel('emergency');
        startMarker.remove();
        destMarker.remove();
        drawRoute(null);
        showStartMarker();
    });
    // "Show route to safety" in the takeover or banner on this page (resources/js/alerts), still inside that tap.
    window.addEventListener('cnas:evacuate', () => {
        if (!alertNow || session) return;
        showPanel('emergency');
        if (evacStart.value) evacuate();
        else {
            note('Choose where you are, or tap “My location”.');
            evacStart.focus();
        }
    });

    $('[data-evac-locate]').addEventListener('click', () => {
        if (!secure || !('geolocation' in navigator)) {
            note('Finding your location needs a secure (https) connection. Choose your building instead.', true);
            return;
        }
        note('Finding your location…');
        navigator.geolocation.getCurrentPosition(
            ({ coords }) => {
                // As in navigation: only a precise fix can pick the right walkway.
                if (coords.accuracy > EVACUATION_START_ACCURACY) {
                    note(`Your location is only accurate to about ${Math.round(coords.accuracy)} m. Choose your building instead.`, true);
                    return;
                }
                const hit = attachPoint(data.graph, [coords.longitude, coords.latitude]);
                if (!hit || hit.distance > OFF_CAMPUS_METERS) {
                    note("You don't seem to be on campus. Choose your building instead.", true);
                    return;
                }
                gpsStart = { nodeId: hit.nodeId, accuracy: coords.accuracy };
                let option = evacStart.querySelector('option[value="gps"]');
                if (!option) {
                    option = new Option('', 'gps');
                    evacStart.insertBefore(option, evacStart.options[1] ?? null);
                }
                option.textContent = `My location (±${Math.round(coords.accuracy)} m)`;
                evacStart.value = 'gps';
                note('');
                showStartMarker();
            },
            (error) =>
                note(
                    error.code === error.PERMISSION_DENIED
                        ? 'Location access is off for this site. Choose your building instead.'
                        : "Your location couldn't be found. Choose your building instead.",
                    true,
                ),
            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
        );
    });

    // Draws (or clears) the emergency on the map and switches the sheet. Runs when the alert changes.
    function applyAlert(alert) {
        // The banner may have appeared or gone: the desktop card moves below it.
        requestAnimationFrame(placeSheet);
        // An advisory has no danger zone and no route to safety, so the map only changes for a warning or an emergency.
        const active = alert?.status === 'active' && alert.severity !== 'advisory' ? alert : null;
        const version = active?.version ?? '';
        if (version === alertVersion) return;
        const wasActive = alertNow !== null;
        alertVersion = version;
        alertNow = active;
        danger = dangerFromAlert(active, data.buildings);
        blocked = blockedEdges(data.graph, danger);

        const empty = { type: 'FeatureCollection', features: [] };
        map.getSource('danger').setData(danger?.center ? circlePolygon(danger.center, danger.radius) : empty);
        map.getSource('blocked').setData({
            type: 'FeatureCollection',
            features: [...blocked].map((id) => {
                const edge = data.graph.edges.get(id);
                return {
                    type: 'Feature',
                    properties: {},
                    geometry: { type: 'LineString', coordinates: [data.graph.nodes.get(edge.a).coord, data.graph.nodes.get(edge.b).coord] },
                };
            }),
        });
        const affectedFilter = ['in', ['get', 'id'], ['literal', active?.building_codes ?? []]];
        map.setFilter('affected', affectedFilter);
        map.setFilter('affected-outline', affectedFilter);
        ['evacuation-fill', 'evacuation-outline'].forEach((id) => map.setLayoutProperty(id, 'visibility', active ? 'visible' : 'none'));
        $('[data-evac-back]').hidden = !active;

        if (danger?.center) {
            const icon = $$('[data-emergency-icon]').find((el) => el.dataset.emergencyIcon === active.type)?.cloneNode(true);
            icon?.toggleAttribute('hidden', false); // SVG has no `hidden` property
            incidentEl.replaceChildren(...(icon ? [icon] : []));
            incidentMarker.setLngLat(danger.center).addTo(map);
        } else {
            incidentMarker.remove();
        }
        if (active && evacuationArea) evacuationLabel.setLngLat(centroid(evacuationArea.geometry.coordinates[0])).addTo(map);
        else evacuationLabel.remove();

        if (active) {
            $$('[data-emergency-icon]').forEach((icon) => icon.toggleAttribute('hidden', icon.dataset.emergencyIcon !== active.type));
            $('[data-emergency-type]').textContent = active.type_label;
            $('[data-emergency-location]').textContent = active.location;
            $('[data-emergency-drill]').hidden = !active.is_drill;
        }

        if (session) return; // Mid-walk: keep the current directions on screen.
        if (active && !wasActive) {
            showPanel('emergency');
            plan = null;
            startMarker.remove();
            destMarker.remove();
            drawRoute(null);
            showStartMarker();
            // Show the danger and the evacuation area together.
            const points = [
                ...(danger?.center ? [danger.center] : []),
                ...danger.rings.flat(),
                ...(evacuationArea ? evacuationArea.geometry.coordinates[0] : []),
            ];
            if (hasDangerArea(danger) && points.length) {
                map.fitBounds(boundsOf(points), { padding: padding(), bearing: map.getBearing(), maxZoom: 19, duration: 600 });
            }
        } else if (!active && wasActive) {
            note('');
            showPanel('plan');
            updatePlan();
        } else if (!$('[data-panel="plan"]').hidden) {
            updatePlan();
        }
    }

    $('[data-im-here]').addEventListener('click', () => session?.tracker.imHere());
    $('[data-next-step]').addEventListener('click', () => session?.tracker.imHere());
    $('[data-confirm-floor]').addEventListener('click', () => session?.tracker.confirmFloor());
    $('[data-end]').addEventListener('click', () => finish(false));
    $('[data-done]').addEventListener('click', backToPlan);

    // ---------- Debug panel (?debug=1)
    function renderDebug() {
        if (!debugPanel) return;
        const t = session?.tracker;
        const gps = t?.lastGps;
        const set = (key, value) => (debugPanel.querySelector(`[data-dbg="${key}"]`).textContent = value);
        const degrees = (value) => `${Math.round(value)}°`;
        set('build', BUILD);
        set('gps', gps ? `±${gps.accuracy.toFixed(1)} m, ${gps.used ? 'used' : `ignored (${gps.reason})`}` : session?.gpsError || '-');
        set('speed', gps ? `${gps.speed === null ? 'none' : `${gps.speed.toFixed(1)} m/s`}, ${gps.moving ? 'moving' : 'still'}` : '-');
        const heading = t?.heading;
        set('heading', heading ? (heading.value === null ? 'unknown' : `${degrees(heading.value)} (${heading.source})`) : '-');
        set('steps', t ? t.steps : '-');
        const detector = session?.steps;
        set('motion', !session ? '-' : detector.receiving ? `yes (${detector.events})` : 'no');
        set('peak', detector?.lastPeak !== null && detector?.lastPeak !== undefined ? detector.lastPeak.toFixed(2) : '-');
        set('source', !session ? '-' : session.manual && t.source !== 'manual' ? 'manual (Next button)' : { start: 'start point', steps: 'steps', gps: 'GPS', manual: 'I\'m here / Next' }[t.source]);
        set('off-route', t ? `${t.offRoute.toFixed(1)} m (limit ${OFF_ROUTE_METERS}), ${t.offRouteStreak} fixes, ${session.reroutes} reroutes` : '-');
        set('progress', t ? `${t.progress.toFixed(1)} / ${t.length.toFixed(1)} m` : '-');
        set('mode', session ? (session.manual ? `manual (${session.manual})` : simulating ? 'SIMULATED GPS' : 'GPS + steps') : '-');
        set('sensors', session ? Object.entries(session.sensors).map(([k, v]) => `${k}: ${v}`).join(', ') : `secure: ${secure}`);
        set('graph', `${data.graph.nodes.size} nodes, ${data.graph.edges.size} edges`);
        set('panoramas', [...debugLines((debugPhotos ?? routePhotos).result), ...(debugPhotosNote ? [debugPhotosNote] : [])].join('\n'));
        renderSim();
    }

    function renderSim() {
        if (!debugPanel) return;
        debugPanel.querySelectorAll('[data-sim-flag], [data-sim-badge]').forEach((el) => (el.hidden = !simulating));
        const ready = simulating && session !== null;
        debugPanel.querySelector('[data-sim-walk]').disabled = !ready;
        const walkOff = debugPanel.querySelector('[data-sim-walk-off]');
        walkOff.disabled = !ready;
        walkOff.setAttribute('aria-pressed', String(simWalkOffArmed));
        walkOff.textContent = simWalkOffArmed ? 'Tap a walkway…' : 'Walk off route';
        let status = 'Off: the real GPS is used.';
        if (simulating && !session) status = 'Start navigation, then tap the map or use the buttons.';
        else if (simWalkOffArmed) status = 'Tap the map where to walk to.';
        else if (ready && sim.mode === 'walking') status = `Walking at 1.2 m/s, ${sim.secondsLeft} s left.`;
        else if (ready && sim.mode === 'catching up') status = 'At the fake position; the dot is catching up.';
        else if (ready) status = 'Standing still. Tap the map to move the fake position.';
        debugPanel.querySelector('[data-sim-status]').textContent = status;
        if (ready && sim.coord) simMarker.setLngLat(sim.coord).addTo(map);
        else simMarker.remove();
    }

    if (debugPanel) {
        const strideInput = debugPanel.querySelector('[data-dbg-stride]');
        const thresholdInput = debugPanel.querySelector('[data-dbg-threshold]');
        strideInput.value = getStride();
        thresholdInput.value = getThreshold();
        strideInput.addEventListener('change', () => {
            const value = Number(strideInput.value);
            if (!(value > 0.2 && value < 1.5)) return;
            setStride(value);
            if (session) session.tracker.stride = value;
        });
        thresholdInput.addEventListener('change', () => {
            const value = Number(thresholdInput.value);
            if (!(value > 0)) return;
            setThreshold(value);
            if (session) session.steps.threshold = value;
        });
        // A fake step, for testing at a desk.
        debugPanel.querySelector('[data-dbg-step]').addEventListener('click', applyStep);
        debugPanel.addEventListener('toggle', () => followCamera(true));
        // Motion readings and ignored fixes arrive without anything else changing, so the panel also refreshes on a timer.
        setInterval(renderDebug, 1000);
        // GPS simulation: the switch, the two walks, and taps on the map.
        debugPanel.querySelector('[data-sim-toggle]').addEventListener('change', (e) => setSimulating(e.target.checked));
        debugPanel.querySelector('[data-sim-walk]').addEventListener('click', () => {
            if (!simulating || !session) return;
            simWalkOffArmed = false;
            sim.walk(session.tracker.remainingLine().geometry.coordinates);
            renderSim();
        });
        debugPanel.querySelector('[data-sim-walk-off]').addEventListener('click', () => {
            if (!simulating || !session) return;
            simWalkOffArmed = !simWalkOffArmed;
            renderSim();
        });
        map.on('click', (event) => {
            // In street view mode a tap looks for a photo: it is not a simulated position.
            if (mapTapAction({ streetView, simulating, navigating: Boolean(session) }) !== 'simulate') return;
            const coord = [event.lngLat.lng, event.lngLat.lat];
            if (simWalkOffArmed) {
                simWalkOffArmed = false;
                sim.walkTo(coord);
            } else {
                sim.place(coord);
            }
            renderSim();
        });
        debugPanel.querySelector('[data-dbg-graph]').addEventListener('change', (e) => {
            ['graph-edges', 'graph-nodes'].forEach((id) => map.setLayoutProperty(id, 'visibility', e.target.checked ? 'visible' : 'none'));
        });
        renderDebug();
    }

    showPanel('plan');
    // Once `session` exists (applyFocus reads it): room dots on, highlights off.
    applyFocus();
    updatePlan();
    // resources/js/alerts loads first and keeps the current alert in window.cnasAlert, then sends every poll.
    applyAlert(window.cnasAlert ?? null);
    window.addEventListener('cnas:alert', (event) => applyAlert(event.detail));
    // Opened from "Show route to safety" on another page: the route needs a tap here (voice and sensors).
    if (alertNow && params.has('evacuate')) {
        note(evacStart.value ? 'Tap “Show route to safety” to start.' : 'Choose where you are, or tap “My location”.');
        $('[data-evac-go]').focus();
    }
    root.dataset.ready = '';
}

const root = document.querySelector('[data-nav]');
if (root) {
    init(root).catch((error) => {
        console.error('Navigation failed to load', error);
        root.setAttribute('data-failed', '');
    });
}
