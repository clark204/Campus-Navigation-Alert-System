import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, test } from 'node:test';
import {
    addEvacuationTargets,
    blockedEdges,
    circlePolygon,
    dangerFromAlert,
    findEvacuationRoute,
    isPointDangerous,
    isSegmentDangerous,
    NO_SAFE_ROUTE,
    pointInRing,
} from '../../resources/js/navigation/evacuation.js';
import { meters } from '../../resources/js/navigation/geo.js';
import { buildGraph, listPlaces } from '../../resources/js/navigation/graph.js';
import { shortestPath } from '../../resources/js/navigation/router.js';

const load = (file) => JSON.parse(readFileSync(new URL(`../../database/data/maps/${file}`, import.meta.url)));
const campus = {
    paths: load('paths.geojson'),
    entrances: load('entrances.geojson'),
    stairs: load('stairs.geojson'),
    buildings: load('buildings.geojson'),
};

// ~1 m in degrees near the campus (8.54° N); longitude degrees are a little longer.
const M = 1 / 111320;
const LNG_M = M / Math.cos((8.54 * Math.PI) / 180);
const at = (east, north) => [124.7 + east * LNG_M, 8.54 + north * M];

const line = (id, coordinates) => ({
    type: 'Feature',
    properties: { id, name: id, type: 'walkway' },
    geometry: { type: 'LineString', coordinates },
});
const collection = (features) => ({ type: 'FeatureCollection', features });
const square = (west, south, size) => [at(west, south), at(west + size, south), at(west + size, south + size), at(west, south + size), at(west, south)];
// Path ends are merged when stitching, so find nodes by where they are rather than by id.
const nodeAt = (graph, coord) => [...graph.nodes.values()].find((node) => meters(node.coord, coord) < 0.5).id;
const alertAt = (east, north, radius, extra = {}) => {
    const [longitude, latitude] = at(east, north);
    return { status: 'active', latitude, longitude, danger_radius_m: radius, building_codes: [], ...extra };
};

/*
 * A loop of walkways, 100 m wide and 40 m tall:
 *
 *   top:     (0,40) ---------------------- (100,40)
 *              |                              |
 *   bottom:  (0,0) ----- (50,0) ---------- (100,0)
 *
 * Start at the west end (0,0), safety at the east end (100,0). The bottom is the short way (100 m), the top the long
 * way around (180 m).
 */
function loopGraph() {
    const graph = buildGraph({
        paths: collection([
            line('bottom', [at(0, 0), at(50, 0), at(100, 0)]),
            line('west', [at(0, 0), at(0, 40)]),
            line('top', [at(0, 40), at(100, 40)]),
            line('east', [at(100, 40), at(100, 0)]),
        ]),
        entrances: collection([]),
    });
    return { graph, start: nodeAt(graph, at(0, 0)), safe: new Set([nodeAt(graph, at(100, 0))]) };
}

describe('danger zone', () => {
    test('edges inside or crossing the danger circle are blocked; edges outside are not', () => {
        const { graph } = loopGraph();
        // 10 m circle on the bottom walkway at x = 50.
        const danger = dangerFromAlert(alertAt(50, 0, 10), campus.buildings);
        const blocked = blockedEdges(graph, danger);

        const blockedPaths = [...blocked].map((id) => graph.edges.get(id).path).sort();
        assert.deepEqual(blockedPaths, ['bottom', 'bottom'], 'both halves of the bottom walkway touch the circle');
        assert.ok(!isSegmentDangerous(at(0, 40), at(100, 40), danger), 'the top walkway is 40 m away');
    });

    test('a walkway passing through the circle without a node inside it is still blocked', () => {
        const danger = dangerFromAlert(alertAt(50, 5, 10), campus.buildings);
        assert.ok(isSegmentDangerous(at(0, 0), at(100, 0), danger));
        assert.ok(!isSegmentDangerous(at(0, 20), at(100, 20), danger));
    });

    test('edges that enter an affected building are blocked', () => {
        const buildings = collection([
            { type: 'Feature', properties: { id: 'bx', type: 'building' }, geometry: { type: 'Polygon', coordinates: [square(40, -5, 10)] } },
        ]);
        const danger = dangerFromAlert({ status: 'active', latitude: null, longitude: null, building_codes: ['bx'] }, buildings);

        assert.ok(isSegmentDangerous(at(0, 0), at(100, 0), danger), 'crosses the building');
        assert.ok(isSegmentDangerous(at(45, 0), at(45, 30), danger), 'starts inside it');
        assert.ok(!isSegmentDangerous(at(0, 10), at(100, 10), danger), 'passes beside it');
    });

    test('there is no danger area without an active alert or for a whole-campus alert', () => {
        assert.equal(dangerFromAlert(null, campus.buildings), null);
        assert.equal(dangerFromAlert({ ...alertAt(0, 0, 50), status: 'resolved' }, campus.buildings), null);
        const typhoon = dangerFromAlert({ status: 'active', latitude: null, longitude: null, building_codes: [] }, campus.buildings);
        assert.equal(blockedEdges(loopGraph().graph, typhoon).size, 0);
    });

    test('the danger circle polygon has the chosen radius', () => {
        const center = at(0, 0);
        const ring = circlePolygon(center, 50).geometry.coordinates[0];
        for (const point of ring) assert.ok(Math.abs(meters(center, point) - 50) < 0.5);
        assert.ok(pointInRing(at(10, 10), ring));
        assert.ok(!pointInRing(at(60, 0), ring));
    });
});

