import { METERS_PER_FLOOR } from '../map-style.js';
import { meters, projectOnSegment, sameCoord } from './geo.js';

// Path ends closer than this to another path are treated as connected.
export const SNAP_METERS = 2;
// When a projection lands this close to an existing vertex, reuse the vertex instead of splitting.
const VERTEX_REUSE_METERS = 0.5;
// A flight of stairs counts as one floor height, doubled for the effort.
const STAIRS_FACTOR = 2;
// Stairs link to the nearest path on their floor and to one more path this close (a landing often touches two),
// at most STAIR_LINKS in all, so routes cross the landing instead of going round to a single attach point.
const STAIR_LINK_METERS = 6;
const STAIR_LINKS = 2;
// Upstairs, stairs link to a corridor at most this far away, and a room to its building's corridor at most this far.
const UPPER_STAIR_LINK_METERS = 10;
const UPPER_ROOM_LINK_METERS = 15;

const WALK_TYPES = new Set(['walkway', 'gate']);
const isWalkEdge = (edge) => WALK_TYPES.has(edge.type);
const floorOf = (graph, edge) => graph.nodes.get(edge.a).floor;
// Walkways, gates and corridors on one floor (floors only meet at stairs).
export const walkOnFloor = (graph, floor) => (edge) => isWalkEdge(edge) && floorOf(graph, edge) === floor;

/*
 * Graph shape:
 *   nodes: Map id -> { id, coord: [lng, lat], floor, kind: 'path'|'room'|'stair', paths: Set, ... }
 *          (a path node's floor is its path's floor: 1 for ground walkways, 2+ for corridors)
 *   edges: Map id -> { id, a, b, weight, type: 'walkway'|'gate'|'link'|'stairs', path }
 *   adjacency: Map nodeId -> Set of edge ids
 */
export function createGraph() {
    return { nodes: new Map(), edges: new Map(), adjacency: new Map(), seq: 0 };
}

export function addNode(graph, node) {
    const full = { floor: 1, kind: 'path', paths: new Set(), ...node };
    graph.nodes.set(full.id, full);
    graph.adjacency.set(full.id, new Set());
    return full;
}

export const edgeBetween = (graph, a, b) =>
    [...graph.adjacency.get(a)].map((id) => graph.edges.get(id)).find((e) => e.a === b || e.b === b);

export const otherEnd = (edge, nodeId) => (edge.a === nodeId ? edge.b : edge.a);

export function addEdge(graph, a, b, { type, path = null, weight } = {}) {
    if (a === b) return null;
    const w = weight ?? meters(graph.nodes.get(a).coord, graph.nodes.get(b).coord);
    const existing = edgeBetween(graph, a, b);
    if (existing) {
        existing.weight = Math.min(existing.weight, w);
        return existing;
    }
    const edge = { id: `e${graph.seq++}`, a, b, weight: w, type, path };
    graph.edges.set(edge.id, edge);
    graph.adjacency.get(a).add(edge.id);
    graph.adjacency.get(b).add(edge.id);
    return edge;
}

export function removeEdge(graph, edgeId) {
    const edge = graph.edges.get(edgeId);
    graph.adjacency.get(edge.a).delete(edgeId);
    graph.adjacency.get(edge.b).delete(edgeId);
    graph.edges.delete(edgeId);
}

// Folds node `drop` into node `keep`: its edges move over and their lengths are re-measured.
export function mergeNodes(graph, keepId, dropId) {
    if (keepId === dropId) return;
    const keep = graph.nodes.get(keepId);
    const drop = graph.nodes.get(dropId);
    for (const edgeId of [...graph.adjacency.get(dropId)]) {
        const edge = graph.edges.get(edgeId);
        removeEdge(graph, edgeId);
        const other = otherEnd(edge, dropId);
        addEdge(graph, keepId, other, {
            type: edge.type,
            path: edge.path,
            weight: edge.type === 'stairs' ? edge.weight : undefined,
        });
    }
    drop.paths.forEach((p) => keep.paths.add(p));
    graph.nodes.delete(dropId);
    graph.adjacency.delete(dropId);
}

