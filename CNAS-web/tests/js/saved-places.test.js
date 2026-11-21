import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { createPlaceStore } from '../../resources/js/navigation/saved-places.js';

// A fetch that records each request and answers with `status` (or fails like a dropped connection).
function fakeFetch(status = 200) {
    const calls = [];
    const fetchImpl = async (url, options) => {
        calls.push({ url, method: options.method, csrf: options.headers['X-CSRF-TOKEN'] });
        if (status === 'offline') throw new TypeError('Failed to fetch');
        return { ok: status >= 200 && status < 300, status };
    };
    return { calls, fetchImpl };
}

const store = (fetchImpl, saved = []) =>
    createPlaceStore({ saved, saveUrl: '/places/__room__/save', recentUrl: '/places/__room__/recent', csrfToken: 'tok', fetchImpl });

describe('saved places on /navigate', () => {
    test('saving a room posts it and it then reads as saved', async () => {
        const { calls, fetchImpl } = fakeFetch();
        const places = store(fetchImpl);
        assert.equal(await places.toggle('r6'), true);
        assert.equal(places.isSaved('r6'), true);
        assert.deepEqual(calls, [{ url: '/places/r6/save', method: 'POST', csrf: 'tok' }]);
    });

    test('tapping a saved room again removes it', async () => {
        const { calls, fetchImpl } = fakeFetch();
        const places = store(fetchImpl, ['r6']);
        assert.equal(await places.toggle('r6'), false);
        assert.equal(places.isSaved('r6'), false);
        assert.equal(calls[0].method, 'DELETE');
    });

    test('the button changes at once, before the server answers', () => {
        const places = store(() => new Promise(() => {}));
        places.toggle('r6');
        assert.equal(places.isSaved('r6'), true);
    });

    test('a refused or failed save is undone', async () => {
        for (const status of [419, 500, 'offline']) {
            const places = store(fakeFetch(status).fetchImpl, ['r4']);
            await assert.rejects(places.toggle('r6'));
            await assert.rejects(places.toggle('r4'));
            assert.equal(places.isSaved('r6'), false, `save undone after ${status}`);
            assert.equal(places.isSaved('r4'), true, `remove undone after ${status}`);
        }
    });

    test('Start records the room in Recent, and a failure never throws', async () => {
        const { calls, fetchImpl } = fakeFetch();
        assert.equal(await store(fetchImpl).recordRecent('r12'), true);
        assert.deepEqual(calls, [{ url: '/places/r12/recent', method: 'POST', csrf: 'tok' }]);
        assert.equal(await store(fakeFetch('offline').fetchImpl).recordRecent('r12'), false);
    });
});
