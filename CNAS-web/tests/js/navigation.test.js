import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, test } from 'node:test';
import { angleBetween, meters, sameCoord, turnDelta } from '../../resources/js/navigation/geo.js';
import { attachPoint, buildGraph, listPlaces, reachableFrom } from '../../resources/js/navigation/graph.js';
import { createFixFilter, GPS_MAX_ACCURACY, MOVING_SPEED } from '../../resources/js/navigation/gps.js';
import { buildManeuvers, classifyTurn, doorHeading, ordinal } from '../../resources/js/navigation/instructions.js';
import { currentLeg, floorParts, legIntro, legLabel, legLine, routeLegs } from '../../resources/js/navigation/legs.js';
import { rerouteFrom, snapToWalkway } from '../../resources/js/navigation/position.js';
import { CAUGHT_UP_METERS, createSimulator, pointsAlong, SIM_SPEED, simulatedFix } from '../../resources/js/navigation/simulate.js';
import { findRoute, shortestPath } from '../../resources/js/navigation/router.js';
import { createStepCounter } from '../../resources/js/navigation/steps.js';
import { forgetTrip, recallTrip, rememberTrip, setVoiceDirections, voiceDirectionsOn } from '../../resources/js/navigation/storage.js';
import { Tracker } from '../../resources/js/navigation/tracker.js';
import { createPromptPlanner, Voice } from '../../resources/js/navigation/voice.js';

const load = (file) => JSON.parse(readFileSync(new URL(`../../database/data/maps/${file}`, import.meta.url)));
const campus = {
    paths: load('paths.geojson'),
    entrances: load('entrances.geojson'),
    stairs: load('stairs.geojson'),
    buildings: load('buildings.geojson'),
    landmarks: load('landmarks.json'),
};

const line = (id, coordinates, type = 'walkway') => ({
    type: 'Feature',
    properties: { id, name: id, type },
    geometry: { type: 'LineString', coordinates },
});
const collection = (features) => ({ type: 'FeatureCollection', features });
const door = (id, name, coord, floor = 1, buildingId = 'b1') => ({
    type: 'Feature',
    properties: { id, name, building_id: buildingId, floor, type: 'classroom', is_it: false, door: name },
    geometry: { type: 'Point', coordinates: coord },
});

// ~1 m in degrees of latitude / longitude near the campus (8.54° N).
const M = 1 / 111320;

function campusRoute(fromId, toId) {
    const graph = buildGraph(campus);
    const places = listPlaces(graph, campus.paths);
    const from = places.find((p) => p.id === fromId);
    const to = places.find((p) => p.id === toId);
    const route = findRoute(graph, from.nodeId, to.nodeId);
    const maneuvers = buildManeuvers(route, graph, {
        landmarks: campus.landmarks,
        buildings: campus.buildings,
        originName: from.name,
        destinationName: to.name,
    });
    return { graph, route, maneuvers };
}

describe('graph stitching', () => {
    test('a path end within 2 m of another path splits that segment and joins it', () => {
        const graph = buildGraph({
            paths: collection([
                line('a', [[124.7, 8.54], [124.7 + 40 * M, 8.54]]),
                // Starts 1.5 m north of the middle of "a".
                line('b', [[124.7 + 20 * M, 8.54 + 1.5 * M], [124.7 + 20 * M, 8.54 + 30 * M]]),
            ]),
            entrances: collection([]),
        });
        assert.equal(reachableFrom(graph, 'a:0').size, graph.nodes.size);
        const route = findRoute(graph, 'a:0', 'b:1');
        assert.ok(route, 'route exists');
        assert.ok(Math.abs(route.length - 50) < 1.5, `length ${route.length}`);
    });

    test('a path end lying on another path joins it there, not at a vertex 1.5 m away', () => {
        // "b" starts on the line of "a", 1.5 m east of its middle vertex (like Main Walkway starting on the gate).
        const start = [124.7 + 21.5 * M, 8.54];
        const graph = buildGraph({
            paths: collection([
                line('a', [[124.7, 8.54], [124.7 + 20 * M, 8.54], [124.7 + 40 * M, 8.54]]),
                line('b', [start, [124.7 + 21.5 * M, 8.54 + 30 * M]]),
            ]),
            entrances: collection([]),
        });
        const route = findRoute(graph, 'a:0', 'b:1');
        const joint = route.nodes.at(-2).coord;
        assert.ok(meters(joint, start) < 0.05, `joined ${meters(joint, start).toFixed(2)} m from where "b" starts`);
    });

    test('path ends within 2 m of each other merge; farther ones do not', () => {
        const graph = buildGraph({
            paths: collection([
                line('a', [[124.7, 8.54], [124.7 + 10 * M, 8.54]]),
                line('b', [[124.7 + 11 * M, 8.54], [124.7 + 20 * M, 8.54]]),
                line('c', [[124.7 + 23 * M, 8.54], [124.7 + 30 * M, 8.54]]),
            ]),
            entrances: collection([]),
        });
        const reach = reachableFrom(graph, 'a:0');
        assert.ok(reach.has('b:1'));
        assert.ok(!reach.has('c:1'));
    });

    test('every node of the campus graph is connected', () => {
        const graph = buildGraph(campus);
        const reach = reachableFrom(graph, 'p1:0');
        const islands = [...graph.nodes.keys()].filter((id) => !reach.has(id));
        assert.deepEqual(islands, []);
    });

    test("doors link to the nearest walkway; upstairs doors to their floor's corridor", () => {
        const graph = buildGraph(campus);
        const neighbours = (id) =>
            [...graph.adjacency.get(id)].map((e) => graph.edges.get(e)).map((e) => graph.nodes.get(e.a === id ? e.b : e.a));
        assert.ok(neighbours('r6').every((n) => n.kind === 'path' && n.floor === 1));
        assert.ok(neighbours('r17').every((n) => n.kind === 'path' && n.floor === 2));
        assert.ok(neighbours('r18').every((n) => n.kind === 'path' && n.floor === 3));
        const stairEdge = [...graph.edges.values()].find((e) => e.type === 'stairs');
        assert.equal(stairEdge.weight, 7);
    });
});

describe('Dijkstra', () => {
    test('takes the shorter of two ways', () => {
        //   a0 ---- a1 ---- a2      (straight, 20 m)
        //    \_____ b1 ____/        (detour, ~32 m)
        const graph = buildGraph({
            paths: collection([
                line('a', [[124.7, 8.54], [124.7 + 10 * M, 8.54], [124.7 + 20 * M, 8.54]]),
                line('b', [[124.7, 8.54], [124.7 + 10 * M, 8.54 + 12 * M], [124.7 + 20 * M, 8.54]]),
            ]),
            entrances: collection([]),
        });
        // Shared ends were merged, so look nodes up by position.
        const at = (lng, lat) => [...graph.nodes.values()].find((n) => n.coord[0] === lng && n.coord[1] === lat).id;
        const path = shortestPath(graph, at(124.7, 8.54), at(124.7 + 20 * M, 8.54));
        assert.deepEqual(path.nodeIds.slice(1, -1), ['a:1']);
        assert.ok(Math.abs(path.cost - 20) < 0.5);
    });

    test('returns null when unreachable', () => {
        const graph = buildGraph({
            paths: collection([line('a', [[124.7, 8.54], [124.7 + 5 * M, 8.54]]), line('b', [[124.8, 8.54], [124.8 + 5 * M, 8.54]])]),
            entrances: collection([]),
        });
        assert.equal(shortestPath(graph, 'a:0', 'b:1'), null);
    });
});

