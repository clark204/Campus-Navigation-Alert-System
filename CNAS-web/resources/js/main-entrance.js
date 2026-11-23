import { loadMapData } from './map-data.js';
import { buildGraph, listPlaces } from './navigation/graph.js';
import { findRoute } from './navigation/router.js';

// A building's main entrance, for its QR code (admin/qr-code-page): of its doors (ground-floor room ids), the one the
// router walks to from the Entrance Gate, where /navigate starts when nothing else is picked (main.js preset('p1')).
// The same way /navigate goes to a building: findRoute to the set of its doors.

const DEFAULT_START = 'p1';

export function mainEntrance(graph, startNodeId, doorIds) {
    const doors = new Set(doorIds.filter((id) => graph.nodes.has(id)));
    if (!doors.size || !graph.nodes.has(startNodeId)) return null;
    return findRoute(graph, startNodeId, doors)?.nodes.at(-1).id ?? null;
}

// The main entrance among `doorIds`, from the live map. Null when the map or the gate isn't there.
export async function findMainEntrance(mapUrl, doorIds) {
    const data = await loadMapData(mapUrl);
    const graph = buildGraph(data, { warn: () => {} });
    const places = listPlaces(graph, data.paths);
    const start = places.find((place) => place.id === DEFAULT_START) ?? places.find((place) => place.kind === 'gate');
    return start ? mainEntrance(graph, start.nodeId, doorIds) : null;
}
