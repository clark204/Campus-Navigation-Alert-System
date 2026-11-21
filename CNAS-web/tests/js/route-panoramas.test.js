import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { meters } from '../../resources/js/navigation/geo.js';
import {
    createRouteGuide,
    debugLines,
    offeredSpot,
    OFFER_AFTER_METERS,
    OFFER_BEFORE_METERS,
    ROUTE_PHOTO_METERS,
    routeDirection,
    routeSpots,
} from '../../resources/js/navigation/route-panoramas.js';
import { offset } from '../../resources/js/panoramas.js';

const START = [124.7482424, 8.5412066];
const TURN = offset(START, 90, 60);
const END = offset(TURN, 0, 40);
const UPSTAIRS = offset(TURN, 90, 20);

// A route as findRoute makes it, from [coord, floor] pairs.
function routeOf(stops) {
    const nodes = stops.map(([coord, floor = 1], i) => ({ id: `n${i}`, coord, floor }));
    const along = [0];
    for (let i = 1; i < nodes.length; i++) along.push(along[i - 1] + meters(nodes[i - 1].coord, nodes[i].coord));
    return { nodes, along, length: along.at(-1), line: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates: nodes.map((n) => n.coord) } } };
}

// 60 m east, then turn left and 40 m north to the door of NEE 202 (on the right).
const route = routeOf([[START], [TURN], [END]]);
const maneuvers = [
    { type: 'depart', along: 0, text: 'Walk toward NEE' },
    { type: 'turn', modifier: 'left', along: 60, bearingAfter: 0, text: 'Turn left near the Registrar' },
    { type: 'arrive', modifier: 'on your right', along: 100, text: 'You have arrived. NEE 202 is on your right' },
];
const at = (from, bearing, distance, sideways = 0) => offset(offset(from, bearing, distance), bearing + 90, sideways);
const photo = (id, coord, extra = {}) => ({ id, name: `Photo ${id}`, floor: 1, northOffset: 0, coord, thumb: `/t/${id}.jpg`, ...extra });

const photos = [
    photo('pn3', at(TURN, 0, 20, 3)),
    photo('pn1', at(START, 90, 10, -2)),
    photo('pn2', at(START, 90, 55, 1)),
    photo('pn4', at(START, 90, 30, 30)),
];

describe('photos on the route', () => {
    test('photos within reach of the route are listed in route order with their distance along it', () => {
        const { spots, skipped } = routeSpots(route, photos, { maneuvers, destinationName: 'NEE 202' });

        assert.deepEqual(spots.map((s) => s.id), ['pn1', 'pn2', 'pn3']);
        assert.deepEqual(spots.map((s) => Math.round(s.along)), [10, 55, 80]);
        assert.deepEqual(skipped.map((s) => [s.id, s.reason]), [['pn4', 'too far from the route (30 m)']]);
    });

    test(`a photo ${ROUTE_PHOTO_METERS} m from the route is on it, a little further is not`, () => {
        const edge = [photo('in', at(START, 90, 20, ROUTE_PHOTO_METERS - 0.2)), photo('out', at(START, 90, 20, ROUTE_PHOTO_METERS + 0.5))];
        const { spots, skipped } = routeSpots(route, edge, { maneuvers });
        assert.deepEqual(spots.map((s) => s.id), ['in']);
        assert.equal(skipped[0].id, 'out');
    });

    test('hidden photos, photos without north and photos on another floor are skipped with the reason', () => {
        const others = [
            photo('pn5', at(START, 90, 20), { isVisible: false }),
            photo('pn6', at(START, 90, 25), { isVisible: false, northOffset: null }),
            photo('pn7', at(START, 90, 30), { northOffset: null }),
            photo('pn8', at(START, 90, 35), { floor: 2 }),
        ];
        const { spots, skipped } = routeSpots(route, others, { maneuvers });

        assert.deepEqual(spots, []);
        assert.deepEqual(skipped.map((s) => [s.id, s.reason]), [
            ['pn5', 'hidden'],
            ['pn6', 'hidden, no north'],
            ['pn7', 'no north'],
            ['pn8', 'the route is not on floor 2'],
        ]);
    });

    test('an upstairs photo is on the route only along the upstairs part', () => {
        const climb = routeOf([[START], [TURN], [TURN, 2], [UPSTAIRS, 2]]);
        const above = [photo('up', at(TURN, 90, 10), { floor: 2 }), photo('ground', at(TURN, 90, 10))];
        const { spots, skipped } = routeSpots(climb, above, { maneuvers: [] });

        assert.deepEqual(spots.map((s) => [s.id, Math.round(s.along)]), [['up', 70]]);
        assert.equal(skipped[0].id, 'ground');
    });
});