describe('turn angles', () => {
    test('signed delta wraps around north', () => {
        assert.equal(turnDelta(350, 10), 20);
        assert.equal(turnDelta(10, 350), -20);
        assert.equal(turnDelta(0, 180), 180);
    });

    test('classification thresholds', () => {
        assert.equal(classifyTurn(10), 'straight');
        assert.equal(classifyTurn(-19.9), 'straight');
        assert.equal(classifyTurn(20), 'slight right');
        assert.equal(classifyTurn(-45), 'slight left');
        assert.equal(classifyTurn(60), 'right');
        assert.equal(classifyTurn(-90), 'left');
        assert.equal(classifyTurn(150), 'right');
        assert.equal(classifyTurn(170), 'uturn');
    });

    test('ordinals', () => {
        assert.deepEqual([1, 2, 3, 4, 11, 21].map(ordinal), ['1st', '2nd', '3rd', '4th', '11th', '21st']);
    });

    test('an L-shaped walk gives one left turn and arrival on the correct side', () => {
        // North 20 m, then west 20 m; the room door is 3 m north of the west leg's end.
        const graph = buildGraph({
            paths: collection([line('a', [[124.7, 8.54], [124.7, 8.54 + 20 * M], [124.7 - 20 * M, 8.54 + 20 * M]])]),
            entrances: collection([door('r1', 'Room 1', [124.7 - 18 * M, 8.54 + 23 * M])]),
        });
        const route = findRoute(graph, 'a:0', 'r1');
        const maneuvers = buildManeuvers(route, graph, { destinationName: 'Room 1' });
        assert.deepEqual(maneuvers.map((m) => [m.type, m.modifier]), [
            ['depart', ''],
            ['turn', 'left'],
            ['arrive', 'on your right'],
        ]);
    });
});

describe('campus routes', () => {
    const noCodes = (text) => assert.doesNotMatch(text, /\b[bprs]\d+\b|Stair [A-Z]\b|NEE Stair/, text);

    test('Entrance Gate -> ComLab 1', (t) => {
        const { maneuvers, route } = campusRoute('p1', 'r6');
        maneuvers.forEach((m) => t.diagnostic(`${m.along.toFixed(0).padStart(3)} m  ${m.text}`));
        assert.equal(maneuvers[0].type, 'depart');
        assert.equal(maneuvers.at(-1).type, 'arrive');
        assert.match(maneuvers.at(-1).text, /^You have arrived\. ComLab 1 is on your (left|right)$/);
        assert.ok(route.length > 30 && route.length < 120, `length ${route.length}`);
        assert.ok(maneuvers.every((m) => m.type !== 'stairs'));
        maneuvers.forEach((m) => noCodes(m.text));
    });

    test('Entrance Gate -> NEE 201 goes up the stairs', (t) => {
        const { maneuvers } = campusRoute('p1', 'r17');
        maneuvers.forEach((m) => t.diagnostic(`${m.along.toFixed(0).padStart(3)} m  ${m.text}`));
        const stairs = maneuvers.filter((m) => m.type === 'stairs');
        assert.equal(stairs.length, 1);
        assert.match(stairs[0].text, /^(Turn (left|right) and g|G)o up the stairs near NEE 10\d to the 2nd floor$/);
        assert.equal(maneuvers.at(-1).type, 'arrive');
        assert.match(maneuvers.at(-1).text, /NEE 201/);
        maneuvers.forEach((m) => noCodes(m.text));
    });

    test('NEE 302 -> ComLab 1 goes down two floors', () => {
        const { maneuvers } = campusRoute('r21', 'r6');
        const stairs = maneuvers.find((m) => m.type === 'stairs');
        assert.match(stairs.text, /^Go down the stairs near NEE 10\d to the 1st floor(, then turn (left|right))?$/);
    });
});

describe('first instruction and short steps', () => {
    const steps = (maneuvers) => maneuvers.map((m) => [m.type, m.text]);

    // A 40 m walkway running east; two doors beside it, 25 m apart, on the sides given (south = -3, north = +3).
    function doorToDoor(firstSide = -3, secondSide = 3) {
        const graph = buildGraph({
            paths: collection([line('a', [[124.7, 8.54], [124.7 + 40 * M, 8.54]])]),
            entrances: collection([
                door('r1', 'Room 1', [124.7 + 5 * M, 8.54 + firstSide * M]),
                door('r2', 'Room 2', [124.7 + 30 * M, 8.54 + secondSide * M]),
            ]),
        });
        const route = findRoute(graph, 'r1', 'r2');
        return { graph, route, maneuvers: buildManeuvers(route, graph) };
    }

    test('the first instruction is relative to facing out of the door: turn right', () => {
        // The door is south of the walkway, so stepping out faces north; Room 2 is to the east.
        const { maneuvers, route } = doorToDoor(-3);
        assert.ok(angleBetween(doorHeading(route), 0) < 1, `door heading ${doorHeading(route)}`);
        assert.equal(maneuvers[0].text, 'Turn right toward Room 2');
        assert.equal(maneuvers[0].now, 'Turn right toward Room 2');
        assert.equal(maneuvers[0].modifier, 'right');
    });

    test('the first instruction is relative to facing out of the door: turn left', () => {
        // The door is north of the walkway, so stepping out faces south; east is then on the left.
        const { maneuvers } = doorToDoor(3);
        assert.equal(maneuvers[0].text, 'Turn left toward Room 2');
    });

    test('a destination straight out of the door is "Walk straight ahead", never a turn', () => {
        // A walkway running north from just outside the door.
        const graph = buildGraph({
            paths: collection([line('a', [[124.7, 8.54], [124.7, 8.54 + 40 * M]])]),
            entrances: collection([door('r1', 'Room 1', [124.7, 8.54 - 3 * M]), door('r2', 'Room 2', [124.7 + 3 * M, 8.54 + 30 * M])]),
        });
        const maneuvers = buildManeuvers(findRoute(graph, 'r1', 'r2'), graph);
        assert.equal(maneuvers[0].text, 'Walk straight ahead toward Room 2');
        assert.ok(maneuvers.every((m) => m.type !== 'turn'));
    });

    test('a known heading against the route gives "Turn around"', () => {
        const { graph } = doorToDoor();
        const route = findRoute(graph, 'a:0', 'r2');
        assert.equal(buildManeuvers(route, graph, { heading: 270 }).at(0).text, 'Turn around and walk toward Room 2');
        assert.equal(buildManeuvers(route, graph, { heading: 85 }).at(0).text, 'Walk straight ahead toward Room 2');
    });

    test('an unknown heading never says left or right: it names a landmark', () => {
        // North 20 m, then west 20 m; the Library stands beside the bend.
        const graph = buildGraph({
            paths: collection([line('a', [[124.7, 8.54], [124.7, 8.54 + 20 * M], [124.7 - 20 * M, 8.54 + 20 * M]])]),
            entrances: collection([door('r1', 'Room 1', [124.7 - 18 * M, 8.54 + 23 * M])]),
        });
        const library = {
            type: 'Feature',
            properties: { id: 'b9', type: 'building' },
            geometry: { type: 'Polygon', coordinates: [[[124.7 + 3 * M, 8.54 + 18 * M], [124.7 + 13 * M, 8.54 + 18 * M], [124.7 + 13 * M, 8.54 + 28 * M], [124.7 + 3 * M, 8.54 + 28 * M], [124.7 + 3 * M, 8.54 + 18 * M]]] },
        };
        const route = findRoute(graph, 'a:0', 'r1');
        const withLibrary = buildManeuvers(route, graph, { buildings: collection([library]), landmarks: { buildings: { b9: 'the Library' } } });
        assert.equal(withLibrary[0].text, 'Walk toward the Library');
        assert.equal(withLibrary[0].modifier, '');
        assert.equal(buildManeuvers(route, graph)[0].text, 'Walk toward Room 1');
        assert.equal(buildManeuvers(route, graph, { heading: null })[0].text, 'Walk toward Room 1');
    });

    test('the door-to-path connector at the start is not its own step or turn', () => {
        const { maneuvers, route } = doorToDoor();
        assert.deepEqual(maneuvers.map((m) => m.type), ['depart', 'arrive']);
        // The first leg runs from the door itself, and the line still starts there.
        assert.ok(Math.abs(maneuvers[1].legBefore - 28) < 0.5, `leg ${maneuvers[1].legBefore}`);
        assert.deepEqual(route.line.geometry.coordinates[0], [124.7 + 5 * M, 8.54 - 3 * M]);
    });

    test('the connector at the destination stays as the arrival step', () => {
        const { maneuvers } = doorToDoor();
        assert.equal(maneuvers.at(-1).text, 'You have arrived. Room 2 is on your left');
    });

    test('a room start that leads to stairs heads toward the stairs', () => {
        const { maneuvers } = campusRoute('r16', 'r17');
        assert.match(maneuvers[0].text, /^(Walk straight ahead|Turn (left|right)|Turn around and walk) toward the stairs near NEE 10\d$/);
    });

    test('a turn less than 3 m before the door is dropped and the side is taken from the walk before it', () => {
        // North 20 m, then west; the door is 2.5 m past the bend and 3 m north of the walkway.
        const graph = buildGraph({
            paths: collection([line('a', [[124.7, 8.54], [124.7, 8.54 + 20 * M], [124.7 - 20 * M, 8.54 + 20 * M]])]),
            entrances: collection([door('r1', 'Room 1', [124.7 - 2.5 * M, 8.54 + 23 * M])]),
        });
        const maneuvers = buildManeuvers(findRoute(graph, 'a:0', 'r1'), graph);
        assert.deepEqual(steps(maneuvers), [
            ['depart', 'Walk toward Room 1'],
            ['arrive', 'You have arrived. Room 1 is on your left'],
        ]);
    });

    test('no campus route has a turn with less than 3 m before or after it', () => {
        const graph = buildGraph(campus);
        const places = listPlaces(graph, campus.paths);
        const short = [];
        for (const from of places) {
            for (const to of places) {
                const route = from.id === to.id ? null : findRoute(graph, from.nodeId, to.nodeId);
                if (!route) continue;
                const maneuvers = buildManeuvers(route, graph, { landmarks: campus.landmarks, buildings: campus.buildings });
                maneuvers.forEach((m, i) => {
                    if (m.type === 'turn' && (m.legBefore < 3 || maneuvers[i + 1].legBefore < 3)) short.push(`${from.name} -> ${to.name}: ${m.text}`);
                });
                if (from.kind === 'room') assert.doesNotMatch(maneuvers[0].text, /^Step out/);
                if (from.kind === 'gate') assert.doesNotMatch(maneuvers[0].text, /left|right/);
            }
        }
        assert.deepEqual(short, []);
    });
});

