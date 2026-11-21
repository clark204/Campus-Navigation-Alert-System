// 360° photos along a route ("See this spot"): which photo points are on the route, which way each one opens, when
// one is offered while walking, and what the viewer says there. Only reads the route and its maneuvers (router.js,
// instructions.js) and the tracker's progress; nothing here changes them. No DOM, so it can be tested in Node.
//
// Directions come only from the route and each photo's north, never from the compass or GPS heading.
import { bearingDeg, projectOnSegment } from './geo.js';
import { ordinal } from './instructions.js';

// A photo this close to the route line (on the same floor) is on the route.
export const ROUTE_PHOTO_METERS = 8;
// While walking, a photo is offered from this far before it…
export const OFFER_BEFORE_METERS = 30;
// …until this far past it.
export const OFFER_AFTER_METERS = 5;
// The photo opens facing the route this far ahead of it…
export const LOOK_AHEAD_METERS = 10;
// …or, with a turn or stairs this close ahead, the way the route goes after the turn (or toward the stairs).
export const TURN_AHEAD_METERS = 12;
// The last photo before the stairs or the destination says so, when it is at most this far before them.
export const LAST_SPOT_METERS = 40;

// The point `at` meters along the route.
function pointAt({ nodes, along }, at) {
    const i = along.findIndex((value) => value >= at);
    if (i <= 0) return nodes[i === -1 ? nodes.length - 1 : 0].coord;
    const [a, b] = [nodes[i - 1].coord, nodes[i].coord];
    const t = along[i] === along[i - 1] ? 1 : (at - along[i - 1]) / (along[i] - along[i - 1]);
    return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
}

const chord = (route, from, to) => bearingDeg(pointAt(route, from), pointAt(route, to));

/*
 * The compass bearing a photo `along` meters into the route opens to: the route LOOK_AHEAD_METERS ahead; with a
 * turn within TURN_AHEAD_METERS ahead, the direction after the turn (so the photo shows which way to turn); with
 * stairs that close, toward the stairs.
 */
export function routeDirection(route, maneuvers, along) {
    const soon = maneuvers.find((m) => (m.type === 'turn' || m.type === 'stairs') && m.along >= along - 0.5 && m.along - along <= TURN_AHEAD_METERS);
    if (soon?.type === 'turn') return soon.bearingAfter ?? chord(route, soon.along, Math.min(route.length, soon.along + LOOK_AHEAD_METERS));
    if (soon?.type === 'stairs') {
        return soon.along - along > 1 ? chord(route, along, soon.along) : chord(route, Math.max(0, soon.along - LOOK_AHEAD_METERS), soon.along);
    }
    if (route.length - along < 1) return chord(route, Math.max(0, route.length - LOOK_AHEAD_METERS), route.length);
    return chord(route, along, Math.min(route.length, along + LOOK_AHEAD_METERS));
}

// Where `coord` is on the parts of the route on `floor`: { along, distance }, or null when the route is not on it.
function projectOnFloor({ nodes, along }, coord, floor) {
    let best = null;
    for (let i = 1; i < nodes.length; i++) {
        if (along[i] === along[i - 1] || nodes[i - 1].floor !== floor || nodes[i].floor !== floor) continue;
        const hit = projectOnSegment(coord, nodes[i - 1].coord, nodes[i].coord);
        if (!best || hit.distance < best.distance) best = { along: along[i - 1] + hit.t * (along[i] - along[i - 1]), distance: hit.distance };
    }
    return best;
}

function skipReason(point, hit) {
    const visible = point.isVisible !== false;
    const north = point.northOffset !== null && point.northOffset !== undefined;
    if (!visible) return north ? 'hidden' : 'hidden, no north';
    if (!north) return 'no north';
    if (!hit) return `the route is not on floor ${point.floor}`;
    if (hit.distance > ROUTE_PHOTO_METERS) return `too far from the route (${Math.round(hit.distance)} m)`;
    return null;
}

// What the last photo before the stairs or the destination says ("Take these stairs up to the 2nd floor").
function lastSpotCaption(maneuver, destinationName) {
    if (maneuver.type === 'stairs') return `Take these stairs ${maneuver.modifier} to the ${ordinal(maneuver.toFloor)} floor`;
    if (maneuver.modifier === 'on your right' || maneuver.modifier === 'on your left') return `${destinationName} is ahead ${maneuver.modifier}`;
    if (maneuver.modifier === 'straight ahead') return `${destinationName} is straight ahead`;
    return `${destinationName} is ahead`;
}

