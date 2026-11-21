import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { buildGraph } from '../../resources/js/navigation/graph.js';
import { MAX_WALK_LINKS, routeTwin, SAME_DIRECTION_DEGREES, walkLinks } from '../../resources/js/photo-links.js';
import { offset } from '../../resources/js/panoramas.js';

const START = [124.7482424, 8.5412066];
// Metres east and north of START.
const at = (east, north = 0) => offset(offset(START, 90, east), 0, north);
const line = (id, coordinates, type = 'walkway', floor = 1) => ({
    type: 'Feature',
    properties: { id, name: id, type, floor },
    geometry: { type: 'LineString', coordinates },
});
const graphOf = (features) => buildGraph({ paths: { type: 'FeatureCollection', features }, entrances: { type: 'FeatureCollection', features: [] } }, { warn: () => {} });
// A photo point: 2 m north of the walkway unless given a position.
const photo = (id, coord, floor = 1) => ({ id, name: `Photo ${id}`, floor, northOffset: 0, coord });
const ids = (links, id) => links.get(id).map((link) => link.id).sort();
const near = (actual, expected, tolerance, label) => assert.ok(Math.abs(actual - expected) <= tolerance, `${label}: ${actual} vs ${expected}`);

describe('photo links: following the walkways', () => {
    test('a straight walkway gives each photo two links: forward and back', () => {
        const graph = graphOf([line('a', [at(-40), at(100)])]);
        const points = [photo('A', at(10, 2)), photo('B', at(30, 2)), photo('C', at(55, 2))];
        const links = walkLinks(graph, points);

        assert.deepEqual(ids(links, 'B'), ['A', 'C']);
        assert.equal(links.get('B').length, 2);
        const back = links.get('B').find((link) => link.id === 'A');
        const forward = links.get('B').find((link) => link.id === 'C');
        near(back.bearing, 270, 10, 'back arrow');
        near(forward.bearing, 90, 10, 'forward arrow');
        assert.equal(back.fallback, false);
    });

    test('a junction gives a third link for the branch, when the branch is in another direction', () => {
        // Walkway a runs east; a branch leaves it at 15 m going north. Photo K is 15 m back, F 35 m on along a, and B
        // 22 m up the branch (37 m of walk).
        const graph = graphOf([line('a', [at(-30), at(100)]), line('b', [at(15), at(15, 60)])]);
        const points = [photo('P', at(0, 2)), photo('K', at(-15)), photo('F', at(35)), photo('B', at(15, 22))];
        const links = walkLinks(graph, points);

        assert.deepEqual(ids(links, 'P'), ['B', 'F', 'K']);
        assert.equal(links.get('P').length, 3);
        const branch = links.get('P').find((link) => link.id === 'B');
        near(branch.bearing, 37, 4, 'branch arrow leaves at the junction');
    });

    test('a dead end gives one link', () => {
        const graph = graphOf([line('a', [at(0), at(100)])]);
        const points = [photo('Q', at(70, 2)), photo('P', at(90, 2))];
        const links = walkLinks(graph, points);

        assert.deepEqual(ids(links, 'P'), ['Q']);
        assert.deepEqual(ids(links, 'Q'), ['P']);
    });

    test('never more than three links, even with four ways out', () => {
        // A cross at (50, 0): the photo P sits 5 m up the north branch; the other three branches have a photo 20 m
        // from the junction, and the north branch has one 25 m ahead of P.
        const graph = graphOf([
            line('n', [at(50, 0), at(50, 60)]),
            line('e', [at(50, 0), at(90, 0)]),
            line('w', [at(50, 0), at(10, 0)]),
            line('s', [at(50, 0), at(50, -40)]),
        ]);
        const points = [photo('P', at(50, 5)), photo('N', at(50, 30)), photo('E', at(70, 0)), photo('W', at(30, 0)), photo('S', at(50, -20))];
        const links = walkLinks(graph, points);

        assert.equal(links.get('P').length, MAX_WALK_LINKS);
    });

    test('two photos in nearly the same direction: the nearer one is kept', () => {
        // A junction 10 m east of P; a branch goes off at 40° (north-east). A photo 30 m along the walkway (walk 30) and
        // one 28 m up the branch (walk 38) are about 35° apart seen from P, so the branch one is dropped.
        const branchEnd = offset(at(10), 40, 40);
        const graph = graphOf([line('a', [at(-40), at(100)]), line('b', [at(10), branchEnd])]);
        const branchPhoto = offset(at(10), 40, 28);
        const points = [photo('P', at(0, 2)), photo('K', at(-15)), photo('A', at(30)), photo('B', branchPhoto)];
        const links = walkLinks(graph, points);

        assert.deepEqual(ids(links, 'P'), ['A', 'K']);
        assert.ok(SAME_DIRECTION_DEGREES === 45);
    });

    test('no walkway link goes past 40 m: the photo 45 m away is only a fallback', () => {
        const graph = graphOf([line('a', [at(-40), at(100)])]);
        const links = walkLinks(graph, [photo('P', at(0, 2)), photo('Far', at(45))]);
        assert.deepEqual(links.get('P').map((link) => [link.id, link.fallback]), [['Far', true]]);
    });
});