describe('step counter', () => {
    test('counts one step per peak of a 2 Hz walk and ignores small jitter', () => {
        const push = createStepCounter({ threshold: 1 });
        let steps = 0;
        for (let t = 0; t < 10000; t += 20) {
            const settled = t > 1500; // let the gravity estimate settle first
            const magnitude = 9.81 + (settled ? 3 * Math.sin((2 * Math.PI * 2 * t) / 1000) : 0) + 0.1 * Math.sin(t);
            if (push(magnitude, t)) steps++;
        }
        // 8.5 s of walking at 2 steps/s.
        assert.ok(steps >= 15 && steps <= 18, `steps ${steps}`);
    });

    test('never counts two steps within 300 ms', () => {
        const push = createStepCounter({ threshold: 0.5 });
        const times = [];
        for (let t = 0; t < 5000; t += 10) {
            if (push(9.81 + 4 * Math.sin((2 * Math.PI * 6 * t) / 1000), t)) times.push(t);
        }
        times.slice(1).forEach((time, i) => assert.ok(time - times[i] >= 300));
    });
});

// Positions in meters east and north of a fixed campus point.
const LNG = 124.7;
const LAT = 8.54;
const at = (east, north) => [LNG + east * M, LAT + north * M];
const offset = ([lng, lat]) => [(lng - LNG) / M, (lat - LAT) / M];
const position = ([longitude, latitude], { accuracy = 5, speed = 1.4, heading = null, t = 0 } = {}) => ({
    coords: { longitude, latitude, accuracy, speed, heading },
    timestamp: t,
});

describe('GPS fixes', () => {
    test(`a fix worse than ${GPS_MAX_ACCURACY} m is ignored; one within it is used`, () => {
        assert.equal(GPS_MAX_ACCURACY, 25);
        const filter = createFixFilter({ start: at(0, 0) });
        const bad = filter.push(position(at(3, 0), { accuracy: 30, t: 1000 }));
        assert.deepEqual([bad.accepted, bad.reason], [false, 'too inaccurate']);
        assert.deepEqual(filter.coord, at(0, 0));
        assert.equal(filter.push(position(at(3, 0), { accuracy: 20, t: 2000 })).accepted, true);
    });

    test('standing still, fixes do not move the position', () => {
        const filter = createFixFilter({ start: at(0, 0) });
        const still = filter.push(position(at(6, 2), { speed: 0, t: 1000 }));
        assert.deepEqual([still.accepted, still.moving, still.reason], [false, false, 'standing still']);
        // No speed from the phone: no steps means standing still too.
        assert.equal(filter.push(position(at(6, 2), { speed: null, t: 2000 }), { recentSteps: false }).moving, false);
        assert.deepEqual(filter.coord, at(0, 0));
    });

    test('without a speed, moving means steps were taken, or about 5 m covered in 5 s', () => {
        const filter = createFixFilter({ start: at(0, 0) });
        assert.equal(filter.push(position(at(1, 0), { speed: null, t: 1000 }), { recentSteps: true }).moving, true);
        const noSteps = createFixFilter({ start: at(0, 0) });
        const moving = [0, 1, 2, 3, 4].map((s) => noSteps.push(position(at(s * 1.4, 0), { speed: null, t: 1000 * (s + 1) })).moving);
        assert.deepEqual(moving, [false, false, false, false, true]);
    });

    test('a precise fix moves the position more than a rough one, and neither jumps all the way', () => {
        const moved = (accuracy) => {
            const filter = createFixFilter({ start: at(0, 0) });
            filter.push(position(at(10, 0), { accuracy, t: 1000 }));
            return offset(filter.coord)[0];
        };
        assert.ok(moved(5) > moved(20), `${moved(5)} vs ${moved(20)}`);
        assert.ok(moved(5) < 10 && moved(20) > 0);
    });

    test('heading comes from coords.heading while moving', () => {
        const filter = createFixFilter({ start: at(0, 0) });
        filter.push(position(at(1, 1), { heading: 45, t: 1000 }));
        assert.deepEqual(filter.heading, { value: 45, source: 'gps' });
    });

    test('without coords.heading, heading is the bearing between positions at least 5 m apart', () => {
        const filter = createFixFilter({ start: at(0, 0) });
        const headings = [];
        for (let s = 1; s <= 12; s++) headings.push(filter.push(position(at(s * 1.4, 0), { t: 1000 * s })).heading);
        assert.equal(headings[0].value, null);
        assert.equal(headings[0].source, 'unknown');
        const first = headings.findIndex((h) => h.value !== null);
        assert.ok(first > 1, `heading after ${first + 1} fixes`);
        assert.ok(angleBetween(headings.at(-1).value, 90) < 5, `heading ${headings.at(-1).value}`);
        assert.equal(headings.at(-1).source, 'gps');
    });

    test('jitter of a few meters gives no heading', () => {
        const filter = createFixFilter({ start: at(0, 0) });
        const wobble = [[2, 0], [0, 2], [-2, 0], [0, -2], [2, 1], [-1, 2], [1, -2], [-2, -1]];
        wobble.forEach(([e, n], i) => filter.push(position(at(e, n), { speed: null, t: 1000 * (i + 1) }), { recentSteps: true }));
        assert.deepEqual(filter.heading, { value: null, source: 'unknown' });
    });

    test('the heading is kept for 30 s of standing still, then unknown', () => {
        const filter = createFixFilter({ start: at(0, 0) });
        filter.push(position(at(1, 1), { heading: 45, t: 1000 }));
        filter.push(position(at(1, 1), { speed: 0, t: 25000 }));
        assert.equal(filter.heading.value, 45);
        filter.push(position(at(1, 1), { speed: 0, t: 32000 }));
        assert.deepEqual(filter.heading, { value: null, source: 'unknown' });
    });
});