describe('route to safety', () => {
    test('the router avoids blocked edges and takes the long way around', () => {
        const { graph, start, safe } = loopGraph();
        const danger = dangerFromAlert(alertAt(50, 0, 10), campus.buildings);

        const plain = shortestPath(graph, start, safe);
        assert.ok(Math.abs(plain.cost - 100) < 1, 'without the alert the bottom walkway is shortest');

        const result = findEvacuationRoute(graph, start, { danger, targets: safe });
        const blocked = blockedEdges(graph, danger);
        assert.ok(result, 'a safe route exists');
        assert.equal(result.startsInDanger, false);
        assert.ok(result.route.edges.every((edge) => !blocked.has(edge.id)), 'no edge of the route is blocked');
        assert.ok(Math.abs(result.route.length - 180) < 1.5, `went around: ${result.route.length}`);
    });

    test('with every way through the danger, there is no safe route', () => {
        const graph = buildGraph({ paths: collection([line('only', [at(0, 0), at(50, 0), at(100, 0)])]), entrances: collection([]) });
        const danger = dangerFromAlert(alertAt(50, 0, 10), campus.buildings);

        const start = nodeAt(graph, at(0, 0));
        assert.equal(findEvacuationRoute(graph, start, { danger, targets: new Set([nodeAt(graph, at(100, 0))]) }), null);
        assert.equal(NO_SAFE_ROUTE, 'No safe route found. Follow staff instructions and move away from the danger area.');
    });

    test('an evacuation point inside the danger zone is never the destination', () => {
        const { graph, start, safe } = loopGraph();
        const danger = dangerFromAlert(alertAt(100, 0, 10), campus.buildings);

        assert.equal(findEvacuationRoute(graph, start, { danger, targets: safe }), null);
    });

    test('starting inside the danger, the route leaves it the shortest way and stays out', () => {
        const { graph, safe } = loopGraph();
        // 30 m circle around (0,0): the start is inside it.
        const danger = dangerFromAlert(alertAt(0, 0, 30), campus.buildings);
        const blocked = blockedEdges(graph, danger);

        const result = findEvacuationRoute(graph, nodeAt(graph, at(0, 0)), { danger, targets: safe });
        assert.ok(result, 'there is a way out');
        assert.equal(result.startsInDanger, true);
        const firstSafe = result.route.edges.findIndex((edge) => !blocked.has(edge.id));
        assert.ok(firstSafe > 0, 'it starts on blocked edges');
        assert.ok(result.route.edges.slice(firstSafe).every((edge) => !blocked.has(edge.id)), 'and never goes back in');
    });

    test('the router picks the nearest of several targets', () => {
        const { graph, start } = loopGraph();
        const nearer = nodeAt(graph, at(0, 40));
        const path = shortestPath(graph, start, new Set([nodeAt(graph, at(100, 0)), nearer]));
        assert.equal(path.nodeIds.at(-1), nearer);
    });
});

describe('campus evacuation', () => {
    const evacuationRing = campus.buildings.features.find((f) => f.properties.type === 'evacuation').geometry.coordinates[0];

    test('every place can reach the evacuation area when nothing is blocked', () => {
        const graph = buildGraph(campus);
        const targets = addEvacuationTargets(graph, evacuationRing);
        assert.ok(targets.size > 0);
        assert.equal(addEvacuationTargets(graph, evacuationRing), targets, 'added once');

        for (const place of listPlaces(graph, campus.paths)) {
            const result = findEvacuationRoute(graph, place.nodeId, { danger: null, targets });
            assert.ok(result, `${place.name} has a route`);
            assert.ok(targets.has(result.route.nodes.at(-1).id));
        }
    });

    test('a fire at the NEE Building: routes from Building 01 avoid the building and its danger zone', () => {
        const graph = buildGraph(campus);
        const targets = addEvacuationTargets(graph, evacuationRing);
        const nee = campus.buildings.features.find((f) => f.properties.id === 'b1').geometry.coordinates[0];
        const [longitude, latitude] = nee[0];
        const danger = dangerFromAlert(
            { status: 'active', latitude, longitude, danger_radius_m: 30, building_codes: ['b1'] },
            campus.buildings,
        );
        const blocked = blockedEdges(graph, danger);
        assert.ok(blocked.size > 0);

        const places = listPlaces(graph, campus.paths);
        const outside = places.filter((place) => place.buildingId === 'b2' && !isPointDangerous(graph.nodes.get(place.nodeId).coord, danger));
        assert.ok(outside.length > 0);
        const routes = outside.map((place) => findEvacuationRoute(graph, place.nodeId, { danger, targets, blocked })).filter(Boolean);
        assert.ok(routes.length > 0, 'Building 01 has a safe way out');
        for (const { route } of routes) {
            assert.ok(route.edges.every((edge) => !blocked.has(edge.id)), 'no blocked edge on the way');
        }

        // From a 3rd-floor NEE room the route first has to get out of the burning building.
        const upstairs = places.find((place) => place.buildingId === 'b1' && place.floor === 3);
        const out = findEvacuationRoute(graph, upstairs.nodeId, { danger, targets, blocked });
        assert.ok(out, 'a way out of the NEE Building');
        assert.equal(out.startsInDanger, true);
    });
});