// Inserts a node at `coord` on an edge, replacing the edge with two halves.
export function splitEdge(graph, edgeId, coord) {
    const edge = graph.edges.get(edgeId);
    const node = addNode(graph, { id: `n${graph.seq++}`, coord, floor: floorOf(graph, edge), paths: new Set([edge.path]) });
    removeEdge(graph, edgeId);
    addEdge(graph, edge.a, node.id, { type: edge.type, path: edge.path });
    addEdge(graph, node.id, edge.b, { type: edge.type, path: edge.path });
    return node.id;
}

// Closest point on any edge accepted by `filter` (by default the ground-floor walkways).
export function nearestOnEdges(graph, coord, filter = walkOnFloor(graph, 1)) {
    let best = null;
    for (const edge of graph.edges.values()) {
        if (!filter(edge)) continue;
        const hit = projectOnSegment(coord, graph.nodes.get(edge.a).coord, graph.nodes.get(edge.b).coord);
        if (!best || hit.distance < best.distance) best = { ...hit, edge };
    }
    return best;
}

// Returns a node on the network at the given projection: an existing vertex if one is close, else a split.
function attachAt(graph, hit) {
    for (const end of [hit.edge.a, hit.edge.b]) {
        if (meters(graph.nodes.get(end).coord, hit.point) <= VERTEX_REUSE_METERS) return end;
    }
    return splitEdge(graph, hit.edge.id, hit.point);
}

// Node on the ground-floor walkways closest to `coord` (splitting a walkway if needed), or null without walkways.
// Used for a GPS start point. Returns { nodeId, distance } where distance is how far `coord` is from the walkway.
export function attachPoint(graph, coord) {
    const hit = nearestOnEdges(graph, coord);
    return hit ? { nodeId: attachAt(graph, hit), distance: hit.distance } : null;
}

// Connects a standalone node (a ground-floor door) to the nearest walkway on its floor with a 'link' edge.
function linkToNetwork(graph, nodeId) {
    const node = graph.nodes.get(nodeId);
    const hit = nearestOnEdges(graph, node.coord, walkOnFloor(graph, node.floor));
    if (!hit) return null;
    const attachId = attachAt(graph, hit);
    addEdge(graph, nodeId, attachId, { type: 'link' });
    return attachId;
}

function addPaths(graph, paths) {
    const endpoints = [];
    for (const feature of paths.features) {
        const { id, type, floor = 1 } = feature.properties;
        const coords = feature.geometry.coordinates.filter((c, i, all) => i === 0 || !sameCoord(c, all[i - 1]));
        coords.forEach((coord, i) => {
            addNode(graph, { id: `${id}:${i}`, coord, floor, paths: new Set([id]) });
            if (i > 0) addEdge(graph, `${id}:${i - 1}`, `${id}:${i}`, { type, path: id });
        });
        endpoints.push({ id: `${id}:0`, path: id }, { id: `${id}:${coords.length - 1}`, path: id });
    }
    return endpoints;
}

// The hand-drawn paths don't share exact points; join them where they nearly touch. Only paths on the same floor
// are joined: a corridor drawn right above a walkway stays separate from it.
// A path end within SNAP_METERS of another path joins it at the closest point on it: that path's vertex when the
// closest point is (or is within VERTEX_REUSE_METERS of) one, else a new vertex splitting the segment. Joining at the
// closest point moves the end as little as possible, so the route stays on the walkway as drawn. (Joining the nearest
// vertex instead moved a walkway that starts on a gate's line 1.5 m along the gate, and the route slanted off it.)
function stitchPaths(graph, endpoints) {
    const alias = new Map();
    const resolve = (id) => {
        while (alias.has(id)) id = alias.get(id);
        return id;
    };

    for (const end of endpoints) {
        const endId = resolve(end.id);
        const endNode = graph.nodes.get(endId);
        const hit = nearestOnEdges(
            graph,
            endNode.coord,
            (edge) =>
                walkOnFloor(graph, endNode.floor)(edge) && !endNode.paths.has(edge.path) && edge.a !== endId && edge.b !== endId,
        );
        if (!hit || hit.distance > SNAP_METERS) continue;
        const attachId = attachAt(graph, hit);
        mergeNodes(graph, attachId, endId);
        alias.set(endId, attachId);
    }
}