/*
 *   (20,30) c ------------- (60,30)
 *      |                       |
 *      b                       d
 *      |                       |
 *   (0,0) ---- a ---(20,0)--- (60,0)      Room 1's door is 3 m south of (58,0).
 */
function block() {
    const graph = buildGraph({
        paths: collection([
            line('a', [at(0, 0), at(60, 0)]),
            line('b', [at(20, 0), at(20, 30)]),
            line('c', [at(20, 30), at(60, 30)]),
            line('d', [at(60, 30), at(60, 0)]),
        ]),
        entrances: collection([door('r1', 'Room 1', at(58, -3))]),
    });
    const route = findRoute(graph, 'a:0', 'r1');
    const tracker = new Tracker(route, buildManeuvers(route, graph), { stride: 0.7, graph });
    return { graph, route, tracker, clock: { t: 0 } };
}

// Walks a line through `points` (meters east, north) at `speed`, one fix a second, and feeds each fix to the tracker.
function drive(tracker, clock, points, { speed = 1.4, accuracy = 5, heading = null } = {}) {
    const statuses = [];
    for (let i = 1; i < points.length; i++) {
        const [[ax, ay], [bx, by]] = [points[i - 1], points[i]];
        const count = Math.max(1, Math.round(Math.hypot(bx - ax, by - ay) / speed));
        for (let k = 1; k <= count; k++) {
            clock.t += 1000;
            const fix = position(at(ax + ((bx - ax) * k) / count, ay + ((by - ay) * k) / count), { accuracy, speed, heading, t: clock.t });
            statuses.push(tracker.gps(fix));
        }
    }
    return statuses;
}

describe('tracker', () => {
    test('GPS moves the dot along the walkway it is near', () => {
        const { tracker, clock } = block();
        drive(tracker, clock, [[0, 1.5], [15, 1.5]]);
        const [east, north] = offset(tracker.position());
        assert.ok(Math.abs(north) < 0.01, `north ${north}`);
        assert.ok(east > 9 && east < 15.1, `east ${east}`);
        assert.ok(Math.abs(tracker.progress - east) < 0.3);
        assert.equal(tracker.source, 'gps');
    });

    test('a fix worse than 25 m is ignored and leaves the dot where it is', () => {
        const { tracker, clock } = block();
        drive(tracker, clock, [[0, 0], [7, 0]]);
        const before = tracker.position();
        const status = tracker.gps(position(at(20, 0), { accuracy: 30, t: clock.t + 1000 }));
        assert.deepEqual([status.used, status.reason], [false, 'too inaccurate']);
        assert.deepEqual(tracker.position(), before);
    });

    test('standing still, GPS jitter does not move the dot', () => {
        const { tracker, clock } = block();
        const start = tracker.position();
        [[5, 2], [-3, 4], [6, -1]].forEach(([e, n]) => {
            clock.t += 1000;
            assert.equal(tracker.gps(position(at(e, n), { speed: 0, t: clock.t })).reason, 'standing still');
        });
        assert.deepEqual(tracker.position(), start);
        assert.equal(tracker.progress, 0);
    });

    test('more than 10 m off the route for 3 fixes in a row asks for a new route', () => {
        const { tracker, clock } = block();
        drive(tracker, clock, [[0, 0], [20, 0]]);
        const north = drive(tracker, clock, [[20, 0], [20, 28]]);
        const due = north.findIndex((s) => s.rerouteDue);
        assert.ok(due >= 2, `reroute at fix ${due}`);
        assert.ok(north.slice(due - 2, due + 1).every((s) => s.offRoute > 10), north.map((s) => s.offRoute.toFixed(1)).join(' '));
        assert.ok(north[due - 3].offRoute <= 10);
        // The dot left the route along the side walkway.
        const [east, up] = offset(tracker.position());
        assert.ok(Math.abs(east - 20) < 0.01 && up > 10, `dot at ${east}, ${up}`);
    });

    test('two fixes off the route then back on: no new route; a third in a row: new route', () => {
        // Precise fixes (±1 m) with steps telling the filter the person walks, so the dot follows them closely.
        const fixes = (tracker, clock, points) =>
            points.map(([e, n]) => {
                clock.t += 1000;
                return tracker.gps(position(at(e, n), { accuracy: 1, speed: null, t: clock.t }), { recentSteps: true });
            });
        const north = [[20, 4], [20, 8], [20, 11.5], [20, 12.5]];

        const back = block();
        drive(back.tracker, back.clock, [[0, 0], [20, 0]], { accuracy: 1 });
        const there = fixes(back.tracker, back.clock, [...north, [20, 6], [20, 1]]);
        assert.deepEqual(there.map((s) => s.offRoute > 10), [false, false, true, true, false, false], there.map((s) => s.offRoute.toFixed(1)).join(' '));
        assert.ok(there.every((s) => !s.rerouteDue));

        const on = block();
        drive(on.tracker, on.clock, [[0, 0], [20, 0]], { accuracy: 1 });
        assert.deepEqual(fixes(on.tracker, on.clock, [...north, [20, 13.5]]).map((s) => s.rerouteDue), [false, false, false, false, true]);
    });

    test('a new route starts at the dot and its first line follows the GPS heading', () => {
        const { graph, tracker, clock } = block();
        drive(tracker, clock, [[0, 0], [20, 0]]);
        const north = drive(tracker, clock, [[20, 0], [20, 28]]);
        assert.ok(north.some((s) => s.rerouteDue));
        const here = tracker.position();
        assert.ok(angleBetween(tracker.heading.value, 0) < 10, `heading ${tracker.heading.value}`);
        assert.equal(tracker.heading.source, 'gps');

        const route = rerouteFrom(graph, here, 'r1');
        assert.ok(meters(route.line.geometry.coordinates[0], here) < 0.01);
        assert.equal(route.nodes.at(-1).id, 'r1');
        const maneuvers = buildManeuvers(route, graph, { heading: tracker.heading.value });
        assert.equal(maneuvers[0].text, 'Turn around and walk toward Room 1');

        // The new tracker starts at the dot, keeps the smoothed GPS, and gives a few fixes of grace.
        const next = new Tracker(route, maneuvers, { stride: 0.7, graph, filter: tracker.filter, graceFixes: 3 });
        assert.ok(meters(next.position(), here) < 0.01);
        assert.ok(drive(next, clock, [[20, 28], [20, 30], [21, 30]]).every((s) => !s.rerouteDue));
    });

    test('rerouting respects blocked walkways and reports when there is no way', () => {
        const { graph } = block();
        const cost = (edge) => (edge.path === 'a' ? Infinity : edge.weight);
        assert.equal(rerouteFrom(graph, at(20, 10), 'r1', { cost }), null);
    });

    test('walking back along the route warns about the wrong way once, without a new route', () => {
        const { tracker, clock } = block();
        drive(tracker, clock, [[0, 0], [20, 0]]);
        const back = drive(tracker, clock, [[20, 0], [6, 0]]);
        assert.equal(back.filter((s) => s.wrongWayStarted).length, 1);
        assert.ok(back.every((s) => !s.rerouteDue));
        assert.equal(tracker.wrongWay, true);
        drive(tracker, clock, [[6, 0], [20, 0]]);
        assert.equal(tracker.wrongWay, false);
    });

    test('between fixes, steps move the dot along the GPS heading, at most 15 m', () => {
        const { tracker, clock } = block();
        drive(tracker, clock, [[0, 0], [14, 0]]);
        const before = tracker.progress;
        for (let i = 0; i < 5; i++) tracker.step();
        assert.ok(Math.abs(tracker.progress - before - 3.5) < 0.1, `moved ${tracker.progress - before}`);
        for (let i = 0; i < 40; i++) tracker.step();
        assert.ok(Math.abs(tracker.progress - before - 15) < 0.8, `moved ${tracker.progress - before}`);
        assert.equal(tracker.source, 'steps');
    });

    test('without a heading, steps follow the route', () => {
        const { tracker } = block();
        for (let i = 0; i < 10; i++) tracker.step();
        assert.ok(Math.abs(tracker.progress - 7) < 1e-9);
    });

    test('the walked part of the line is cut off', () => {
        const { tracker, clock, route } = block();
        drive(tracker, clock, [[0, 0], [25, 0]]);
        const coordinates = tracker.remainingLine().geometry.coordinates;
        assert.ok(meters(coordinates[0], tracker.position()) < 0.01);
        assert.deepEqual(coordinates.at(-1), route.line.geometry.coordinates.at(-1));
        assert.ok(coordinates.every(([lng]) => lng >= tracker.position()[0]));
    });

    test('steps stop at the stairs until the floor is confirmed, then lead on to the room', () => {
        const { graph, route, maneuvers } = campusRoute('p1', 'r17');
        const tracker = new Tracker(route, maneuvers, { stride: 0.7, graph });
        const stairs = maneuvers.find((m) => m.type === 'stairs');
        for (let i = 0; i < 400 && !tracker.waitingAt; i++) tracker.step();
        assert.equal(tracker.waitingAt, stairs);
        assert.equal(tracker.progress, stairs.along);
        assert.equal(tracker.step().reason, 'on stairs');
        tracker.confirmFloor();
        assert.equal(tracker.waitingAt, null);
        // Upper floors: GPS is not used, steps carry on along the route.
        assert.equal(tracker.gps(position(route.nodes.at(-1).coord, { t: 1000 })).reason, 'upper floor');
        for (let i = 0; i < 50; i++) tracker.step();
        assert.ok(tracker.arrived);
    });

    test("I'm here jumps to the next maneuver, and the dot stays there while standing", () => {
        const { route, maneuvers, graph } = campusRoute('p1', 'r6');
        const tracker = new Tracker(route, maneuvers, { stride: 0.7, graph });
        tracker.imHere();
        assert.equal(tracker.progress, maneuvers[1].along);
        const there = tracker.position();
        tracker.gps(position(tracker.position(tracker.progress + 8), { speed: 0, t: 1000 }));
        assert.deepEqual(tracker.position(), there);
    });

    test('scanning a QR checkpoint mid-trip restarts exactly at that place, to the same destination', () => {
        const store = new Map();
        globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v) };
        try {
            const now = 1_000_000;
            rememberTrip('r17', now);
            assert.equal(recallTrip(now + 60 * 60 * 1000), 'r17');
            assert.equal(recallTrip(now + 3 * 60 * 60 * 1000), null, 'an old trip is not resumed');

            // The page reopens with ?from=<checkpoint>: the dot is on the checkpoint itself, nothing walked yet,
            // and GPS noise while standing there doesn't move it.
            const { graph, route, maneuvers } = campusRoute('r6', recallTrip(now + 1));
            const tracker = new Tracker(route, maneuvers, { stride: 0.7, graph, startHeading: { value: doorHeading(route), source: 'door' } });
            assert.deepEqual(tracker.position(), graph.nodes.get('r6').coord);
            tracker.gps(position(tracker.position(12), { speed: 0, t: 1000 }));
            assert.deepEqual(tracker.position(), graph.nodes.get('r6').coord);
            assert.deepEqual([tracker.progress, tracker.offRoute], [0, 0]);
            assert.equal(tracker.heading.source, 'door');

            forgetTrip();
            assert.equal(recallTrip(now + 1), null);
        } finally {
            delete globalThis.localStorage;
        }
    });
});

