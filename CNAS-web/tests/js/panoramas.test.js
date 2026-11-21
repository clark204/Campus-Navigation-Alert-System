import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import {
    bearingBetween,
    createNorthSpot,
    createViewerSession,
    distanceBetween,
    groundArrow,
    MIN_SPOT_METERS,
    northOffsetFrom,
    offset,
    panoramasFrom,
    poseHeading,
    SPOT_TOO_CLOSE,
    tourNodes,
    viewBearing,
} from '../../resources/js/panoramas.js';

const START = [124.7482424, 8.5412066];
const point = (id, coord, extra = {}) => ({ id, name: `Photo ${id}`, floor: 1, northOffset: 90, image: `/storage/panoramas/${id}.jpg`, thumb: `/storage/panoramas/${id}-thumb.jpg`, coord, ...extra });
// Photos every 12 m going south-west down a walkway, like pn1…pn5.
const walkway = Array.from({ length: 5 }, (_, i) => point(`pn${i + 1}`, offset(START, 225, i * 12)));
const ids = (links, id) => links.get(id).map((link) => link.id);
const tick = () => new Promise((resolve) => setImmediate(resolve));

describe('panoramas: data from GET /api/map', () => {
    test('features become photo points', () => {
        const points = panoramasFrom({
            type: 'FeatureCollection',
            features: [{
                type: 'Feature',
                properties: { id: 'pn3', name: 'Main walkway', floor: 2, north_offset: 287.5, image: '/storage/panoramas/pn3.jpg', thumb: '/storage/panoramas/pn3-thumb.jpg' },
                geometry: { type: 'Point', coordinates: [124.7483552, 8.541122] },
            }],
        });
        assert.deepEqual(points, [{ id: 'pn3', name: 'Main walkway', floor: 2, northOffset: 287.5, image: '/storage/panoramas/pn3.jpg', thumb: '/storage/panoramas/pn3-thumb.jpg', coord: [124.7483552, 8.541122] }]);
        assert.deepEqual(panoramasFrom(undefined), []);
    });

    test('distances and bearings on campus', () => {
        const east = offset(START, 90, 20);
        assert.ok(Math.abs(distanceBetween(START, east) - 20) < 0.01);
        assert.ok(Math.abs(bearingBetween(START, east) - 90) < 0.01);
        assert.ok(Math.abs(bearingBetween(east, START) - 270) < 0.01);
    });
});

describe('panoramas: north', () => {
    test('north from a spot on the map lined up in the photo, and back', () => {
        // The spot is 30° east of north from the photo point and is on the line with the photo turned 100° right.
        const north = northOffsetFrom(100, 30);
        assert.equal(north, 70);
        assert.equal(viewBearing(north, 100), 30);
        assert.equal(viewBearing(north, 70), 0);
        // Wraps around.
        assert.equal(northOffsetFrom(10, 350), 20);
        assert.equal(viewBearing(20, 10), 350);
    });

    test('the photo centre faces the opposite of north in the photo', () => {
        assert.equal(poseHeading(70), 290);
        assert.equal(poseHeading(0), 0);
    });
});

describe('panoramas: the reference spot for north', () => {
    const pin = START;

    test(`a spot closer than ${MIN_SPOT_METERS} m to the pin is not accepted`, () => {
        const spot = createNorthSpot();
        const near = spot.pick(pin, offset(pin, 60, MIN_SPOT_METERS - 0.5));

        assert.equal(near.ok, false);
        assert.equal(near.message, SPOT_TOO_CLOSE);
        assert.equal(Math.round(near.distance), 10);
        assert.equal(spot.spot, null);
        assert.equal(spot.pick(pin, pin).ok, false);
    });

    test(`a spot ${MIN_SPOT_METERS} m or more away is accepted, with its distance`, () => {
        const spot = createNorthSpot();
        const far = offset(pin, 60, 34);
        const picked = spot.pick(pin, far);

        assert.deepEqual([picked.ok, Math.round(picked.distance)], [true, 34]);
        assert.deepEqual(spot.spot, far);
        assert.equal(Math.round(spot.distance(pin)), 34);
        assert.equal(spot.pick(pin, offset(pin, 60, MIN_SPOT_METERS)).ok, true);
    });

    test('a new click replaces the old spot, and a too-close click leaves none behind', () => {
        const spot = createNorthSpot();
        const first = offset(pin, 0, 30);
        const second = offset(pin, 90, 40);
        spot.pick(pin, first);
        spot.pick(pin, second);
        assert.deepEqual(spot.spot, second);

        spot.pick(pin, offset(pin, 90, 3));
        assert.equal(spot.spot, null);
        assert.equal(spot.distance(pin), null);
    });

    test('clearing removes the spot', () => {
        const spot = createNorthSpot();
        spot.pick(pin, offset(pin, 0, 30));
        spot.clear();
        assert.equal(spot.spot, null);
    });

    test('moving the pin next to the spot drops it; moving it a little keeps it', () => {
        const spot = createNorthSpot();
        const far = offset(pin, 0, 30);
        spot.pick(pin, far);

        assert.equal(spot.check(offset(pin, 0, 5)), true);
        assert.deepEqual(spot.spot, far);
        assert.equal(spot.check(offset(far, 180, 4)), false);
        assert.equal(spot.spot, null);
    });
});

