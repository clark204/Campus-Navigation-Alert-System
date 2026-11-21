import { distanceToRing, meters, projectOnSegment } from './geo.js';
import { addEdge, addNode } from './graph.js';
import { findRoute } from './router.js';

// Walkway points this close to the evacuation area get a short link onto it (the walkways stop at its edge).
export const EVACUATION_REACH_METERS = 15;
// Leaving a danger zone you are already in: every meter inside it costs this much more than a meter outside, so the
// route gets out by the shortest stretch through the danger and then stays out.
export const DANGER_PENALTY = 1000;
export const NO_SAFE_ROUTE = 'No safe route found. Follow staff instructions and move away from the danger area.';

const RAD = Math.PI / 180;
const METERS_PER_DEGREE = 111320;

// Ray casting; ring is a closed GeoJSON ring of [lng, lat].
export function pointInRing([x, y], ring) {
    let inside = false;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        const [xi, yi] = ring[i];
        const [xj, yj] = ring[j];
        if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inside = !inside;
    }
    return inside;
}

const orientation = (p, q, r) => Math.sign((q[1] - p[1]) * (r[0] - q[0]) - (q[0] - p[0]) * (r[1] - q[1]));

function segmentsCross(a, b, c, d) {
    return orientation(a, b, c) !== orientation(a, b, d) && orientation(c, d, a) !== orientation(c, d, b);
}

// Whether segment a-b is inside the ring or crosses its outline.
export function segmentTouchesRing(a, b, ring) {
    if (pointInRing(a, ring) || pointInRing(b, ring)) return true;
    for (let i = 1; i < ring.length; i++) {
        if (segmentsCross(a, b, ring[i - 1], ring[i])) return true;
    }
    return false;
}

/*
 * The danger of an active alert: { center: [lng, lat] | null, radius: meters, rings: affected building outlines }.
 * null when there is no active alert. `alert` is the api/alerts/active payload; `buildings` is buildings.geojson.
 */
export function dangerFromAlert(alert, buildings) {
    if (!alert || alert.status !== 'active') return null;
    const codes = new Set(alert.building_codes ?? []);
    const rings = (buildings?.features ?? [])
        .filter((feature) => codes.has(feature.properties.id))
        .map((feature) => feature.geometry.coordinates[0]);
    const hasPin = alert.latitude != null && alert.longitude != null;
    return {
        center: hasPin ? [alert.longitude, alert.latitude] : null,
        radius: hasPin ? (alert.danger_radius_m ?? 50) : 0,
        rings,
        // Affected building codes: their upper floors are in danger as a whole (see blockedEdges).
        buildingIds: new Set((buildings?.features ?? []).filter((feature) => codes.has(feature.properties.id)).map((feature) => feature.properties.id)),
    };
}

/*
 * Edge ids inside affected buildings above the ground floor: their stairs and every upstairs corridor, door link and
 * room reached from those stairs. Corridors are often drawn along the building's edge, so the outline alone would
 * call them safe, and a route down would then go "back into" the danger at the stairs.
 */
function indoorEdges(graph, buildingIds) {
    const edges = new Set();
    const queue = [...graph.nodes.values()].filter((node) => node.kind === 'stair' && buildingIds.has(node.buildingId)).map((node) => node.id);
    const seen = new Set(queue);
    while (queue.length) {
        const id = queue.shift();
        for (const edgeId of graph.adjacency.get(id)) {
            const edge = graph.edges.get(edgeId);
            const [a, b] = [graph.nodes.get(edge.a), graph.nodes.get(edge.b)];
            if (edge.type !== 'stairs' && (a.floor === 1 || b.floor === 1)) continue;
            edges.add(edge.id);
            const next = edge.a === id ? edge.b : edge.a;
            if (!seen.has(next)) {
                seen.add(next);
                queue.push(next);
            }
        }
    }
    return edges;
}

export const hasDangerArea = (danger) => !!danger && (danger.center !== null || danger.rings.length > 0);

// Whether walking from a to b passes through the danger circle or an affected building.
export function isSegmentDangerous(a, b, danger) {
    if (!danger) return false;
    if (danger.center && projectOnSegment(danger.center, a, b).distance < danger.radius) return true;
    return danger.rings.some((ring) => segmentTouchesRing(a, b, ring));
}

export function isPointDangerous(point, danger) {
    if (!danger) return false;
    if (danger.center && meters(danger.center, point) < danger.radius) return true;
    return danger.rings.some((ring) => pointInRing(point, ring));
}