describe('voice directions setting', () => {
    const withStorage = (entries, run) => {
        const store = new Map(entries);
        globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, v), removeItem: (k) => store.delete(k) };
        globalThis.window = {}; // no speechSynthesis: Voice still tracks muted
        try {
            run(store);
        } finally {
            delete globalThis.localStorage;
            delete globalThis.window;
        }
    };

    test('off by default, and a browser that only had the old mute setting starts off too', () => {
        withStorage([], () => assert.equal(voiceDirectionsOn(), false));
        withStorage([['cnas.nav.muted', 'false']], (store) => {
            assert.equal(voiceDirectionsOn(), false);
            assert.equal(store.has('cnas.nav.muted'), false, 'the old key is removed');
        });
    });

    test('each trip starts from the setting; mute only changes the trip under way; the route to safety always speaks', () => {
        withStorage([], () => {
            const voice = new Voice();
            assert.equal(voice.muted, true);

            setVoiceDirections(true);
            voice.startTrip();
            assert.equal(voice.muted, false);
            voice.setMuted(true);
            assert.equal(voiceDirectionsOn(), true, 'muting a trip leaves the setting on');
            voice.startTrip();
            assert.equal(voice.muted, false, 'the next trip speaks again');

            setVoiceDirections(false);
            voice.startTrip();
            assert.equal(voice.muted, true);
            voice.startTrip({ alwaysOn: true });
            assert.equal(voice.muted, false);
        });
    });
});

describe('voice prompts', () => {
    test('far, then near, never repeated', () => {
        const { maneuvers } = campusRoute('p1', 'r6');
        const planner = createPromptPlanner(maneuvers);
        const spoken = [];
        for (let p = 0; p <= maneuvers.at(-1).along; p += 0.7) {
            for (let prompt; (prompt = planner.next(p)); ) spoken.push(prompt.key);
        }
        assert.equal(new Set(spoken).size, spoken.length);
        const turn = maneuvers.findIndex((m) => m.type === 'turn' && m.legBefore > 15);
        assert.ok(spoken.indexOf(`far:${turn}`) < spoken.indexOf(`near:${turn}`));
        assert.ok(spoken.includes(`near:${maneuvers.length - 1}`));
    });

    test('long straights are announced', () => {
        const maneuvers = [
            { type: 'depart', along: 0, now: 'Go' },
            { type: 'turn', along: 90, ahead: 'turn left', now: 'Turn left now', legBefore: 90 },
            { type: 'arrive', along: 100, ahead: 'you will arrive', now: 'Arrived', legBefore: 10 },
        ];
        const planner = createPromptPlanner(maneuvers);
        assert.deepEqual(planner.next(1), { key: 'straight:1', text: 'Walk straight for 90 meters' });
        assert.equal(planner.next(2), null);
        assert.deepEqual(planner.next(60), { key: 'far:1', text: 'In 30 meters, turn left' });
    });

    test('a turn is not announced when the GPS heading already points the new way', () => {
        const maneuvers = [
            { type: 'depart', along: 0, now: 'Go' },
            { type: 'turn', along: 30, ahead: 'turn left', now: 'Turn left now', legBefore: 30, bearingAfter: 270 },
            { type: 'arrive', along: 60, ahead: 'you will arrive', now: 'Arrived', legBefore: 30 },
        ];
        const cut = createPromptPlanner(maneuvers);
        assert.equal(cut.next(10, 275), null);
        assert.equal(cut.next(27, 275), null);
        assert.deepEqual(cut.next(55, 270), { key: 'near:2', text: 'Arrived' });
        const ahead = createPromptPlanner(maneuvers);
        assert.deepEqual(ahead.next(27, 0), { key: 'near:1', text: 'Turn left now' });
    });

    test('turn instructions carry the bearing walked after the turn', () => {
        const { maneuvers } = campusRoute('p1', 'r6');
        maneuvers.filter((m) => m.type === 'turn').forEach((m) => assert.ok(m.bearingAfter >= 0 && m.bearingAfter < 360));
    });
});