describe('panoramas: viewer tour', () => {
    test('each photo is a tour node with its walk links (placed on the walkway) and turned to north', () => {
        const links = new Map([['pn2', [
            { id: 'pn1', gps: walkway[0].coord, bearing: 45, label: 'Toward the gate' },
            { id: 'pn3', gps: walkway[2].coord, bearing: 225, label: 'Photo pn3' },
        ]]]);
        const nodes = tourNodes(walkway.slice(0, 3), links);
        assert.deepEqual(nodes.map((n) => n.id), ['pn1', 'pn2', 'pn3']);
        const second = nodes[1];
        assert.equal(second.panorama, '/storage/panoramas/pn2.jpg');
        assert.equal(second.thumbnail, '/storage/panoramas/pn2-thumb.jpg');
        assert.equal(second.caption, 'Photo pn2');
        assert.deepEqual(second.gps, walkway[1].coord);
        assert.deepEqual(second.panoData, { poseHeading: 270 });
        assert.deepEqual(second.links.map((l) => l.nodeId).sort(), ['pn1', 'pn3']);
        assert.deepEqual(second.links.find((l) => l.nodeId === 'pn3').gps, walkway[2].coord);
        assert.deepEqual(second.links.find((l) => l.nodeId === 'pn1').data, { label: 'Toward the gate', bearing: 45, from: 'pn2' });
        assert.deepEqual(nodes[0].links, []);
    });

    test('names are escaped, since the viewer shows them as HTML', () => {
        const [node] = tourNodes([point('pn1', START, { name: '<img src=x onerror=alert(1)> & "Gate"' })]);
        assert.equal(node.name, '&lt;img src=x onerror=alert(1)&gt; &amp; &quot;Gate&quot;');
        assert.equal(node.caption, node.name);
    });
});

describe('panoramas: route arrow on the ground', () => {
    test('the arrow lies on the ground ahead in the route direction, narrower where it is further away', () => {
        const [farLeft, farRight, nearRight, nearLeft] = groundArrow(90);
        const deg = (rad) => (rad * 180) / Math.PI;
        // Centred on 90° (east), every corner below the horizon.
        assert.equal(Math.round(deg((farLeft.yaw + farRight.yaw) / 2)), 90);
        assert.equal(Math.round(deg((nearLeft.yaw + nearRight.yaw) / 2)), 90);
        assert.ok([farLeft, farRight, nearRight, nearLeft].every((c) => c.pitch < 0));
        // The far end is nearer the horizon and looks narrower.
        assert.ok(farLeft.pitch > nearLeft.pitch);
        assert.ok(farRight.yaw - farLeft.yaw < nearRight.yaw - nearLeft.yaw);
        assert.ok(farLeft.yaw < farRight.yaw);
    });

    test('pointing north wraps around cleanly', () => {
        const [farLeft, farRight] = groundArrow(0);
        assert.ok(Math.abs(farRight.yaw - farLeft.yaw) < 1);
    });
});

