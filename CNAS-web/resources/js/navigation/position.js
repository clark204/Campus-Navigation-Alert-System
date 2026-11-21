import { projectOnSegment } from './geo.js';
import { attachPoint, nearestOnEdges, walkOnFloor } from './graph.js';
import { findRoute } from './router.js';

const RAD = Math.PI / 180;
const METERS_PER_DEGREE = 111320;
// The dot leaves the walkway it is on for one that doesn't touch it only when that one is this much closer,
// so it doesn't flicker between two walkways that run side by side.
const SWITCH_METERS = 2;
// Two spots on a route this equally far from the dot count as the same place (the route passes it twice).
const SAME_SPOT_METERS = 0.1;


// The point `distance` meters from `coord` in compass direction `heading` (flat projection, fine at campus scale).
export function movePoint([lng, lat], heading, distance) {
    return [
        lng + (distance * Math.sin(heading * RAD)) / (METERS_PER_DEGREE * Math.cos(lat * RAD)),
        lat + (distance * Math.cos(heading * RAD)) / METERS_PER_DEGREE,
    ];
}

/*
 * Closest point on the ground-floor walkways (plus the edges in `alsoEdges`: a route's own door and stair links).
 * `currentEdge` is the edge the dot is on now. Returns { point, edgeId, distance } or null without walkways.
 */
export function snapToWalkway(graph, coord, currentEdge = null, alsoEdges = null) {
    const ground = walkOnFloor(graph, 1);
    const allowed = (edge) => ground(edge) || alsoEdges?.has(edge.id) === true;
    const best = nearestOnEdges(graph, coord, allowed);
    if (!best) return null;

    const current = graph.edges.get(currentEdge);
    if (current && current.id !== best.edge.id) {
        const touches = [best.edge.a, best.edge.b].some((id) => id === current.a || id === current.b);
        if (!touches) {
            const stay = projectOnSegment(coord, graph.nodes.get(current.a).coord, graph.nodes.get(current.b).coord);
            if (stay.distance <= best.distance + SWITCH_METERS) return { point: stay.point, edgeId: current.id, distance: stay.distance };
        }
    }
    return { point: best.point, edgeId: best.edge.id, distance: best.distance };
}

/*
 * Where `coord` is on a route: { along, distance } for the closest point of the route line between `from` and
 * `to` meters. Among spots about equally close (a route can pass the same place twice), the one nearest to
 * `near` meters along wins.
 */
export function projectOnRoute(route, coord, { from = 0, to = route.length, near = from } = {}) {
    const { nodes, along } = route;
    let best = null;
    for (let i = 1; i < nodes.length; i++) {
        if (along[i] === along[i - 1] || along[i] < from || along[i - 1] > to) continue;
        const hit = projectOnSegment(coord, nodes[i - 1].coord, nodes[i].coord);
        const at = Math.max(from, Math.min(to, along[i - 1] + hit.t * (along[i] - along[i - 1])));
        const closer = !best || hit.distance < best.distance - SAME_SPOT_METERS;
        const sameButNearer =
            best && Math.abs(hit.distance - best.distance) <= SAME_SPOT_METERS && Math.abs(at - near) < Math.abs(best.along - near);
        if (closer || sameButNearer) best = { along: at, distance: hit.distance };
    }
    return best ?? { along: near, distance: 0 };
}

// A new route from wherever the dot is (any point on a walkway) to `to`: a node id or a Set of them.
// `options` as for findRoute (blocked walkways). null when there is no way.
export function rerouteFrom(graph, coord, to, options) {
    const start = attachPoint(graph, coord);
    return start ? findRoute(graph, start.nodeId, to, options) : null;
}