describe('GPS simulation (debug only)', () => {
    // A simulator whose fixes go straight into the tracker, as applyFix does in the page, one second apart.
    function simulated(tracker) {
        const clock = { t: 0 };
        const statuses = [];
        const sim = createSimulator({
            emit: (fix) => statuses.push(tracker.gps(fix)),
            now: () => (clock.t += 1000),
            caughtUp: (coord) => meters(tracker.filter.coord, coord) < CAUGHT_UP_METERS,
        });
        sim.place(tracker.position());
        const run = (ticks = 400) => {
            for (let i = 0; i < ticks && sim.busy; i++) sim.tick();
        };
        return { sim, statuses, run, clock };
    }

    test('a simulated fix looks like a phone fix: 5 m accuracy, a speed, no heading, a timestamp', () => {
        const fix = simulatedFix(at(3, 4), { speed: SIM_SPEED, t: 12345 });
        assert.deepEqual(fix.coords, { longitude: at(3, 4)[0], latitude: at(3, 4)[1], accuracy: 5, speed: 1.2, heading: null, altitude: null, altitudeAccuracy: null });
        assert.equal(fix.timestamp, 12345);
    });

    test('walking points are about 1.2 m apart and end at the target', () => {
        const points = pointsAlong([at(0, 0), at(10, 0), at(10, 5)], SIM_SPEED);
        points.slice(1).forEach((point, i) => assert.ok(meters(points[i], point) <= SIM_SPEED + 0.01, `gap ${meters(points[i], point)}`));
        assert.deepEqual(points.at(-1), at(10, 5));
        assert.equal(points.length, Math.ceil(15 / SIM_SPEED));
    });

    test('"Walk route" moves the dot along the route at walking speed to the destination, heading from movement', () => {
        const { tracker } = block();
        const { sim, statuses, run } = simulated(tracker);
        sim.walk(tracker.remainingLine().geometry.coordinates);
        assert.equal(sim.mode, 'walking');
        run();
        // ~61 m at 1.2 m/s, one fix a second, then a few slow fixes while the dot catches up.
        const walking = statuses.filter((s) => s.speed === SIM_SPEED).length;
        assert.ok(walking > 45 && walking < 55, `${walking} walking fixes`);
        assert.ok(statuses.every((s) => [SIM_SPEED, MOVING_SPEED, 0].includes(s.speed)));
        assert.ok(statuses.some((s) => s.used));
        assert.ok(tracker.arrived, `progress ${tracker.progress} of ${tracker.length}`);
        assert.equal(tracker.heading.source, 'gps');
        assert.ok(statuses.every((s) => !s.rerouteDue && !s.wrongWayStarted));
    });

    test('"Walk off route" walks straight to a tapped walkway and the tracker asks for a new route', () => {
        const { tracker } = block();
        const { sim, statuses, run } = simulated(tracker);
        sim.walkTo(at(20, 0));
        run();
        sim.walkTo(at(20, 28));
        run();
        assert.ok(statuses.some((s) => s.rerouteDue));
        assert.ok(angleBetween(tracker.heading.value, 0) < 10, `heading ${tracker.heading.value}`);
    });

    test('walking back along the route gives the wrong-way warning', () => {
        const { tracker } = block();
        const { sim, statuses, run } = simulated(tracker);
        sim.walkTo(at(25, 0));
        run();
        sim.walkTo(at(8, 0));
        run();
        assert.equal(statuses.filter((s) => s.wrongWayStarted).length, 1);
        assert.equal(tracker.wrongWay, true);
    });

    test('a tapped position: the dot goes there through the filter, then the fake GPS stands still', () => {
        const { tracker } = block();
        const { sim, statuses, run } = simulated(tracker);
        sim.place(at(12, 1));
        assert.equal(sim.mode, 'catching up');
        run(30);
        assert.equal(sim.mode, 'standing');
        const [east, north] = offset(tracker.position());
        assert.ok(Math.abs(east - 12) < 1.5 && Math.abs(north) < 0.01, `dot at ${east}, ${north}`);
        // Standing: fixes keep coming with speed 0 and the dot stays put.
        const there = tracker.position();
        for (let i = 0; i < 5; i++) sim.tick();
        assert.deepEqual(statuses.slice(-5).map((s) => [s.speed, s.reason]), Array(5).fill([0, 'standing still']));
        assert.deepEqual(tracker.position(), there);
    });

    test('stop() ends a walk where it is', () => {
        const { tracker } = block();
        const { sim } = simulated(tracker);
        sim.walkTo(at(30, 0));
        sim.tick();
        sim.tick();
        sim.stop();
        assert.equal(sim.mode, 'standing');
        assert.ok(Math.abs(offset(sim.coord)[0] - 2 * SIM_SPEED) < 0.05);
    });
});

