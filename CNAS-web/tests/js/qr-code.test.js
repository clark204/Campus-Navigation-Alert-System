import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, test } from 'node:test';
import { buildGraph, listPlaces } from '../../resources/js/navigation/graph.js';
import { findRoute } from '../../resources/js/navigation/router.js';
import { mainEntrance } from '../../resources/js/main-entrance.js';
import { fitFont, sizedSvg } from '../../resources/js/qr-print.js';

const load = (file) => JSON.parse(readFileSync(new URL(`../../database/data/maps/${file}`, import.meta.url)));
const campus = {
    paths: load('paths.geojson'),
    entrances: load('entrances.geojson'),
    stairs: load('stairs.geojson'),
    buildings: load('buildings.geojson'),
};
const graph = buildGraph(campus, { warn: () => {} });
const gate = listPlaces(graph, campus.paths).find((place) => place.id === 'p1');
const groundDoors = (buildingId) =>
    campus.entrances.features.filter((f) => f.properties.building_id === buildingId && f.properties.floor === 1).map((f) => f.properties.id);

describe('QR codes: main entrance', () => {
    test('is the door with the shortest walk from the Entrance Gate', () => {
        const buildingId = Object.entries(Object.groupBy(campus.entrances.features, (f) => f.properties.building_id)).find(
            ([id]) => groundDoors(id).length > 1,
        )[0];
        const doors = groundDoors(buildingId);
        const walks = doors.map((door) => ({ door, length: findRoute(graph, gate.nodeId, door).length }));
        const shortest = walks.reduce((a, b) => (a.length <= b.length ? a : b));

        assert.equal(mainEntrance(graph, gate.nodeId, doors), shortest.door);
    });

    test('is null with no doors on the map, and ignores doors the map does not have', () => {
        assert.equal(mainEntrance(graph, gate.nodeId, []), null);
        assert.equal(mainEntrance(graph, gate.nodeId, ['r-missing']), null);
        const [door] = groundDoors(campus.entrances.features[0].properties.building_id);
        assert.equal(mainEntrance(graph, gate.nodeId, ['r-missing', door]), door);
    });
});

describe('QR codes: the PNG', () => {
    test('gets a sized copy of the SVG and a title font that fits', () => {
        assert.equal(sizedSvg('<svg xmlns="x" viewBox="0 0 37 37"><path/></svg>', 900), '<svg width="900" height="900" xmlns="x" viewBox="0 0 37 37"><path/></svg>');
        const measure = (text, size) => text.length * size * 0.5;
        assert.equal(fitFont(measure, 'NEE 202', 1080, 88), 88);
        assert.equal(fitFont(measure, 'A very long room name that will not fit at all', 1080, 88), 46);
        assert.equal(fitFont(measure, 'x'.repeat(500), 1080, 88), 24);
    });
});
