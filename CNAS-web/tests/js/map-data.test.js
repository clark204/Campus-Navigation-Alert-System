import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { loadMapData } from '../../resources/js/map-data.js';

// A stand-in for fetch that answers with the given replies in turn and counts the calls.
function fakeFetch(...replies) {
    const calls = [];
    const fetchImpl = async (url, options) => {
        calls.push({ url, options });
        const { status = 200, body = {} } = replies[Math.min(calls.length, replies.length) - 1];
        return { ok: status >= 200 && status < 300, status, json: async () => body };
    };
    return { fetchImpl, calls };
}

describe('loading the campus map', () => {
    test('two readers on one page share one request', async () => {
        const { fetchImpl, calls } = fakeFetch({ body: { data: { buildings: { features: [] } } } });

        const [first, second] = await Promise.all([
            loadMapData('/api/map?shared', fetchImpl),
            loadMapData('/api/map?shared', fetchImpl),
        ]);

        assert.equal(calls.length, 1);
        assert.equal(first, second);
        assert.equal(calls[0].options.headers.Accept, 'application/json');
    });

    test('the map is unwrapped from the data key', async () => {
        const map = { buildings: 1, paths: 2, entrances: 3, stairs: 4, landmarks: 5 };
        const { fetchImpl } = fakeFetch({ body: { data: map } });

        assert.deepEqual(await loadMapData('/api/map?unwrap', fetchImpl), map);
    });

    test('an HTTP error rejects, and the next call tries again', async () => {
        const { fetchImpl, calls } = fakeFetch({ status: 500 }, { body: { data: { buildings: 1 } } });

        await assert.rejects(loadMapData('/api/map?retry', fetchImpl), /HTTP 500/);
        assert.deepEqual(await loadMapData('/api/map?retry', fetchImpl), { buildings: 1 });
        assert.equal(calls.length, 2);
    });

    test('a reply without a map rejects', async () => {
        const { fetchImpl } = fakeFetch({ body: { message: 'Server Error' } });

        await assert.rejects(loadMapData('/api/map?empty', fetchImpl), /no map/i);
    });
});