describe('floors', () => {
    const corridor = (id, coordinates, floor) => ({ ...line(id, coordinates), properties: { id, name: id, type: 'walkway', floor } });
    const stairs = (id, center, floors, buildingId = 'b1') => ({
        type: 'Feature',
        properties: { id, name: `Stair ${id}`, building_id: buildingId, floors, center },
        geometry: { type: 'Polygon', coordinates: [[center, center, center, center]] },
    });
    const linkedTo = (graph, id) =>
        [...graph.adjacency.get(id)]
            .map((edgeId) => graph.edges.get(edgeId))
            .filter((edge) => edge.type === 'link')
            .map((edge) => graph.nodes.get(edge.a === id ? edge.b : edge.a));

    // Building b1: a ground walkway running east, stairs 4 m south of its west end and (unless left out) a 2nd-floor
    // corridor exactly above the walkway. Room 1 is on the ground floor north of the walkway; Room 2 is upstairs,
    // 3 m south of the corridor.
    function twoFloors({ withCorridor = true, warn = () => {} } = {}) {
        return buildGraph(
            {
                paths: collection([line('a', [at(0, 0), at(40, 0)]), ...(withCorridor ? [corridor('c', [at(0, 0), at(40, 0)], 2)] : [])]),
                entrances: collection([door('r1', 'Room 1', at(10, 3)), door('r2', 'Room 2', at(30, -3), 2)]),
                stairs: collection([stairs('s0', at(0, -4), 2)]),
            },
            { warn },
        );
    }

    // One floor at a time on /navigate: the route is split into legs at each stairs step.
    const legsOf = (graph, from, to, destinationName) => {
        const route = findRoute(graph, from, to);
        const maneuvers = buildManeuvers(route, graph, { destinationName });
        return { route, maneuvers, legs: routeLegs(route, maneuvers, { destinationName }) };
    };

    test('the pin is on the stairs until the floor is confirmed, then on the room', () => {
        const graph = twoFloors();
        const { route, maneuvers, legs } = legsOf(graph, 'r1', 'r2', 'Room 2');
        const tracker = new Tracker(route, maneuvers, { stride: 0.7, graph });

        assert.equal(legs.length, 2);
        let leg = currentLeg(legs, tracker);
        assert.deepEqual([leg.floor, leg.target.kind, leg.target.coord], [1, 'stairs', at(0, -4)]);
        assert.equal(legIntro(legs, leg), 'Go to the stairs near Room 1, then Room 2 is on the 2nd floor');
        assert.equal(legLabel(legs, leg), 'to the stairs near Room 1 · then Room 2, 2nd floor');
        // Only the way to the stairs is drawn: it ends on the stairs.
        const drawn = legLine(route, leg, tracker.progress).geometry.coordinates;
        assert.deepEqual(drawn.at(-1), at(0, -4));
        assert.ok(drawn.every(([lng]) => lng <= at(10, 0)[0] + 1e-12), 'nothing east of Room 1, where the corridor goes');

        for (let i = 0; i < 100 && !tracker.waitingAt; i++) tracker.step();
        assert.equal(currentLeg(legs, tracker), leg, 'still on the stairs pin while waiting at the stairs');

        tracker.confirmFloor();
        leg = currentLeg(legs, tracker);
        assert.deepEqual([leg.floor, leg.target.kind, leg.target.coord], [2, 'destination', at(30, -3)]);
        assert.equal(legIntro(legs, leg), null);
        assert.equal(legLabel(legs, leg), 'to Room 2');
        const upstairs = legLine(route, leg, tracker.progress).geometry.coordinates;
        assert.deepEqual([upstairs[0], upstairs.at(-1)], [at(0, -4), at(30, -3)]);
    });

    test('a route down then up again has a leg per stairs step', () => {
        // Building b1 (Room 1 below, Room 2 upstairs, stairs s0) and building b2 80 m east (Room 3 below, Room 4
        // upstairs, stairs s1), each with its own 2nd-floor corridor.
        const graph = buildGraph({
            paths: collection([line('a', [at(0, 0), at(80, 0)]), corridor('c1', [at(0, 0), at(20, 0)], 2), corridor('c2', [at(60, 0), at(80, 0)], 2)]),
            entrances: collection([
                door('r1', 'Room 1', at(5, 3)),
                door('r2', 'Room 2', at(15, -3), 2),
                door('r3', 'Room 3', at(75, 3), 1, 'b2'),
                door('r4', 'Room 4', at(65, -3), 2, 'b2'),
            ]),
            stairs: collection([stairs('s0', at(0, -4), 2), stairs('s1', at(80, -4), 2, 'b2')]),
        });
        const { legs } = legsOf(graph, 'r2', 'r4', 'Room 4');
        assert.deepEqual(
            legs.map((leg) => [leg.floor, leg.target.kind, leg.target.coord]),
            [[2, 'stairs', at(0, -4)], [1, 'stairs', at(80, -4)], [2, 'destination', at(65, -3)]],
        );
        assert.deepEqual(legs.map((leg) => legIntro(legs, leg)), [
            'Go to the stairs near Room 1, then Room 4 is on the 2nd floor',
            'Go to the stairs near Room 3, then Room 4 is on the 2nd floor',
            null,
        ]);
        assert.deepEqual(legs.map((leg) => leg.stairs?.modifier ?? null), ['down', 'up', null]);
    });

    test('going down: the pin is on the stairs first, then on the destination below', () => {
        const graph = twoFloors();
        const { route, maneuvers, legs } = legsOf(graph, 'r2', 'r1', 'Room 1');
        const tracker = new Tracker(route, maneuvers, { stride: 0.7, graph });
        assert.deepEqual(legs.map((leg) => [leg.floor, leg.target.kind]), [[2, 'stairs'], [1, 'destination']]);
        assert.equal(legIntro(legs, legs[0]), 'Go to the stairs near Room 1, then Room 1 is on the 1st floor');
        for (let i = 0; i < 100 && !tracker.waitingAt; i++) tracker.step();
        assert.equal(currentLeg(legs, tracker), legs[0]);
        tracker.confirmFloor();
        assert.deepEqual(currentLeg(legs, tracker).target.coord, at(10, 3));
    });

    test('a ground-floor route is one leg: pin on the destination, the whole line, no extra sentence', () => {
        const graph = twoFloors();
        const { route, legs } = legsOf(graph, 'r1', 'a:1', 'the east end');
        assert.equal(legs.length, 1);
        assert.deepEqual([legs[0].floor, legs[0].target.kind, legs[0].target.coord], [1, 'destination', at(40, 0)]);
        assert.equal(legIntro(legs, legs[0]), null);
        assert.equal(legLabel(legs, legs[0]), 'to the east end');
        assert.deepEqual(legLine(route, legs[0], 0).geometry.coordinates, route.line.geometry.coordinates);
        assert.deepEqual(floorParts(route), { ground: [route.line.geometry.coordinates], upper: [] });
    });

    test('the preview splits the line into the ground part and the upstairs part', () => {
        const graph = twoFloors();
        const { route } = legsOf(graph, 'r1', 'r2', 'Room 2');
        const { ground, upper } = floorParts(route);
        assert.equal(ground.length, 1);
        assert.equal(upper.length, 1);
        assert.deepEqual([ground[0][0], ground[0].at(-1)], [at(10, 3), at(0, -4)]);
        assert.deepEqual([upper[0][0], upper[0].at(-1)], [at(0, -4), at(30, -3)]);
    });

    test('a 2nd-floor room is reached up the stairs and along the 2nd-floor corridor', () => {
        const graph = twoFloors();
        const route = findRoute(graph, 'r1', 'r2');
        const upstairs = route.nodes.slice(route.nodes.findIndex((n) => n.id === 's0:f2'));
        assert.ok(upstairs.length > 2 && upstairs.every((n) => n.floor === 2), upstairs.map((n) => `${n.id}@${n.floor}`).join(' '));
        assert.ok(upstairs.some((n) => n.kind === 'path'), 'walks along the corridor');
        assert.ok(!linkedTo(graph, 'r2').some((n) => n.kind === 'stair'), 'no straight line from the stairs to the door');
        // Room 1 -> walkway -> stairs (4 m) -> up -> back to the corridor (4 m) -> 30 m east -> door (3 m).
        assert.ok(Math.abs(route.length - (3 + 10 + 4 + 4 + 30 + 3)) < 1, `length ${route.length}`);

        const maneuvers = buildManeuvers(route, graph, { destinationName: 'Room 2' });
        const up = maneuvers.findIndex((m) => m.type === 'stairs');
        assert.match(maneuvers[up].text, /^Go up the stairs near Room 1 to the 2nd floor$/);
        assert.match(maneuvers[up + 1].text, /^Turn right/, 'out of the stairs and east along the corridor');
        assert.equal(maneuvers.at(-1).text, 'You have arrived. Room 2 is on your right');
    });

    test('a turn less than 3 m before or after the stairs joins the stairs step', () => {
        // Walkway and corridor run east to (20,0); the stairs are 2 m north of that end. Room 1 is downstairs and Room 2
        // upstairs, both near the west end.
        const graph = buildGraph({
            paths: collection([line('a', [at(0, 0), at(20, 0)]), corridor('c', [at(0, 0), at(20, 0)], 2)]),
            entrances: collection([door('r1', 'Room 1', at(5, 3)), door('r2', 'Room 2', at(5, -3), 2)]),
            stairs: collection([stairs('s0', at(20, 2), 2)]),
        });
        const maneuvers = buildManeuvers(findRoute(graph, 'r1', 'r2'), graph, { destinationName: 'Room 2' });
        const up = maneuvers.find((m) => m.type === 'stairs');
        assert.equal(up.text, 'Turn left and go up the stairs near Room 1 to the 2nd floor, then turn right');
        assert.equal(up.now, up.text);
        assert.ok(maneuvers.every((m, i) => i === 0 || m.legBefore >= 3), maneuvers.map((m) => `${m.legBefore.toFixed(1)} ${m.text}`).join(' | '));
        assert.equal(maneuvers.at(-1).text, 'You have arrived. Room 2 is on your left');
    });

    test('turns along an upstairs corridor name no building: the person is inside one', () => {
        // The corridor runs east, north, then east again; the building around it is named in landmarks.
        const graph = buildGraph({
            paths: collection([line('a', [at(0, 0), at(40, 0)]), corridor('c', [at(0, 0), at(20, 0), at(20, 20), at(40, 20)], 2)]),
            entrances: collection([door('r1', 'Room 1', at(10, 3)), door('r2', 'Room 2', at(35, 23), 2)]),
            stairs: collection([stairs('s0', at(0, -4), 2)]),
        });
        const building = {
            type: 'Feature',
            properties: { id: 'b1', type: 'building' },
            geometry: { type: 'Polygon', coordinates: [[at(-5, -10), at(45, -10), at(45, 30), at(-5, 30), at(-5, -10)]] },
        };
        const maneuvers = buildManeuvers(findRoute(graph, 'r1', 'r2'), graph, {
            buildings: collection([building]),
            landmarks: { buildings: { b1: 'the NEE Building' } },
            destinationName: 'Room 2',
        });
        const upstairs = maneuvers.slice(maneuvers.findIndex((m) => m.type === 'stairs') + 1).filter((m) => m.type === 'turn');
        assert.deepEqual(upstairs.map((m) => m.text), ['Turn right', 'Turn left', 'Turn right toward Room 2']);
    });

    test('a route from an upstairs room starts with the way out of its door', () => {
        const graph = twoFloors();
        const maneuvers = buildManeuvers(findRoute(graph, 'r2', 'r1'), graph);
        // The door is south of the corridor, so stepping out faces north; the stairs are west.
        assert.equal(maneuvers[0].text, 'Turn left toward the stairs near Room 1');
    });

    test('floors are joined only by stairs, even where a corridor lies exactly above a walkway', () => {
        const graph = twoFloors();
        for (const edge of graph.edges.values()) {
            const [a, b] = [graph.nodes.get(edge.a), graph.nodes.get(edge.b)];
            if (edge.type !== 'stairs') assert.equal(a.floor, b.floor, `${edge.type} ${a.id} (floor ${a.floor}) - ${b.id} (floor ${b.floor})`);
        }
        assert.ok(graph.nodes.has('a:0') && graph.nodes.has('c:0'), 'the corridor keeps its own points');
        // Ground-floor lookups (GPS, "My location", rerouting) never land on the corridor above.
        assert.equal(graph.nodes.get(attachPoint(graph, at(20, 0.5)).nodeId).floor, 1);
        const snapped = snapToWalkway(graph, at(20, 0.5));
        assert.equal(graph.nodes.get(graph.edges.get(snapped.edgeId).a).floor, 1);
    });

    test('a floor with no corridor keeps the straight line from the stairs, with a warning', () => {
        const warnings = [];
        const graph = twoFloors({ withCorridor: false, warn: (message) => warnings.push(message) });
        const route = findRoute(graph, 'r1', 'r2');
        assert.deepEqual(route.nodes.slice(-2).map((n) => n.id), ['s0:f2', 'r2']);
        assert.equal(warnings.length, 1);
        assert.match(warnings[0], /floor 2 of b1/);
    });

    test("a room links to its own building's corridor, not a nearer one next door", () => {
        const graph = buildGraph(
            {
                paths: collection([line('a', [at(0, 0), at(80, 0)]), corridor('c1', [at(0, 0), at(20, 0)], 2), corridor('c2', [at(26, 0), at(80, 0)], 2)]),
                entrances: collection([door('r2', 'Room 2', at(24, -3), 2, 'b1')]),
                stairs: collection([stairs('s0', at(0, -4), 2, 'b1'), stairs('s1', at(80, -4), 2, 'b2')]),
            },
            { warn: () => {} },
        );
        assert.deepEqual(linkedTo(graph, 'r2').map((n) => [...n.paths]), [['c1']]);
    });

    test('stairs link to each walkway within 6 m, so routes cross the landing instead of going round the corner', () => {
        // Walkway p runs east to a corner at (20,0) and q runs north from it; the stairs sit inside the corner, 4 m from
        // both. A third walkway 8 m away is not linked.
        const graph = buildGraph({
            paths: collection([line('p', [at(0, 0), at(20, 0)]), line('q', [at(20, 0), at(20, 30)]), line('far', [at(0, 12), at(10, 12)])]),
            entrances: collection([]),
            stairs: collection([stairs('s0', at(16, 4), 1)]),
        });
        assert.deepEqual(linkedTo(graph, 's0:f1').flatMap((n) => [...n.paths]).sort(), ['p', 'q']);
        const route = findRoute(graph, 'q:1', 's0:f1');
        assert.ok(route.nodes.every((n) => !sameCoord(n.coord, at(20, 0))), 'does not go round the corner');
        assert.ok(Math.abs(route.length - 30) < 0.5, `length ${route.length}`);
    });
});

