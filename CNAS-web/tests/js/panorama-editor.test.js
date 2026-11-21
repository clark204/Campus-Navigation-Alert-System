import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { buildGraph } from '../../resources/js/navigation/graph.js';
import {
    changedCodes,
    createDrafts,
    discard,
    hasUnsaved,
    isChanged,
    markSaved,
    move,
    payloadFor,
    select,
    setFloor,
    setName,
    setNorth,
    setVisible,
} from '../../resources/js/panorama-drafts.js';
import {
    EYE_HEIGHT_METERS,
    groundView,
    guessNorth,
    inView,
    nearestWalkBearing,
    overlayLines,
    walkSegments,
} from '../../resources/js/panorama-overlay.js';
import { offset } from '../../resources/js/panoramas.js';

const START = [124.7482424, 8.5412066];
const at = (east, north = 0) => offset(offset(START, 90, east), 0, north);

const saved = () =>
    createDrafts([
        { code: 'pn1', name: 'Gate', floor: 1, latitude: START[1], longitude: START[0], north_offset: 90, is_visible: true },
        { code: 'pn2', name: 'Walkway', floor: 1, latitude: at(12)[1], longitude: at(12)[0], north_offset: null, is_visible: false },
        { code: 'pn3', name: 'Upstairs', floor: 2, latitude: at(20)[1], longitude: at(20)[0], north_offset: 10, is_visible: false },
    ]);

describe('editor: selecting, dragging and the unsaved state', () => {
    test('only saved photos can be selected', () => {
        const state = select(saved(), 'pn2');
        assert.equal(state.selected, 'pn2');
        assert.equal(select(state, 'pn99').selected, 'pn2');
    });

    test('dragging a pin marks that photo changed; dragging it back unmarks it', () => {
        let state = move(saved(), 'pn2', { latitude: 8.5415, longitude: 124.7485 });
        assert.equal(isChanged(state, 'pn2'), true);
        assert.equal(isChanged(state, 'pn1'), false);
        assert.deepEqual(changedCodes(state), ['pn2']);
        assert.equal(hasUnsaved(state), true);

        state = move(state, 'pn2', { latitude: at(12)[1], longitude: at(12)[0] });
        assert.equal(isChanged(state, 'pn2'), false);
        assert.equal(hasUnsaved(state), false);
    });

    test('every field counts as a change: name, floor, north and visibility', () => {
        assert.equal(isChanged(setName(saved(), 'pn1', 'Main gate'), 'pn1'), true);
        assert.equal(isChanged(setFloor(saved(), 'pn3', 1), 'pn3'), true);
        assert.equal(isChanged(setNorth(saved(), 'pn3', 200), 'pn3'), true);
        assert.equal(isChanged(setVisible(setNorth(saved(), 'pn2', 30), 'pn2', true), 'pn2'), true);
    });

    test('"Save all" sends only the changed photos, in code order, with every field', () => {
        let state = move(saved(), 'pn2', { latitude: 8.5415, longitude: 124.7485 });
        state = setName(state, 'pn1', 'Main gate');
        assert.deepEqual(payloadFor(state), [
            { code: 'pn1', name: 'Main gate', floor: 1, latitude: START[1], longitude: START[0], north_offset: 90, is_visible: true },
            { code: 'pn2', name: 'Walkway', floor: 1, latitude: 8.5415, longitude: 124.7485, north_offset: null, is_visible: false },
        ]);
        assert.deepEqual(payloadFor(state, ['pn2']).map((p) => p.code), ['pn2']);
    });

    test('discard throws away every edit, or only one photo\'s', () => {
        let state = setName(move(saved(), 'pn2', { latitude: 8.5415, longitude: 124.7485 }), 'pn1', 'Main gate');
        assert.equal(hasUnsaved(discard(state, 'pn2')), true);
        assert.deepEqual(changedCodes(discard(state, 'pn2')), ['pn1']);
        assert.equal(hasUnsaved(discard(state)), false);
        assert.equal(discard(state).drafts.get('pn1').name, 'Gate');
    });

    test('a photo keeps its image through edits, and the image is never sent to the server', () => {
        const image = '/storage/panoramas/pn1-abc.jpg';
        let state = createDrafts([{ code: 'pn1', name: 'Gate', floor: 1, latitude: START[1], longitude: START[0], north_offset: null, is_visible: false, image }]);
        assert.equal(state.drafts.get('pn1').image, image);
        assert.equal(isChanged(state, 'pn1'), false);

        state = setNorth(move(state, 'pn1', { latitude: 8.5415, longitude: 124.7485 }), 'pn1', 40);
        assert.equal(state.drafts.get('pn1').image, image);
        assert.equal(markSaved(state, ['pn1']).saved.get('pn1').image, image);
        assert.equal(discard(state).drafts.get('pn1').image, image);
        assert.equal('image' in payloadFor(state)[0], false);
    });

    test('after a save the saved photos are the edited ones', () => {
        const state = setName(saved(), 'pn1', 'Main gate');
        const after = markSaved(state, ['pn1']);
        assert.equal(hasUnsaved(after), false);
        assert.equal(after.saved.get('pn1').name, 'Main gate');
    });
});