// Links a stair node to the nearest path on its floor, and to one more path within STAIR_LINK_METERS. On the ground
// floor the nearest walkway is always linked; upstairs only a corridor within UPPER_STAIR_LINK_METERS.
function linkStairs(graph, nodeId) {
    const node = graph.nodes.get(nodeId);
    const onFloor = walkOnFloor(graph, node.floor);
    const nearestPerPath = new Map();
    for (const edge of graph.edges.values()) {
        if (!onFloor(edge)) continue;
        const hit = projectOnSegment(node.coord, graph.nodes.get(edge.a).coord, graph.nodes.get(edge.b).coord);
        const best = nearestPerPath.get(edge.path);
        if (!best || hit.distance < best.distance) nearestPerPath.set(edge.path, { ...hit, edge });
    }
    const reach = node.floor === 1 ? Infinity : UPPER_STAIR_LINK_METERS;
    [...nearestPerPath.values()]
        .sort((a, b) => a.distance - b.distance)
        .filter((hit, i) => hit.distance <= (i === 0 ? reach : STAIR_LINK_METERS))
        .slice(0, STAIR_LINKS)
        .forEach((hit) => addEdge(graph, nodeId, attachAt(graph, hit), { type: 'link' }));
}

function addStairs(graph, stairs) {
    for (const feature of stairs.features) {
        const { id, name, building_id: buildingId, floors, center } = feature.properties;
        for (let floor = 1; floor <= floors; floor++) {
            addNode(graph, { id: `${id}:f${floor}`, coord: center, floor, kind: 'stair', stairId: id, name, buildingId });
            if (floor > 1) {
                addEdge(graph, `${id}:f${floor - 1}`, `${id}:f${floor}`, {
                    type: 'stairs',
                    weight: METERS_PER_FLOOR * STAIRS_FACTOR,
                });
            }
            linkStairs(graph, `${id}:f${floor}`);
        }
    }
}

// The corridor edges on `floor` reachable from these stairs without changing floor: a building's own corridors, so
// a room never links to the corridor of the building next door.
function corridorsFrom(graph, stairNodes, floor) {
    const corridors = new Set();
    const seen = new Set(stairNodes.map((n) => n.id));
    const queue = [...seen];
    while (queue.length) {
        const id = queue.shift();
        for (const edgeId of graph.adjacency.get(id)) {
            const edge = graph.edges.get(edgeId);
            const next = otherEnd(edge, id);
            if (edge.type === 'stairs' || graph.nodes.get(next).floor !== floor) continue;
            if (isWalkEdge(edge)) corridors.add(edge.id);
            if (!seen.has(next)) {
                seen.add(next);
                queue.push(next);
            }
        }
    }
    return corridors;
}