describe('photo links: floors and fallback', () => {
    test('photos on different floors never link, even when they are close', () => {
        const graph = graphOf([line('a', [at(-40), at(100)]), line('c', [at(-40, 1), at(100, 1)], 'corridor', 2)]);
        const points = [photo('P', at(10, 2), 1), photo('U', at(20, 1), 2)];
        const links = walkLinks(graph, points);

        assert.deepEqual(links.get('P'), []);
        assert.deepEqual(links.get('U'), []);
    });

    test('a photo off the walkway falls back to its single nearest photo on its floor', () => {
        const graph = graphOf([line('a', [at(-40), at(100)])]);
        const off = photo('Off', at(20, 30));
        const points = [off, photo('Q', at(60, 2))];
        const links = walkLinks(graph, points);

        assert.equal(links.get('Off').length, 1);
        assert.equal(links.get('Off')[0].id, 'Q');
        assert.equal(links.get('Off')[0].fallback, true);
    });

    test('a photo with no walkway link at all falls back, and the fallback stays on its own floor', () => {
        const graph = graphOf([line('a', [at(-40), at(100)])]);
        const points = [photo('P', at(10, 2), 1), photo('Up', at(12, 1), 2)];
        const links = walkLinks(graph, points);

        // Alone on its floor: nothing to link to, even though a photo on another floor is closer.
        assert.deepEqual(links.get('P'), []);
    });

    test('without a walkway graph every photo falls back to its nearest photo', () => {
        const links = walkLinks(null, [photo('A', at(0)), photo('B', at(30)), photo('C', at(60))]);
        assert.deepEqual(ids(links, 'A'), ['B']);
        assert.equal(links.get('A')[0].fallback, true);
    });
});

describe('photo links: labels and the route arrow', () => {
    test('an arrow along a named walkway says where it goes', () => {
        const graph = graphOf([line('a', [at(-40), at(100)])]);
        const points = [photo('B', at(30, 2)), photo('C', at(55, 2))];
        const links = walkLinks(graph, points, { landmarks: { paths: { a: 'the gate' } } });

        const forward = links.get('B').find((link) => link.id === 'C');
        assert.equal(forward.label, 'Toward the gate');
    });

    test('without a named walkway the label is the next photo\'s name', () => {
        const graph = graphOf([line('a', [at(-40), at(100)])]);
        const links = walkLinks(graph, [photo('B', at(30, 2)), photo('C', at(55, 2))]);
        assert.equal(links.get('B').find((link) => link.id === 'C').label, 'Photo C');
    });

    test('an arrow pointing the same way as the route arrow is hidden with it', () => {
        assert.equal(routeTwin(90, 100), true);
        assert.equal(routeTwin(90, 60), false);
        assert.equal(routeTwin(350, 10), true);
    });
});
