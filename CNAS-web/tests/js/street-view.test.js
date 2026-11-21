import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { meters, projectOnSegment } from '../../resources/js/navigation/geo.js';
import { buildGraph } from '../../resources/js/navigation/graph.js';
import {
    ALONE_METERS,
    alongPathFacing,
    coverageLines,
    JOIN_METERS,
    mapPadding,
    mapTapAction,
    photoAtTap,
    SNAP_METERS,
    streetViewAvailable,
    streetViewCoverage,
} from '../../resources/js/navigation/street-view.js';
import { offset } from '../../resources/js/panoramas.js';

const START = [124.7482424, 8.5412066];
const east = (m, north = 0) => offset(offset(START, 90, m), 0, north);
const line = (id, coordinates, floor = 1) => ({ type: 'Feature', properties: { id, name: id, type: 'walkway', floor }, geometry: { type: 'LineString', coordinates } });
const collection = (features) => ({ type: 'FeatureCollection', features });
// Walkway "a" runs 100 m east; "b" goes 60 m north from its middle (a T junction at 50 m); a corridor upstairs runs
// above "a" from 0 to 30 m.
const graph = buildGraph({
    paths: collection([
        line('a', [START, east(100)]),
        line('b', [east(50), east(50, 60)]),
        line('c', [east(0, 1), east(30, 1)], 2),
    ]),
    entrances: collection([]),
});
const photo = (id, coord, extra = {}) => ({ id, name: id, floor: 1, northOffset: 0, coord, ...extra });
// Whether a spot is on a highlighted stretch (within 1 m of one).
const lit = (coverage, coord, floor = 1) => photoAtTap(coverage, coord, { toleranceMeters: 1, floor }) !== null;

describe('street view: which walkway stretches light up', () => {
    const total = (coverage) => coverageLines(coverage, 1).features.reduce((sum, f) => sum + f.properties.meters, 0);

    test('the walkway between neighbouring photos lights up, and nothing past the first and last photo', () => {
        const coverage = streetViewCoverage(graph, [photo('pn1', east(10, 2)), photo('pn2', east(22)), photo('pn3', east(35))]);

        for (const m of [10, 16, 22, 30, 35]) assert.equal(lit(coverage, east(m)), true, `${m} m`);
        assert.equal(lit(coverage, east(7)), false);
        assert.equal(lit(coverage, east(38)), false);
        // One unbroken line, 25 m long.
        assert.equal(coverageLines(coverage, 1).features.length, 1);
        assert.ok(Math.abs(total(coverage) - 25) < 0.5);
    });

    test(`photos more than ${JOIN_METERS} m apart along the walkways are not joined`, () => {
        const coverage = streetViewCoverage(graph, [photo('pn1', east(10)), photo('pn2', east(10 + JOIN_METERS + 5))]);
        assert.equal(lit(coverage, east(25)), false);
    });

    test(`a photo with no neighbour lights ${ALONE_METERS} m each way, so there is something to tap`, () => {
        const coverage = streetViewCoverage(graph, [photo('pn1', east(20))]);
        assert.equal(lit(coverage, east(20)), true);
        assert.equal(lit(coverage, east(20 - ALONE_METERS + 1)), true);
        assert.equal(lit(coverage, east(20 + ALONE_METERS + 2)), false);
        assert.ok(Math.abs(total(coverage) - 2 * ALONE_METERS) < 0.5);
    });

    test('a side walkway with no photo on it stays dark', () => {
        // Photos on "a" either side of the junction with "b".
        const coverage = streetViewCoverage(graph, [photo('pn1', east(45)), photo('pn2', east(56))]);
        assert.equal(lit(coverage, east(50)), true);
        assert.equal(lit(coverage, east(50, 3)), false);
        assert.equal(lit(coverage, east(60)), false);
    });

    test('round a corner, the way to a photo on the next walkway lights up', () => {
        const coverage = streetViewCoverage(graph, [photo('pn1', east(45)), photo('pn2', east(50, 10))]);
        assert.equal(lit(coverage, east(48)), true);
        assert.equal(lit(coverage, east(50, 5)), true);
        // Not on past the junction along "a", nor past the photo on "b".
        assert.equal(lit(coverage, east(54)), false);
        assert.equal(lit(coverage, east(50, 13)), false);
    });

    test(`a photo more than ${SNAP_METERS} m from every walkway lights nothing`, () => {
        const coverage = streetViewCoverage(graph, [photo('pn1', east(80, SNAP_METERS + 4))]);
        assert.equal(coverageLines(coverage, 1).features.length, 0);
        assert.deepEqual(coverage.unsnapped, ['pn1']);
    });

    test('an upstairs photo lights its corridor, not the walkway below', () => {
        const coverage = streetViewCoverage(graph, [photo('up', east(10, 1), { floor: 2 })]);
        assert.equal(lit(coverage, east(10, 1), 2), true);
        assert.equal(lit(coverage, east(10), 1), false);
        assert.equal(coverageLines(coverage, 1).features.length, 0);
    });
});

