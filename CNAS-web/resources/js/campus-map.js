import maplibregl from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';

const COLORS = {
    ground: '#e7ecdf',
    campus: '#cfdcc3',
    evacuation: '#b9d1a8',
    facility: '#e4d8c6',
    walkway: '#ffffff',
    gate: '#C59B27',
    building: '#e9ddd0',
    selected: '#C59B27',
    entrance: '#581825',
};

const METERS_PER_FLOOR = 3.5;

// Returns [[minLng, minLat], [maxLng, maxLat]] for a GeoJSON polygon ring.
function ringBounds(ring) {
    const lngs = ring.map(([lng]) => lng);
    const lats = ring.map(([, lat]) => lat);
    return [[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]];
}

function padBounds([[minLng, minLat], [maxLng, maxLat]], ratio) {
    const dLng = (maxLng - minLng) * ratio;
    const dLat = (maxLat - minLat) * ratio;
    return [[minLng - dLng, minLat - dLat], [maxLng + dLng, maxLat + dLat]];
}

// Centers and zooms the current view so the projected ring fills the canvas, minus padding.
function fitOutline(map, ring, padding) {
    const points = ring.map((coord) => map.project(coord));
    const xs = points.map((p) => p.x);
    const ys = points.map((p) => p.y);
    const [minX, maxX, minY, maxY] = [Math.min(...xs), Math.max(...xs), Math.min(...ys), Math.max(...ys)];
    const { clientWidth: width, clientHeight: height } = map.getContainer();
    const scale = Math.min((width - padding * 2) / (maxX - minX), (height - padding * 2) / (maxY - minY));

    map.jumpTo({
        center: map.unproject([(minX + maxX) / 2, (minY + maxY) / 2]),
        zoom: map.getZoom() + Math.log2(scale),
    });
}

async function initCampusMap(el) {
    const base = el.dataset.mapsUrl;
    const [buildings, paths, entrances] = await Promise.all(
        ['buildings', 'paths', 'entrances'].map((name) => fetch(`${base}/${name}.geojson`).then((res) => res.json())),
    );

    const campus = buildings.features.find((f) => f.properties.type === 'campus');
    const campusBounds = ringBounds(campus.geometry.coordinates[0]);
    const label = el.parentElement.querySelector('[data-campus-map-label]');

    const map = new maplibregl.Map({
        container: el,
        style: {
            version: 8,
            sources: {
                buildings: { type: 'geojson', data: buildings, promoteId: 'id' },
                paths: { type: 'geojson', data: paths },
                entrances: { type: 'geojson', data: entrances },
            },
            layers: [
                { id: 'ground', type: 'background', paint: { 'background-color': COLORS.ground } },
                {
                    id: 'campus',
                    type: 'fill',
                    source: 'buildings',
                    filter: ['==', ['get', 'type'], 'campus'],
                    paint: { 'fill-color': COLORS.campus, 'fill-outline-color': '#9fb48f' },
                },
                {
                    id: 'grounds',
                    type: 'fill',
                    source: 'buildings',
                    filter: ['in', ['get', 'type'], ['literal', ['evacuation', 'facility']]],
                    paint: {
                        'fill-color': ['match', ['get', 'type'], 'evacuation', COLORS.evacuation, COLORS.facility],
                    },
                },
                {
                    id: 'walkways',
                    type: 'line',
                    source: 'paths',
                    layout: { 'line-cap': 'round', 'line-join': 'round' },
                    paint: {
                        'line-color': ['match', ['get', 'type'], 'gate', COLORS.gate, COLORS.walkway],
                        'line-width': ['interpolate', ['exponential', 2], ['zoom'], 16, 2, 20, 18],
                    },
                },
                {
                    id: 'buildings-3d',
                    type: 'fill-extrusion',
                    source: 'buildings',
                    filter: ['==', ['get', 'type'], 'building'],
                    paint: {
                        'fill-extrusion-color': [
                            'case',
                            ['boolean', ['feature-state', 'selected'], false],
                            COLORS.selected,
                            COLORS.building,
                        ],
                        'fill-extrusion-height': ['*', ['coalesce', ['get', 'floors'], 1], METERS_PER_FLOOR],
                        'fill-extrusion-opacity': 0.95,
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
            ],
        },
        bounds: campusBounds,
        minZoom: 15.5,
        maxZoom: 21,
        maxPitch: 70,
        // Like an embedded Google Map: one finger scrolls the page, two fingers move the map;
        // on desktop, drag moves the map and Ctrl/⌘ + scroll zooms.
        cooperativeGestures: true,
        attributionControl: false,
    });

    // Rotate the campus so its long side runs across the card, then tilt for the 3D look.
    // fitBounds only fits the north-aligned box, so refine using the outline's on-screen size.
    map.once('load', () => {
        map.fitBounds(campusBounds, { padding: 16, bearing: -50, pitch: 45, duration: 0 });
        for (let i = 0; i < 3; i++) fitOutline(map, campus.geometry.coordinates[0], 24);
        // Applied after the fit: setting it up front makes the tilted fit throw inside MapLibre.
        map.setMaxBounds(padBounds(campusBounds, 0.5));
    });

    let selectedId = null;
    const select = (feature) => {
        if (selectedId !== null) {
            map.setFeatureState({ source: 'buildings', id: selectedId }, { selected: false });
        }
        selectedId = feature?.id ?? null;
        if (selectedId !== null) {
            map.setFeatureState({ source: 'buildings', id: selectedId }, { selected: true });
        }
        if (label) {
            const { name, floors } = feature?.properties ?? {};
            label.textContent = feature ? `${name} · ${floors} ${floors === 1 ? 'floor' : 'floors'}` : '';
            label.hidden = !feature;
        }
    };

    map.on('click', 'buildings-3d', (e) => select(e.features[0]));
    map.on('click', (e) => {
        if (!map.queryRenderedFeatures(e.point, { layers: ['buildings-3d'] }).length) select(null);
    });
    map.on('mouseenter', 'buildings-3d', () => (map.getCanvas().style.cursor = 'pointer'));
    map.on('mouseleave', 'buildings-3d', () => (map.getCanvas().style.cursor = ''));
}

document.querySelectorAll('[data-campus-map]').forEach((el) => {
    initCampusMap(el).catch((error) => {
        console.error('Campus map failed to load', error);
        el.closest('[data-campus-map-card]')?.setAttribute('data-failed', '');
    });
});