/*
 * The photo points on a route: { checked, spots, skipped }. `spots` are in route order:
 *   { id, point, along, distance, bearing, text, caption }
 * with `bearing` the direction the photo opens to, `text` the instruction that comes next after the photo, and
 * `caption` set on the last photo before the stairs or the destination. `skipped` is [{ id, reason }].
 * `points` are photo points (panoramas.js), with `isVisible` false for one hidden from users (admin debug list).
 */
export function routeSpots(route, points, { maneuvers = [], destinationName = 'your destination' } = {}) {
    const spots = [];
    const skipped = [];
    for (const point of points) {
        const visible = point.isVisible !== false && point.northOffset !== null && point.northOffset !== undefined;
        const hit = visible ? projectOnFloor(route, point.coord, point.floor ?? 1) : null;
        const reason = skipReason(point, hit);
        if (reason) {
            skipped.push({ id: point.id, reason });
            continue;
        }
        const next = maneuvers.find((m) => m.type !== 'depart' && m.along > hit.along + 0.5) ?? maneuvers.at(-1);
        spots.push({
            id: point.id,
            point,
            along: hit.along,
            distance: hit.distance,
            bearing: routeDirection(route, maneuvers, hit.along),
            text: next?.text ?? null,
            caption: null,
        });
    }
    spots.sort((a, b) => a.along - b.along);

    // Each stairs and the arrival: the last photo before it (and after the one before) says what is there.
    let from = -Infinity;
    for (const m of maneuvers.filter((m) => m.type === 'stairs' || m.type === 'arrive')) {
        const last = spots.filter((s) => s.along > from && s.along <= m.along + 0.5).at(-1);
        if (last && m.along - last.along <= LAST_SPOT_METERS) last.caption = lastSpotCaption(m, destinationName);
        from = m.along + 0.5;
    }
    return { checked: points.length, spots, skipped };
}

/*
 * The photo to offer as "See this spot", or null. While walking (GPS), the next photo from OFFER_BEFORE_METERS before
 * it to OFFER_AFTER_METERS past it. In manual mode (the "Next" button) progress jumps from step to step, so it is
 * the next photo on the current step, up to the maneuver it leads to (`next`).
 */
export function offeredSpot(spots, { progress, next, manual = false }) {
    const from = manual ? progress - 1 : progress - OFFER_AFTER_METERS;
    const to = manual ? (next?.along ?? Infinity) + 0.5 : progress + OFFER_BEFORE_METERS;
    return spots.find((s) => s.along >= from && s.along <= to) ?? null;
}

/*
 * The photos of the route being previewed or walked. setRoute() is called for each new route (a reroute too), so
 * the photos on it are worked out again; setRoute(null) clears them.
 */
export function createRouteGuide(points) {
    let result = { checked: points.length, spots: [], skipped: [], noRoute: true };
    return {
        setRoute(route, maneuvers, destinationName) {
            result = route ? routeSpots(route, points, { maneuvers, destinationName }) : { checked: points.length, spots: [], skipped: [], noRoute: true };
        },
        get result() {
            return result;
        },
        get spots() {
            return result.spots;
        },
        spot: (id) => result.spots.find((s) => s.id === id) ?? null,
        nextAfter(id) {
            const i = result.spots.findIndex((s) => s.id === id);
            return i === -1 ? null : (result.spots[i + 1] ?? null);
        },
        offer: (state) => offeredSpot(result.spots, state),
    };
}

// The debug panel's lines for a route guide's result.
export function debugLines({ checked, spots, skipped, noRoute = false }) {
    if (!checked) return ['no photos'];
    if (noRoute) return [`${checked} photo${checked === 1 ? '' : 's'}, no route yet`];
    const lines = [...spots.map((s) => `${s.id} at ${Math.round(s.along)} m`), ...skipped.map((s) => `skipped ${s.id}: ${s.reason}`)];
    if (!spots.length) lines.unshift(checked === 1 ? 'The only photo was skipped, so none are on this route.' : `All ${checked} photos were skipped, so none are on this route.`);
    return lines;
}

