// 360° photo points ("street view"): the logic shared by the maps, the viewer and the admin editor, without the DOM or
// the viewer library, so it can be tested in Node.
//
// Directions are compass bearings in degrees (0 = north, clockwise). A photo's `northOffset` is the direction in the
// photo that faces north, in degrees clockwise from the photo's centre column (null until an admin sets it).

const EARTH_RADIUS = 6371000;
const RADIANS = Math.PI / 180;

export const normalize = (degrees) => ((degrees % 360) + 360) % 360;

// The smallest angle between two directions, 0–180.
export const angleBetween = (a, b) => {
    const d = Math.abs(normalize(a) - normalize(b));
    return d > 180 ? 360 - d : d;
};

// [lng, lat] → meters east and north of `origin` (flat earth: fine across a campus).
const toMeters = ([lng, lat], [originLng, originLat]) => [
    (lng - originLng) * RADIANS * EARTH_RADIUS * Math.cos(originLat * RADIANS),
    (lat - originLat) * RADIANS * EARTH_RADIUS,
];

export function distanceBetween(a, b) {
    const [x, y] = toMeters(b, a);
    return Math.hypot(x, y);
}

export function bearingBetween(a, b) {
    const [x, y] = toMeters(b, a);
    return normalize(Math.atan2(x, y) / RADIANS);
}

// The point `meters` from `coord` in direction `bearing`.
export function offset([lng, lat], bearing, meters) {
    const east = Math.sin(bearing * RADIANS) * meters;
    const north = Math.cos(bearing * RADIANS) * meters;
    return [lng + east / (RADIANS * EARTH_RADIUS * Math.cos(lat * RADIANS)), lat + north / (RADIANS * EARTH_RADIUS)];
}

// North for a photo, from the admin's "this spot is on the line": the photo is turned to `imageYaw` (degrees right
// of its centre) and the spot clicked on the map is in direction `bearing` from the photo point.
export const northOffsetFrom = (imageYaw, bearing) => normalize(imageYaw - bearing);

// The spot clicked on the map to set north from must be at least this far from the photo point: closer, a small
// click or GPS error turns into a large error in north.
export const MIN_SPOT_METERS = 10;
export const SPOT_TOO_CLOSE = 'Pick a spot farther away, like a building or the gate.';

// The one reference spot for setting north. Each click replaces the last one; a click too close to the pin is
// refused and leaves no spot. check(pin) drops the spot when the pin has been moved too close to it.
export function createNorthSpot() {
    let spot = null;
    return {
        get spot() {
            return spot;
        },
        // → { ok, distance, message }; message only when it was refused.
        pick(pin, coord) {
            const distance = distanceBetween(pin, coord);
            if (distance < MIN_SPOT_METERS) {
                spot = null;
                return { ok: false, distance, message: SPOT_TOO_CLOSE };
            }
            spot = coord;
            return { ok: true, distance, message: null };
        },
        distance: (pin) => (spot ? distanceBetween(pin, spot) : null),
        check(pin) {
            if (spot && distanceBetween(pin, spot) < MIN_SPOT_METERS) spot = null;
            return spot !== null;
        },
        clear() {
            spot = null;
        },
    };
}

// Which way someone looks (a compass bearing) when the photo is turned to `imageYaw`.
export const viewBearing = (northOffset, imageYaw) => normalize(imageYaw - northOffset);

// The compass heading of the photo's centre (the viewer's "pose heading"): with it the viewer's directions are
// compass bearings, so its arrows can be placed from the photo points' positions.
export const poseHeading = (northOffset) => normalize(-northOffset);

// The route arrow in the viewer: a flat shape on the ground ahead (from 2 m to 4 m out, 1.2 m wide, seen from 1.6 m
// up), pointing `bearing`. Its four corners for the viewer's image layer, far-left first, in radians; the yaws may go
// below 0 or past 2π so the shape never wraps apart.
const EYE_HEIGHT = 1.6;
const ARROW_NEAR = 2;
const ARROW_FAR = 4;
const ARROW_HALF_WIDTH = 0.6;
export function groundArrow(bearing) {
    const centre = bearing * RADIANS;
    const corner = (distance, side) => ({ yaw: centre + side * Math.atan(ARROW_HALF_WIDTH / distance), pitch: -Math.atan(EYE_HEIGHT / distance) });
    return [corner(ARROW_FAR, -1), corner(ARROW_FAR, 1), corner(ARROW_NEAR, 1), corner(ARROW_NEAR, -1)];
}