describe('campus upper floors (the real map files)', () => {
    // Ground stair landings are a few meters from the walkway (NEE Stair A 5.4 m, Stair B 5.9 m); upstairs every link
    // is short. The straight fallback lines these replace were 10-26 m.
    const GROUND_LINK_MAX = 6;
    const UPPER_LINK_MAX = 5;
    const linkedTo = (graph, id) =>
        [...graph.adjacency.get(id)]
            .map((edgeId) => graph.edges.get(edgeId))
            .filter((edge) => edge.type === 'link')
            .map((edge) => graph.nodes.get(edge.a === id ? edge.b : edge.a));

    test('every upstairs room reaches a corridor: no straight-line fallback', () => {
        const warnings = [];
        buildGraph(campus, { warn: (message) => warnings.push(message) });
        assert.deepEqual(warnings, []);
    });

    test('going down from any upstairs room never says "turn around" at the stairs', () => {
        const graph = buildGraph(campus);
        const places = listPlaces(graph, campus.paths);
        const gate = places.find((p) => p.name === 'Entrance Gate');
        for (const room of places.filter((p) => p.kind === 'room' && p.floor > 1)) {
            const maneuvers = buildManeuvers(findRoute(graph, room.nodeId, gate.nodeId), graph, { landmarks: campus.landmarks, buildings: campus.buildings });
            const down = maneuvers.find((m) => m.type === 'stairs');
            assert.match(down.text, /^(Turn (left|right) and g|G)o down the stairs near NEE 10\d to the 1st floor(, then turn (left|right))?$/, room.name);
            assert.ok(maneuvers.every((m) => m.type !== 'turn' || m.modifier !== 'uturn'), `${room.name}: ${maneuvers.map((m) => m.text).join(' | ')}`);
        }
    });

    test('both NEE stairs reach the corridor on floors 2 and 3', () => {
        const graph = buildGraph(campus);
        for (const id of ['s0:f2', 's1:f2', 's0:f3', 's1:f3']) {
            const floor = graph.nodes.get(id).floor;
            assert.ok(linkedTo(graph, id).some((n) => n.kind === 'path' && n.floor === floor), id);
        }
    });

    test('from the Entrance Gate every upstairs room is up the stairs, along the corridor, then the door', () => {
        const graph = buildGraph(campus);
        const places = listPlaces(graph, campus.paths);
        const gate = places.find((p) => p.name === 'Entrance Gate');
        const upstairs = places.filter((p) => p.kind === 'room' && p.floor > 1);
        assert.equal(upstairs.length, 8);

        for (const room of upstairs) {
            const route = findRoute(graph, gate.nodeId, room.nodeId);
            const last = route.edges.findLastIndex((e) => e.type === 'stairs');
            const after = route.edges.slice(last + 1);
            const corridor = after.slice(1, -1);
            assert.ok(
                after[0].type === 'link' && after.at(-1).type === 'link' && corridor.length > 0 && corridor.every((e) => e.type === 'walkway'),
                `${room.name}: ${after.map((e) => e.type).join(', ')}`,
            );
            assert.ok(route.nodes.slice(last + 1).every((n) => n.floor === room.floor), `${room.name} stays on floor ${room.floor}`);

            route.edges.forEach((edge, i) => {
                if (edge.type !== 'link') return;
                const [a, b] = [route.nodes[i], route.nodes[i + 1]];
                const limit = a.floor === 1 && b.floor === 1 ? GROUND_LINK_MAX : UPPER_LINK_MAX;
                const length = meters(a.coord, b.coord);
                assert.ok(length <= limit, `${room.name}: link ${a.id} -> ${b.id} is ${length.toFixed(1)} m`);
            });
        }
    });
});