describe('street view: the highlight follows the walkway as it is drawn', () => {
    // "n" is drawn ending 1.5 m short of "m"; joining the two moves that end onto "m" in the graph,
    // so the graph's line for "n" slants away from the drawn one.
    const paths = collection([line('m', [START, east(20), east(40)]), line('n', [east(21.5, 1.5), east(21.5, 15), east(30, 30)])]);
    const joined = buildGraph({ paths, entrances: collection([]) });
    // One photo on each side of the bend in "n".
    const coverage = streetViewCoverage(joined, [photo('pn1', east(21.5, 2)), photo('pn2', east(25.75, 22.5))], { paths });
    const offDrawn = (coord) =>
        Math.min(...paths.features.flatMap((f) => f.geometry.coordinates.slice(1).map((c, i) => projectOnSegment(coord, f.geometry.coordinates[i], c).distance)));

    test('every lit line lies on a drawn walkway, round bends too', () => {
        const lines = coverageLines(coverage, 1).features;
        assert.ok(lines.length > 0);
        // Checked at the ends of each straight piece and at points along it.
        const mix = (a, b, t) => [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
        for (const { geometry } of lines) {
            geometry.coordinates.slice(1).forEach((end, i) => {
                for (const t of [0, 0.25, 0.5, 0.75, 1]) {
                    const off = offDrawn(mix(geometry.coordinates[i], end, t));
                    assert.ok(off < 0.05, `${off.toFixed(2)} m off the drawn walkway`);
                }
            });
        }
        // Up to the bend and on past it: the bend itself is on the line.
        assert.ok(lines.some((f) => f.geometry.coordinates.some((c) => meters(c, east(21.5, 15)) < 0.05)));
    });

    test('a tap on the drawn walkway opens the photo; a tap where the graph runs does not', () => {
        assert.equal(photoAtTap(coverage, east(21.5, 4), { toleranceMeters: 0.3 })?.id, 'pn1');
        assert.equal(photoAtTap(coverage, east(20.4, 4), { toleranceMeters: 0.3 }), null);
    });
});

describe('street view: a tap on the map', () => {
    const coverage = streetViewCoverage(graph, [photo('pn1', east(10)), photo('pn2', east(35)), photo('pn3', east(50, 30))]);

    test('a tap on a highlighted path opens the photo point nearest to it', () => {
        assert.equal(photoAtTap(coverage, east(14, 3), { toleranceMeters: 5 }).id, 'pn1');
        assert.equal(photoAtTap(coverage, east(30, -2), { toleranceMeters: 5 }).id, 'pn2');
        assert.equal(photoAtTap(coverage, east(52, 25), { toleranceMeters: 5 }).id, 'pn3');
    });

    test('a tap too far from every highlighted path finds no photo', () => {
        assert.equal(photoAtTap(coverage, east(20, 12), { toleranceMeters: 5 }), null);
        assert.equal(photoAtTap(coverage, east(90), { toleranceMeters: 5 }), null);
    });

    test('in street view mode a tap only looks for a photo: no start, destination or simulated fix', () => {
        assert.equal(mapTapAction({ streetView: true, simulating: true, navigating: true }), 'street-view');
        assert.equal(mapTapAction({ streetView: true, simulating: false, navigating: false }), 'street-view');
        assert.equal(mapTapAction({ streetView: false, simulating: true, navigating: true }), 'simulate');
        assert.equal(mapTapAction({ streetView: false, simulating: true, navigating: false }), 'none');
        assert.equal(mapTapAction({ streetView: false, simulating: false, navigating: false }), 'none');
        assert.equal(mapTapAction({ streetView: false, simulating: false, navigating: true }), 'none');
    });

    test('off the route a photo opens facing along the path, the way closer to how the map is turned', () => {
        const pn1 = coverage.snaps.get('pn1');
        assert.equal(Math.round(alongPathFacing(pn1, 70)), 90);
        assert.equal(Math.round(alongPathFacing(pn1, 250)), 270);
        assert.equal(Math.round(alongPathFacing(coverage.snaps.get('pn3'), 10)), 0);
    });
});

describe('street view: the button', () => {
    test('is offered only when some photo is shown to users', () => {
        assert.equal(streetViewAvailable([]), false);
        assert.equal(streetViewAvailable([photo('pn1', START)]), true);
    });
});

describe('map padding: the route and the dot centred in the uncovered part of the map', () => {
    const phone = { width: 390, height: 844 };
    const desktop = { width: 1920, height: 1080 };

    test('on a phone the bottom sheet is left free, as before', () => {
        assert.deepEqual(mapPadding({ desktop: false, viewport: phone, sheetHeight: 300 }), { top: 80, left: 24, right: 24, bottom: 324 });
        // Under the debug panel; never so low that nothing is left.
        assert.equal(mapPadding({ desktop: false, viewport: phone, sheetHeight: 200, debug: { right: 250, bottom: 400 } }).top, 416);
        assert.equal(mapPadding({ desktop: false, viewport: phone, sheetHeight: 600, debug: { right: 250, bottom: 400 } }).top, 844 - 624 - 120);
    });

    test('on a desktop the card on the right and the debug panel on the left are left free', () => {
        const card = { left: 1920 - 12 - 380, bottom: 700 };
        assert.deepEqual(mapPadding({ desktop: true, viewport: desktop, card }), { top: 80, left: 24, right: 404, bottom: 24 });
        assert.deepEqual(mapPadding({ desktop: true, viewport: desktop, card, debug: { right: 252, bottom: 500 } }), { top: 80, left: 276, right: 404, bottom: 24 });
        // Below an alert banner.
        assert.equal(mapPadding({ desktop: true, viewport: desktop, card, bannerBottom: 140 }).top, 156);
    });
});