// Ids of every edge inside or crossing the danger zone or an affected building, including an affected building's
// upper floors.
export function blockedEdges(graph, danger) {
    const blocked = new Set();
    if (!hasDangerArea(danger)) return blocked;
    for (const edge of graph.edges.values()) {
        if (isSegmentDangerous(graph.nodes.get(edge.a).coord, graph.nodes.get(edge.b).coord, danger)) blocked.add(edge.id);
    }
    if (danger.buildingIds?.size) indoorEdges(graph, danger.buildingIds).forEach((id) => blocked.add(id));
    return blocked;
}

// Closest point on a ring's outline to `point`.
function nearestOnRing(point, ring) {
    let best = null;
    for (let i = 1; i < ring.length; i++) {
        const hit = projectOnSegment(point, ring[i - 1], ring[i]);
        if (!best || hit.distance < best.distance) best = hit;
    }
    return best.point;
}

/*
 * Adds the evacuation area to the graph once: a node on its edge for every nearby ground-floor walkway point, linked
 * to that point. Returns the Set of those node ids (the possible ends of a route to safety).
 */
export function addEvacuationTargets(graph, evacuationRing, reach = EVACUATION_REACH_METERS) {
    if (graph.evacuationTargets) return graph.evacuationTargets;
    const walkway = [...graph.nodes.values()].filter((node) => node.kind === 'path' && node.floor === 1);
    const distances = walkway.map((node) => ({ node, d: distanceToRing(node.coord, evacuationRing) }));
    // If no walkway comes within `reach`, use the closest ones anyway.
    const nearest = Math.min(...distances.map(({ d }) => d));
    const limit = Math.max(reach, nearest + 5);

    const targets = new Set();
    for (const { node, d } of distances) {
        if (d > limit) continue;
        const id = `evac:${node.id}`;
        const onEdge = pointInRing(node.coord, evacuationRing) ? node.coord : nearestOnRing(node.coord, evacuationRing);
        addNode(graph, { id, coord: onEdge, kind: 'path', evacuation: true });
        addEdge(graph, node.id, id, { type: 'link' });
        targets.add(id);
    }
    graph.evacuationTargets = targets;
    return targets;
}

/*
 * Route from `startId` to the nearest safe point of the evacuation area, never using a blocked edge.
 * If the start itself is in danger (e.g. inside the burning building), the route leaves the danger by the shortest
 * stretch through it and must then stay out. Returns { route, startsInDanger } or null when no safe route exists.
 */
export function findEvacuationRoute(graph, startId, { danger, targets, blocked = blockedEdges(graph, danger) }) {
    const start = graph.nodes.get(startId);
    if (!start) return null;
    const safeTargets = new Set([...targets].filter((id) => !isPointDangerous(graph.nodes.get(id).coord, danger)));
    if (!safeTargets.size) return null;

    const touching = [...graph.adjacency.get(startId)];
    const startsInDanger =
        isPointDangerous(start.coord, danger) || (touching.length > 0 && touching.every((id) => blocked.has(id)));

    if (!startsInDanger) {
        const route = findRoute(graph, startId, safeTargets, { cost: (edge) => (blocked.has(edge.id) ? Infinity : edge.weight) });
        return route ? { route, startsInDanger } : null;
    }

    const route = findRoute(graph, startId, safeTargets, {
        cost: (edge) => (blocked.has(edge.id) ? edge.weight * DANGER_PENALTY : edge.weight),
    });
    if (!route) return null;
    // Out of the danger once, then never back in.
    const firstSafe = route.edges.findIndex((edge) => !blocked.has(edge.id));
    if (firstSafe === -1 || route.edges.slice(firstSafe).some((edge) => blocked.has(edge.id))) return null;
    return { route, startsInDanger };
}

// GeoJSON polygon approximating the danger circle, for the map.
export function circlePolygon([lng, lat], radiusMeters, steps = 64) {
    const dLat = radiusMeters / METERS_PER_DEGREE;
    const dLng = radiusMeters / (METERS_PER_DEGREE * Math.cos(lat * RAD));
    const ring = [];
    for (let i = 0; i <= steps; i++) {
        const angle = (i / steps) * 2 * Math.PI;
        ring.push([lng + dLng * Math.cos(angle), lat + dLat * Math.sin(angle)]);
    }
    return { type: 'Feature', properties: {}, geometry: { type: 'Polygon', coordinates: [ring] } };
}