describe('panoramas: viewer wiring', () => {
    const fakeUi = () => {
        const ui = { container: { id: 'viewer' }, shown: [], guides: [], hidden: 0, failed: 0 };
        ui.show = (p, guide = null) => {
            ui.shown.push(p.id);
            ui.guides.push(guide);
        };
        ui.hide = () => ui.hidden++;
        ui.fail = () => ui.failed++;
        return ui;
    };
    const fakeLoader = () => {
        const calls = [];
        const load = (container, options) => {
            const viewer = { shown: [], destroyed: false, show: async (id, yaw) => viewer.shown.push([id, yaw]), destroy: () => (viewer.destroyed = true) };
            calls.push({ container, options, viewer });
            return Promise.resolve(viewer);
        };
        return { calls, load };
    };

    test('opening a photo starts the viewer there, turned the way the map faces', async () => {
        const ui = fakeUi();
        const { calls, load } = fakeLoader();
        const session = createViewerSession({ points: walkway, load, ui });

        assert.equal(await session.open('pn2', { yaw: 310 }), true);

        assert.equal(calls.length, 1);
        assert.equal(calls[0].container, ui.container);
        assert.equal(calls[0].options.startId, 'pn2');
        assert.equal(calls[0].options.yaw, 310);
        assert.deepEqual(calls[0].options.nodes.map((n) => n.id), ['pn1', 'pn2', 'pn3', 'pn4', 'pn5']);
        assert.deepEqual(ui.shown, ['pn2']);
        assert.equal(session.openId, 'pn2');
    });

    test('stepping to another photo inside the viewer updates the title without a new viewer', async () => {
        const ui = fakeUi();
        const { calls, load } = fakeLoader();
        const session = createViewerSession({ points: walkway, load, ui });
        await session.open('pn2');

        calls[0].options.onNode('pn3');

        assert.deepEqual(ui.shown, ['pn2', 'pn3']);
        assert.equal(calls.length, 1);
    });

    test('closing frees the viewer; opening again starts a new one', async () => {
        const ui = fakeUi();
        const { calls, load } = fakeLoader();
        const session = createViewerSession({ points: walkway, load, ui });
        await session.open('pn1');

        session.close();
        assert.equal(calls[0].viewer.destroyed, true);
        assert.equal(ui.hidden, 1);
        assert.equal(session.openId, null);

        await session.open('pn4');
        assert.equal(calls.length, 2);
        assert.equal(calls[1].options.startId, 'pn4');
    });

    test('closing while the photo is still loading frees it once it arrives', async () => {
        const ui = fakeUi();
        let resolve;
        const viewer = { destroyed: false, destroy: () => (viewer.destroyed = true) };
        const session = createViewerSession({ points: walkway, load: () => new Promise((r) => (resolve = r)), ui });

        const opening = session.open('pn1');
        session.close();
        resolve(viewer);

        assert.equal(await opening, false);
        await tick();
        assert.equal(viewer.destroyed, true);
    });

    test('a viewer that fails to load says so, and the next tap tries again', async () => {
        const ui = fakeUi();
        let attempts = 0;
        const viewer = { destroy: () => {} };
        const load = () => (++attempts === 1 ? Promise.reject(new Error('WebGL unavailable')) : Promise.resolve(viewer));
        const session = createViewerSession({ points: walkway, load, ui });
        const error = console.error;
        console.error = () => {};
        try {
            assert.equal(await session.open('pn1'), false);
        } finally {
            console.error = error;
        }
        assert.equal(ui.failed, 1);

        assert.equal(await session.open('pn1'), true);
        assert.equal(attempts, 2);
    });

    // A route through pn2 and pn3 (route-panoramas.js gives these for real): which way each faces, what it says.
    const routeGuide = (id) =>
        ({
            pn2: { bearing: 90, text: 'Turn left near the Registrar', caption: null, nextId: 'pn3' },
            pn3: { bearing: 0, text: 'You have arrived', caption: 'NEE 202 is ahead on your right', nextId: null },
        })[id] ?? null;

    test('a photo opened on a route faces the way the route goes, with the arrow and the guide shown', async () => {
        const ui = fakeUi();
        const { calls, load } = fakeLoader();
        const session = createViewerSession({ points: walkway, load, ui });

        await session.open('pn2', { yaw: 310, guide: routeGuide });

        assert.equal(calls[0].options.yaw, 90);
        assert.equal(calls[0].options.arrowFor('pn2'), 90);
        assert.deepEqual(ui.guides, [routeGuide('pn2')]);
    });

    test('"Next spot" steps to the next photo on the route, facing its direction; the last one has none', async () => {
        const ui = fakeUi();
        const { calls, load } = fakeLoader();
        const session = createViewerSession({ points: walkway, load, ui });
        await session.open('pn2', { guide: routeGuide });

        assert.equal(await session.next(), true);
        assert.deepEqual(calls[0].viewer.shown, [['pn3', 0]]);
        assert.deepEqual([session.openId, ui.guides.at(-1).caption], ['pn3', 'NEE 202 is ahead on your right']);
        assert.equal(await session.next(), false);
    });

    test('walking in the viewer to a photo off the route drops the arrow and the guide', async () => {
        const ui = fakeUi();
        const { calls, load } = fakeLoader();
        const session = createViewerSession({ points: walkway, load, ui });
        await session.open('pn2', { guide: routeGuide });

        calls[0].options.onNode('pn5');

        assert.equal(ui.guides.at(-1), null);
        assert.equal(calls[0].options.arrowFor('pn5'), null);
        assert.equal(await session.next(), false);
    });

    test('a photo change still arriving from a closed viewer does not touch the one opened after it', async () => {
        const ui = fakeUi();
        const { calls, load } = fakeLoader();
        const session = createViewerSession({ points: walkway, load, ui });
        await session.open('pn2', { guide: routeGuide });
        session.close();
        await session.open('pn1', { yaw: 10 });

        // The first viewer was still fading to pn3 when it was closed.
        calls[0].options.onNode('pn3');

        assert.deepEqual([session.openId, ui.shown.at(-1)], ['pn1', 'pn1']);
    });

    test('a photo opened off the route after a route photo has no guide', async () => {
        const ui = fakeUi();
        const { calls, load } = fakeLoader();
        const session = createViewerSession({ points: walkway, load, ui });
        await session.open('pn2', { guide: routeGuide });

        await session.open('pn3', { yaw: 45 });

        assert.deepEqual(calls[0].viewer.shown, [['pn3', 45]]);
        assert.equal(ui.guides.at(-1), null);
        assert.equal(calls[0].options.arrowFor('pn3'), null);
    });

    test('an unknown photo is not opened', async () => {
        const ui = fakeUi();
        const { calls, load } = fakeLoader();
        const session = createViewerSession({ points: walkway, load, ui });
        assert.equal(await session.open('pn99'), false);
        assert.deepEqual([calls.length, ui.shown.length], [0, 0]);
    });
});