describe('editor: north and "Show to users"', () => {
    test('a photo can only be shown with north set', () => {
        const state = saved();
        assert.equal(setVisible(state, 'pn2', true), state, 'refused without north');
        assert.equal(setVisible(setNorth(state, 'pn2', 30), 'pn2', true).drafts.get('pn2').is_visible, true);
    });

    test('clearing north hides the photo', () => {
        const state = setNorth(saved(), 'pn1', '');
        assert.equal(state.drafts.get('pn1').north_offset, null);
        assert.equal(state.drafts.get('pn1').is_visible, false);
    });

    test('north is 0–360, with 360 read as north again', () => {
        assert.equal(setNorth(saved(), 'pn2', '360').drafts.get('pn2').north_offset, 0);
        assert.equal(setNorth(saved(), 'pn2', '-10').drafts.get('pn2').north_offset, 350);
        assert.equal(setNorth(saved(), 'pn2', '12.345').drafts.get('pn2').north_offset, 12.35);
    });
});

describe('overlay: the direction maths', () => {
    test('a walkway on the ground appears at its bearing plus north, below the horizon', () => {
        const view = groundView(90, 30, 10);
        assert.equal(view.yaw, 120);
        assert.ok(Math.abs(view.pitch - -Math.atan(EYE_HEIGHT_METERS / 10) * (180 / Math.PI)) < 1e-9);
    });

    test('bearings wrap round north', () => {
        assert.equal(groundView(350, 20, 5).yaw, 10);
        assert.equal(groundView(10, 350, 5).yaw, 0);
    });

    test('the nearer the ground, the steeper it is seen', () => {
        assert.ok(Math.abs(groundView(0, 0, 2).pitch - -38.66) < 0.01);
        assert.ok(Math.abs(groundView(0, 0, 40).pitch - -2.29) < 0.01);
        assert.ok(groundView(0, 0, 2).pitch < groundView(0, 0, 40).pitch);
    });

    test('the starting guess lines the walkway up with the centre of the view', () => {
        const north = guessNorth(200, 90);
        assert.equal(north, 110);
        assert.equal(groundView(90, north, 10).yaw, 200);
    });

    test('lines out of view are not drawn', () => {
        assert.equal(inView(10, 350), true);
        assert.equal(inView(180, 0), false);
    });
});

describe('overlay: the walkways near the pin', () => {
    const graph = buildGraph(
        {
            paths: {
                type: 'FeatureCollection',
                features: [
                    { type: 'Feature', properties: { id: 'a', type: 'walkway', floor: 1 }, geometry: { type: 'LineString', coordinates: [at(-30), at(100)] } },
                    { type: 'Feature', properties: { id: 'far', type: 'walkway', floor: 1 }, geometry: { type: 'LineString', coordinates: [at(300, 200), at(320, 200)] } },
                    { type: 'Feature', properties: { id: 'up', type: 'corridor', floor: 2 }, geometry: { type: 'LineString', coordinates: [at(-30, 1), at(100, 1)] } },
                ],
            },
            entrances: { type: 'FeatureCollection', features: [] },
        },
        { warn: () => {} },
    );
    const pin = at(0, 2);

    test('the walkways within reach of the pin, on its floor only', () => {
        const segments = walkSegments(graph, pin, 1);
        assert.ok(segments.length >= 1);
        assert.ok(segments.every(([a, b]) => Math.min(a[0], b[0]) < at(100)[0]));
        assert.equal(walkSegments(graph, pin, 3).length, 0);
    });

    test('the nearest walkway runs east from the pin', () => {
        const bearing = nearestWalkBearing(graph, pin, 1);
        assert.ok(Math.abs(bearing - 90) < 5 || Math.abs(bearing - 270) < 5, `${bearing}`);
    });

    test('a drawn walkway runs along its bearing from the pin, turned by north', () => {
        const lines = overlayLines(pin, walkSegments(graph, pin, 1), 30);
        const first = lines[0][0];
        // The walkway's end 30 m west of the pin (the pin sits 2 m north of it): photo yaw about 300° with north 30.
        assert.ok(Math.abs(first.yaw - 300) < 5, `${first.yaw}`);
        assert.ok(lines.every((line) => line.every((point) => point.pitch < 0)));
    });

    test('with no graph there is nothing to draw', () => {
        assert.deepEqual(walkSegments(null, pin, 1), []);
        assert.equal(nearestWalkBearing(null, pin, 1), null);
    });
});
