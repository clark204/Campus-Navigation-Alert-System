// The arrows between 360° photo points, found by walking the walkways (not by straight-line distance). From each photo,
// every way the walkway leaves it is followed to the first visible photo reached, within WALK_LINK_METERS. A junction
// on the way gives each branch its own candidate. At most MAX_WALK_LINKS are kept, nearest along the path first, and a
// candidate in nearly the same direction as a kept one (SAME_DIRECTION_DEGREES) is dropped. Each arrow leaves along the
// walkway: it points ARROW_AHEAD_METERS past the point where its walk parts from the other candidates.
//
// Pure: it reads the walkway graph (navigation/graph.js) and never changes it. Without a graph (or when a photo has no
// walkway link, e.g. its pin is far from any path) the photo falls back to its single nearest photo on its floor.
import { meters, sameCoord } from './navigation/geo.js';
import { nearestOnEdges, otherEnd, walkOnFloor } from './navigation/graph.js';
import { angleBetween, bearingBetween, distanceBetween } from './panoramas.js';

// A photo this close to a walkway on its floor is on it; farther, its links fall back to the nearest photo.
export const SNAP_PHOTO_METERS = 10;
// How far a link's walk may go along the walkways.
export const WALK_LINK_METERS = 40;
export const MAX_WALK_LINKS = 3;
// Two arrows this close in direction (degrees, seen from the photo) are the same way; the nearer one is kept.
export const SAME_DIRECTION_DEGREES = 45;
// An arrow points this far along the walkway past where its walk parts from the other candidates.
export const ARROW_AHEAD_METERS = 25;
// A link arrow this close to the route arrow (degrees) is hidden while a route is shown.
export const ROUTE_TWIN_DEGREES = 30;

// Where a photo stands on the walkways: { point, edge, t, length, at, floor }, or nothing when it is more than
// `snapMeters` from them.
export function snapPhotos(graph, points, snapMeters = SNAP_PHOTO_METERS) {
    const snaps = new Map();
    for (const point of points) {
        const floor = point.floor ?? 1;
        const hit = nearestOnEdges(graph, point.coord, walkOnFloor(graph, floor));
        if (!hit || hit.distance > snapMeters) continue;
        const [a, b] = [graph.nodes.get(hit.edge.a).coord, graph.nodes.get(hit.edge.b).coord];
        snaps.set(point.id, { point, edge: hit.edge, t: hit.t, length: meters(a, b), at: hit.point, floor });
    }
    return snaps;
}

// Meters from a snapped photo to one end of its edge.
const metersTo = (snap, nodeId) => (nodeId === snap.edge.a ? snap.t : 1 - snap.t) * snap.length;

// The point `distance` meters along a trail (a list of [lng, lat]) from the trail's point number `index`.
function pointAlong(trail, index, distance) {
    let left = distance;
    for (let i = index; i < trail.length - 1; i++) {
        const [a, b] = [trail[i], trail[i + 1]];
        const step = meters(a, b);
        if (step >= left) {
            const t = step ? left / step : 0;
            return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
        }
        left -= step;
    }
    return trail.at(-1);
}

// Every first photo reached from one photo, each way out along the walkways (a junction gives each branch its own).
// A candidate is { photo, walk, trail, edges }: the walk's points (trail) and the edges between them (edges[i] joins
// trail[i] and trail[i + 1]). No walk is longer than `limit` meters.
function candidatesFrom(graph, snap, byEdge, limit) {
    const found = [];
    const onFloor = walkOnFloor(graph, snap.floor);
    const others = (edgeId) => (byEdge.get(edgeId) ?? []).filter((other) => other.point.id !== snap.point.id);

    const explore = (nodeId, dist, trail, edges, visited) => {
        for (const edgeId of graph.adjacency.get(nodeId) ?? []) {
            const edge = graph.edges.get(edgeId);
            if (edge.id === snap.edge.id || !onFloor(edge)) continue;
            const next = otherEnd(edge, nodeId);
            if (visited.has(next)) continue;
            const length = meters(graph.nodes.get(nodeId).coord, graph.nodes.get(next).coord);
            // The first photo on this edge, from the node the walk is at, ends this branch.
            const first = others(edge.id)
                .map((other) => ({ other, along: metersTo(other, nodeId) }))
                .filter(({ along }) => along >= 0 && dist + along <= limit)
                .sort((x, y) => x.along - y.along)[0];
            if (first) {
                found.push({ photo: first.other, walk: dist + first.along, trail: [...trail, first.other.at], edges: [...edges, edge] });
                continue;
            }
            if (dist + length > limit) continue;
            explore(next, dist + length, [...trail, graph.nodes.get(next).coord], [...edges, edge], new Set([...visited, next]));
        }
    };

    // Both ways out of the photo: a photo ahead on its own walkway first, else on from the walkway's end.
    for (const startId of [snap.edge.a, snap.edge.b]) {
        const ahead = others(snap.edge.id)
            .filter((other) => (startId === snap.edge.b ? other.t > snap.t : other.t < snap.t))
            .map((other) => ({ other, walk: Math.abs(other.t - snap.t) * snap.length }))
            .filter(({ walk }) => walk <= limit)
            .sort((x, y) => x.walk - y.walk)[0];
        if (ahead) {
            found.push({ photo: ahead.other, walk: ahead.walk, trail: [snap.at, ahead.other.at], edges: [snap.edge] });
            continue;
        }
        explore(startId, metersTo(snap, startId), [snap.at, graph.nodes.get(startId).coord], [snap.edge], new Set([startId]));
    }

    // One candidate per photo: its shortest walk.
    const best = new Map();
    for (const candidate of found) {
        const seen = best.get(candidate.photo.point.id);
        if (!seen || candidate.walk < seen.walk) best.set(candidate.photo.point.id, candidate);
    }
    return [...best.values()];
}