// Ground-floor rooms link to the nearest walkway. A room upstairs links to the nearest corridor its building's stairs
// reach on that floor; without one it falls back to a straight link to those stairs, and `warn` says so.
function addRooms(graph, entrances, warn) {
    const stairNodes = [...graph.nodes.values()].filter((n) => n.kind === 'stair');
    const warned = new Set();
    for (const feature of entrances.features) {
        const { id, name, building_id: buildingId, floor = 1, type, door } = feature.properties;
        addNode(graph, { id, coord: feature.geometry.coordinates, floor, kind: 'room', name, buildingId, roomType: type, door });
        if (floor === 1) {
            linkToNetwork(graph, id);
            continue;
        }
        const sameFloorStairs = stairNodes.filter((s) => s.buildingId === buildingId && s.floor === floor);
        if (!sameFloorStairs.length) {
            warn(`No stairs reach floor ${floor} of ${buildingId}; ${name} may be unreachable.`);
            continue;
        }
        const corridors = corridorsFrom(graph, sameFloorStairs, floor);
        const hit = corridors.size ? nearestOnEdges(graph, feature.geometry.coordinates, (edge) => corridors.has(edge.id)) : null;
        if (hit && hit.distance <= UPPER_ROOM_LINK_METERS) {
            addEdge(graph, id, attachAt(graph, hit), { type: 'link' });
            continue;
        }
        const where = `floor ${floor} of ${buildingId}`;
        if (!corridors.size && !warned.has(where)) {
            warned.add(where);
            warn(`No corridor on ${where} reaches its stairs; rooms there are linked straight to the stairs.`);
        } else if (corridors.size) {
            warn(`${name} is more than ${UPPER_ROOM_LINK_METERS} m from the corridors on ${where}; it is linked straight to the stairs.`);
        }
        sameFloorStairs.forEach((s) => addEdge(graph, id, s.id, { type: 'link' }));
    }
}

// `warn` reports upstairs rooms that fall back to a straight link (no corridor drawn yet).
export function buildGraph({ paths, entrances, stairs = { features: [] } }, { warn = console.warn } = {}) {
    const graph = createGraph();
    stitchPaths(graph, addPaths(graph, paths));
    addStairs(graph, stairs);
    addRooms(graph, entrances, warn);
    return graph;
}

// Places people can pick as a start or destination: gates and rooms.
export function listPlaces(graph, paths) {
    const places = [];
    for (const feature of paths.features) {
        if (feature.properties.type !== 'gate') continue;
        const coords = feature.geometry.coordinates;
        const mid = [(coords[0][0] + coords.at(-1)[0]) / 2, (coords[0][1] + coords.at(-1)[1]) / 2];
        const onGate = [...graph.nodes.values()].filter((n) => n.kind === 'path' && n.paths.has(feature.properties.id));
        const nearest = onGate.reduce((a, b) => (meters(a.coord, mid) <= meters(b.coord, mid) ? a : b));
        places.push({ id: feature.properties.id, name: feature.properties.name, kind: 'gate', floor: 1, nodeId: nearest.id });
    }
    for (const node of graph.nodes.values()) {
        if (node.kind !== 'room') continue;
        places.push({ id: node.id, name: node.name, kind: 'room', floor: node.floor, buildingId: node.buildingId, nodeId: node.id });
    }
    return places;
}

// Node ids reachable from `startId`; used to check the stitching left no islands.
export function reachableFrom(graph, startId) {
    const seen = new Set([startId]);
    const queue = [startId];
    while (queue.length) {
        const id = queue.shift();
        for (const edgeId of graph.adjacency.get(id)) {
            const next = otherEnd(graph.edges.get(edgeId), id);
            if (!seen.has(next)) {
                seen.add(next);
                queue.push(next);
            }
        }
    }
    return seen;
}

// Debug overlay: nodes as points, edges as lines.
export function graphToGeoJSON(graph) {
    const features = [];
    for (const edge of graph.edges.values()) {
        features.push({
            type: 'Feature',
            properties: { kind: 'edge', type: edge.type, floor: floorOf(graph, edge), weight: Math.round(edge.weight * 10) / 10 },
            geometry: { type: 'LineString', coordinates: [graph.nodes.get(edge.a).coord, graph.nodes.get(edge.b).coord] },
        });
    }
    for (const node of graph.nodes.values()) {
        features.push({
            type: 'Feature',
            properties: { kind: 'node', nodeKind: node.kind, id: node.id, floor: node.floor },
            geometry: { type: 'Point', coordinates: node.coord },
        });
    }
    return { type: 'FeatureCollection', features };
}
