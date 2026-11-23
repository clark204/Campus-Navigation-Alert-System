// The incident pin helpers shared by the web alert form and the mobile app's send form (navigation/incident.js).
import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import { buildingAt, locationLabel } from '../../resources/js/navigation/incident.js';

const square = (x, y) => [[x, y], [x + 1, y], [x + 1, y + 1], [x, y + 1], [x, y]];

describe('incident pin', () => {
    test('the building or facility under the pin is found, never the campus or an evacuation area', () => {
        const buildings = [
            { id: 'campus', type: 'campus', ring: square(0, 0).map(([x, y]) => [x * 10, y * 10]) },
            { id: 'field', type: 'evacuation', ring: square(5, 5) },
            { id: 'b1', type: 'building', ring: square(1, 1) },
            { id: 'canteen', type: 'facility', ring: square(3, 3) },
        ];

        assert.equal(buildingAt([1.5, 1.5], buildings), 'b1');
        assert.equal(buildingAt([3.5, 3.5], buildings), 'canteen');
        assert.equal(buildingAt([5.5, 5.5], buildings), null);
        assert.equal(buildingAt([8, 8], buildings), null);
    });

    test('the place reads like Alert::locationLabel()', () => {
        assert.equal(locationLabel(['NEE Building'], true), 'NEE Building');
        assert.equal(locationLabel(['NEE Building', 'Library'], true), 'NEE Building and Library');
        assert.equal(locationLabel(['NEE Building', 'Library', 'Gym'], false), 'NEE Building and 2 more buildings');
        assert.equal(locationLabel([], true), 'Marked on the map');
        assert.equal(locationLabel([], false), 'Whole campus');
    });
});