// GET /api/map's `panoramas` FeatureCollection → plain photo points.
export function panoramasFrom(collection) {
    return (collection?.features ?? []).map((feature) => ({
        id: feature.properties.id,
        name: feature.properties.name,
        floor: feature.properties.floor ?? 1,
        northOffset: feature.properties.north_offset ?? null,
        image: feature.properties.image,
        thumb: feature.properties.thumb,
        coord: feature.geometry.coordinates,
    }));
}

export const escapeHtml = (text) =>
    String(text).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

// The photo points as virtual-tour nodes for the viewer (GPS mode: arrows placed from the positions; the pose heading
// turns each photo so its directions are compass bearings). `links` is photo-links.js's walkLinks result. Each arrow
// keeps its label and bearing in its data, and the photo it leaves from. The viewer shows names as HTML, so they are escaped.
export function tourNodes(points, links = new Map()) {
    return points.map((p) => ({
        id: p.id,
        panorama: p.image,
        name: escapeHtml(p.name),
        caption: escapeHtml(p.name),
        thumbnail: p.thumb,
        gps: p.coord,
        panoData: { poseHeading: poseHeading(p.northOffset ?? 0) },
        links: (links.get(p.id) ?? []).map((link) => ({ nodeId: link.id, gps: link.gps, data: { label: link.label, bearing: link.bearing, from: p.id } })),
    }));
}

// The viewer around one tour: open(id) shows the overlay and starts the viewer at that photo, turned to `yaw` (a
// compass bearing); moving between photos happens inside the viewer; close() hides the overlay and frees the viewer
// (it holds a large image on the GPU). `load(container, { nodes, startId, yaw, onNode, arrowFor })` starts the viewer
// library and resolves to { show(id, yaw), destroy() }. `ui` is the overlay: { container, show(point, guide), hide(),
// fail() }.
//
// Opened from a route, `guide(id)` gives { bearing, text, caption, nextId } for each photo on it (null for the
// others): the photo then opens facing `bearing`, with the arrow on the ground that way (arrowFor) and the text on
// top, and next() steps to the next photo on the route.
export function createViewerSession({ points, links = new Map(), load, ui }) {
    const nodes = tourNodes(points, links);
    const byId = new Map(points.map((p) => [p.id, p]));
    let viewer = null;
    let opening = null;
    let openId = null;
    let guide = null;
    // The viewer being loaded or shown (a token per load), so events from a closed one are ignored.
    let current = null;
    const guideFor = (id) => guide?.(id) ?? null;
    const showUi = (id) => ui.show(byId.get(id), guideFor(id));

    const session = {
        get openId() {
            return openId;
        },
        async open(id, { yaw = 0, guide: routeGuide = null } = {}) {
            if (!byId.has(id)) return false;
            openId = id;
            guide = routeGuide;
            const facing = guideFor(id)?.bearing ?? yaw;
            showUi(id);
            if (viewer) {
                await viewer.show(id, facing);
                return true;
            }
            // A viewer that was closed can still finish moving to a photo; only the current one updates the overlay.
            const loading = {};
            current = loading;
            const onNode = (nodeId) => {
                if (current !== loading || !byId.has(nodeId)) return;
                openId = nodeId;
                showUi(nodeId);
            };
            const arrowFor = (nodeId) => guideFor(nodeId)?.bearing ?? null;
            const started = (opening ??= load(ui.container, { nodes, startId: id, yaw: facing, onNode, arrowFor }));
            try {
                const loaded = await started;
                if (opening !== started) return false; // closed while loading
                viewer = loaded;
                return true;
            } catch (error) {
                console.error('360° viewer failed to load', error);
                if (opening === started) {
                    opening = null;
                    ui.fail();
                }
                return false;
            }
        },
        // "Next spot": the next photo on the route, facing the way the route goes there. False when there is none.
        async next() {
            const nextId = openId === null ? null : guideFor(openId)?.nextId;
            if (!nextId) return false;
            return session.open(nextId, { guide });
        },
        close() {
            openId = null;
            guide = null;
            current = null;
            ui.hide();
            const pending = opening;
            opening = null;
            if (viewer) {
                viewer.destroy();
                viewer = null;
            } else if (pending) {
                pending.then((late) => late.destroy(), () => {});
            }
        },
    };
    return session;
}