describe('which way a photo opens', () => {
    test('on a straight part it faces along the route', () => {
        assert.equal(Math.round(routeDirection(route, maneuvers, 10)), 90);
        assert.equal(Math.round(routeDirection(route, maneuvers, 80)), 0);
    });

    test('near a turn it faces the way the route goes after the turn', () => {
        assert.equal(Math.round(routeDirection(route, maneuvers, 55)), 0);
        assert.equal(Math.round(routeDirection(route, maneuvers, 60)), 0);
    });

    test('a turn further ahead does not change it yet', () => {
        assert.equal(Math.round(routeDirection(route, maneuvers, 40)), 90);
    });

    test('photo spots carry that direction and what comes next', () => {
        const { spots } = routeSpots(route, photos, { maneuvers, destinationName: 'NEE 202' });
        assert.deepEqual(spots.map((s) => Math.round(s.bearing)), [90, 0, 0]);
        assert.deepEqual(spots.map((s) => s.text), ['Turn left near the Registrar', 'Turn left near the Registrar', 'You have arrived. NEE 202 is on your right']);
    });

    test('the last photo before the destination says where it is', () => {
        const { spots } = routeSpots(route, photos, { maneuvers, destinationName: 'NEE 202' });
        assert.deepEqual(spots.map((s) => s.caption), [null, null, 'NEE 202 is ahead on your right']);
    });

    test('the last photo before stairs says to take them, and one far before the destination says nothing', () => {
        const climb = routeOf([[START], [TURN], [TURN, 2], [UPSTAIRS, 2]]);
        const steps = [
            { type: 'depart', along: 0, text: 'Walk toward the stairs' },
            { type: 'stairs', modifier: 'up', along: 60, toFloor: 2, text: 'Go up the stairs near NEE to the 2nd floor' },
            { type: 'arrive', modifier: '', along: 80, text: 'You have arrived at NEE 202' },
        ];
        const ground = [photo('a', at(START, 90, 5)), photo('b', at(START, 90, 50))];
        const { spots } = routeSpots(climb, ground, { maneuvers: steps, destinationName: 'NEE 202' });
        assert.deepEqual(spots.map((s) => s.caption), [null, 'Take these stairs up to the 2nd floor']);
        assert.equal(Math.round(spots[1].bearing), 90);

        const far = routeSpots(route, [photo('a', at(START, 90, 5))], { maneuvers, destinationName: 'NEE 202' });
        assert.equal(far.spots[0].caption, null);
    });
});

describe('the "See this spot" offer', () => {
    const { spots } = routeSpots(route, photos, { maneuvers, destinationName: 'NEE 202' });

    test(`a photo is offered from ${OFFER_BEFORE_METERS} m before it until ${OFFER_AFTER_METERS} m past it`, () => {
        const offer = (progress) => offeredSpot(spots, { progress, next: maneuvers[1] })?.id ?? null;
        const [pn1, pn2] = spots.map((s) => s.along);
        assert.equal(offer(pn1 - OFFER_BEFORE_METERS - 0.1), null);
        assert.equal(offer(pn1 - OFFER_BEFORE_METERS), 'pn1');
        assert.equal(offer(pn1 + OFFER_AFTER_METERS), 'pn1');
        assert.equal(offer(pn1 + OFFER_AFTER_METERS + 0.1), null);
        assert.equal(offer(pn2 - OFFER_BEFORE_METERS - 0.1), null);
        assert.equal(offer(pn2 - OFFER_BEFORE_METERS), 'pn2');
        assert.equal(offer(70), 'pn3');
        assert.equal(offer(90), null);
    });

    test('in manual mode it follows the current step: the next photo before the step ends', () => {
        const offer = (progress, next) => offeredSpot(spots, { progress, next, manual: true })?.id ?? null;
        // At the start, walking to the turn 60 m on: pn1, then (after it) pn2, though it is more than 30 m away.
        assert.equal(offer(0, maneuvers[1]), 'pn1');
        assert.equal(offer(16, maneuvers[1]), 'pn2');
        // "Next" at the turn: the step is now the walk to the door, with pn3 on it.
        assert.equal(offer(60, maneuvers[2]), 'pn3');
        // A photo past the current step is not offered yet.
        assert.equal(offeredSpot([{ id: 'late', along: 70 }], { progress: 0, next: maneuvers[1], manual: true }), null);
    });

    test('a route with no photos offers nothing', () => {
        assert.equal(offeredSpot([], { progress: 0, next: maneuvers[1] }), null);
    });
});

describe('the route guide', () => {
    test('rerouting recomputes the photos on the route', () => {
        const guide = createRouteGuide(photos);
        guide.setRoute(route, maneuvers, 'NEE 202');
        assert.deepEqual(guide.spots.map((s) => s.id), ['pn1', 'pn2', 'pn3']);
        assert.equal(guide.offer({ progress: 30, next: maneuvers[1] }).id, 'pn2');

        // From pn4's side of the campus, straight north to the destination: only pn4 is on it now.
        const detour = routeOf([[offset(START, 90, 30)], [at(START, 90, 30, 30)]]);
        guide.setRoute(detour, [{ type: 'arrive', along: detour.length, modifier: '', text: 'You have arrived at NEE 202' }], 'NEE 202');
        assert.deepEqual(guide.spots.map((s) => s.id), ['pn4']);
        assert.equal(guide.offer({ progress: 5, next: { along: detour.length } }).id, 'pn4');
        assert.equal(guide.spot('pn2'), null);
    });

    test('the next spot follows route order, and the last one has none', () => {
        const guide = createRouteGuide(photos);
        guide.setRoute(route, maneuvers, 'NEE 202');
        assert.equal(guide.nextAfter('pn1')?.id, 'pn2');
        assert.equal(guide.nextAfter('pn3'), null);
        assert.equal(guide.nextAfter('pn4'), null);
    });

    test('debug lists every photo checked, and says so when all were skipped', () => {
        const guide = createRouteGuide(photos);
        guide.setRoute(route, maneuvers, 'NEE 202');
        assert.deepEqual(debugLines(guide.result), ['pn1 at 10 m', 'pn2 at 55 m', 'pn3 at 80 m', 'skipped pn4: too far from the route (30 m)']);

        const none = createRouteGuide([photo('pn4', at(START, 90, 30, 30)), photo('pn5', START, { isVisible: false })]);
        none.setRoute(route, maneuvers, 'NEE 202');
        assert.deepEqual(debugLines(none.result), [
            'All 2 photos were skipped, so none are on this route.',
            'skipped pn4: too far from the route (30 m)',
            'skipped pn5: hidden',
        ]);
        assert.deepEqual(debugLines(createRouteGuide([]).result), ['no photos']);
    });
});