// The walks from every snapped photo (snapPhotos) to the first photos reached each way along the walkways, none
// longer than `limit` meters: Map(id → [{ photo, walk, trail, edges }]).
export function photoWalks(graph, snaps, limit = WALK_LINK_METERS) {
    const byEdge = new Map();
    for (const snap of snaps.values()) {
        if (!byEdge.has(snap.edge.id)) byEdge.set(snap.edge.id, []);
        byEdge.get(snap.edge.id).push(snap);
    }
    return new Map([...snaps].map(([id, snap]) => [id, candidatesFrom(graph, snap, byEdge, limit)]));
}

// Where a candidate's arrow points: ARROW_AHEAD_METERS past the last point it shares with another candidate's walk.
// `from` is the index of that shared point; the walk leaves it on edges[from].
function arrowFrom(candidate, all) {
    let shared = 1;
    for (const other of all) {
        if (other === candidate) continue;
        let i = 0;
        while (i < candidate.trail.length && i < other.trail.length && sameCoord(candidate.trail[i], other.trail[i])) i++;
        shared = Math.max(shared, i);
    }
    const from = shared - 1;
    return { target: pointAlong(candidate.trail, from, ARROW_AHEAD_METERS), from };
}

// "Toward the gate" when a named landmark is on the walkway the arrow leaves along; else the photo's name.
function labelFor(candidate, from, landmarks) {
    const edge = candidate.edges[from] ?? candidate.edges.at(-1);
    const landmark = landmarks?.paths?.[edge?.path];
    return landmark ? `Toward ${landmark}` : candidate.photo.point.name;
}

// The links of one photo: its walk candidates, nearest first, kept by the rules above.
function linksFor(snap, all, landmarks) {
    const prepared = all.map((candidate) => {
        const { target, from } = arrowFrom(candidate, all);
        return { ...candidate, target, from, bearing: bearingBetween(snap.point.coord, target) };
    });
    const kept = [];
    for (const candidate of prepared.sort((x, y) => x.walk - y.walk)) {
        if (kept.length >= MAX_WALK_LINKS) break;
        if (kept.some((k) => angleBetween(k.bearing, candidate.bearing) < SAME_DIRECTION_DEGREES)) continue;
        kept.push(candidate);
    }
    return kept.map((candidate) => ({
        id: candidate.photo.point.id,
        bearing: candidate.bearing,
        distance: candidate.walk,
        gps: candidate.target,
        label: labelFor(candidate, candidate.from, landmarks),
        fallback: false,
    }));
}

// The one nearest photo on the same floor, for a photo with no walkway link.
function fallbackLinks(point, points) {
    const floor = point.floor ?? 1;
    const nearest = points
        .filter((other) => other.id !== point.id && (other.floor ?? 1) === floor)
        .map((other) => ({ other, distance: distanceBetween(point.coord, other.coord) }))
        .sort((x, y) => x.distance - y.distance)[0];
    if (!nearest) return [];
    return [{ id: nearest.other.id, bearing: bearingBetween(point.coord, nearest.other.coord), distance: nearest.distance, gps: nearest.other.coord, label: nearest.other.name, fallback: true }];
}

/*
 * The links of every photo point: Map(id → [{ id, bearing, distance, gps, label, fallback }]). `bearing` is the direction
 * of the arrow from the photo, `gps` is the spot on the walkway the arrow points to, and `distance` the walk (or, for
 * a fallback, the straight line). `landmarks` ({ paths }) names the walkways for labels.
 */
export function walkLinks(graph, points, { landmarks = null } = {}) {
    const snaps = graph ? snapPhotos(graph, points) : new Map();
    const walks = photoWalks(graph, snaps);
    return new Map(
        points.map((point) => {
            const snap = snaps.get(point.id);
            const links = snap ? linksFor(snap, walks.get(point.id), landmarks) : [];
            return [point.id, links.length ? links : fallbackLinks(point, points)];
        }),
    );
}

// Whether a link arrow points the same way as the route arrow (it is hidden then, so there is only one arrow).
export const routeTwin = (linkBearing, routeBearing) => angleBetween(linkBearing, routeBearing) < ROUTE_TWIN_DEGREES;
