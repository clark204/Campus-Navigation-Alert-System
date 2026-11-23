// Help requests: the admin queue's data (alerts/help-data.js) and the person's check-in (alerts/help-tracker.js).
import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import { describeWhere, newRequestIds, readHelpPoints, titleWithCount } from '../../resources/js/alerts/help-data.js';
import { firstFix, helpLine, HelpTracker, PING_MS } from '../../resources/js/alerts/help-tracker.js';

// Small squares near the campus (about 110 m a side), so distances are realistic.
const square = (lng, lat, size = 0.001) => [[lng, lat], [lng + size, lat], [lng + size, lat + size], [lng, lat + size], [lng, lat]];

describe('help queue data', () => {
    test('reads the points the panel carries, and survives a broken one', () => {
        const root = (text) => ({ querySelector: () => (text === null ? null : { textContent: text }) });

        assert.deepEqual(readHelpPoints(root('{"open":2,"requests":[{"id":1}]}')), { open: 2, requests: [{ id: 1 }] });
        assert.deepEqual(readHelpPoints(root('not json')), { open: 0, requests: [] });
        assert.deepEqual(readHelpPoints(root(null)), { open: 0, requests: [] });
    });

    test('only open requests that were not there before are new, and never on the first load', () => {
        const requests = [{ id: 1, status: 'new' }, { id: 2, status: 'responding' }, { id: 3, status: 'helped' }];

        assert.deepEqual(newRequestIds(new Set(), requests, true), []);
        assert.deepEqual(newRequestIds(new Set([1]), requests, false), [2]);
        assert.deepEqual(newRequestIds(new Set([1, 2]), requests, false), []);
    });

    test('says which building the person is in and how far from the incident', () => {
        const buildings = [{ id: 'lib', name: 'Library', type: 'building', ring: square(124.748, 8.54) }];
        const inLibrary = { latitude: 8.5405, longitude: 124.7485 };

        assert.equal(describeWhere(inLibrary, buildings, [124.7485, 8.5405]), 'In Library, at the incident');
        assert.match(describeWhere(inLibrary, buildings, [124.7485, 8.5415]), /^In Library, 1\d\d m from the incident$/);
        assert.equal(describeWhere({ latitude: 8.55, longitude: 124.76 }, buildings, null), 'Outside');
        assert.equal(describeWhere({ latitude: null, longitude: null }, buildings, null), null);
    });

    test('the tab title counts who waits', () => {
        assert.equal(titleWithCount('Emergency alerts', 2), '(2) need help · Emergency alerts');
        assert.equal(titleWithCount('Emergency alerts', 0), 'Emergency alerts');
    });
});

function fakeGeolocation({ deny = false } = {}) {
    const watchers = new Map();
    let nextId = 1;
    return {
        cleared: [],
        getCurrentPosition(ok, fail) {
            if (deny) fail({ code: 1 });
            else ok({ coords: { latitude: 8.54, longitude: 124.75, accuracy: 12.4 } });
        },
        watchPosition(ok, fail) {
            const id = nextId++;
            watchers.set(id, { ok, fail });
            return id;
        },
        clearWatch(id) {
            this.cleared.push(id);
            watchers.delete(id);
        },
        move(latitude, longitude) {
            watchers.forEach(({ ok }) => ok({ coords: { latitude, longitude, accuracy: 5 } }));
        },
        watching: () => watchers.size,
    };
}

function fakeTimers() {
    const intervals = new Map();
    let nextId = 1;
    return {
        setInterval(fn, ms) {
            intervals.set(nextId, { fn, ms });
            return nextId++;
        },
        clearInterval(id) {
            intervals.delete(id);
        },
        intervals,
    };
}

const reply = (status, body) => Promise.resolve({ ok: status >= 200 && status < 300, status, json: async () => body });

describe('help tracker', () => {
    test('the first position is rounded, and a refusal is reported', async () => {
        assert.deepEqual(await firstFix(fakeGeolocation()), { fix: { latitude: 8.54, longitude: 124.75, accuracy: 12 }, denied: false });
        assert.deepEqual(await firstFix(fakeGeolocation({ deny: true })), { fix: null, denied: true });
        assert.deepEqual(await firstFix(undefined), { fix: null, denied: false });
    });

    test('pings every 15 s with the latest position and reports who is on the way', async () => {
        const geolocation = fakeGeolocation();
        const timers = fakeTimers();
        const sent = [];
        const states = [];
        const tracker = new HelpTracker({
            pingUrl: '/alerts/7/help/ping',
            csrfToken: 'token',
            geolocation,
            timers,
            fetchImpl: (url, options) => {
                sent.push([url, JSON.parse(options.body), options.headers['X-CSRF-TOKEN']]);
                return reply(200, { help: { status: 'responding', label: 'Rita Reyes is on the way.' } });
            },
            onState: (state) => states.push(state),
        });

        tracker.start({ share: true });
        assert.equal([...timers.intervals.values()][0].ms, PING_MS);
        geolocation.move(8.541, 124.749);
        await tracker.ping();

        assert.deepEqual(sent, [['/alerts/7/help/ping', { latitude: 8.541, longitude: 124.749, accuracy: 5 }, 'token']]);
        assert.equal(states.at(-1).help.label, 'Rita Reyes is on the way.');
        assert.equal(states.at(-1).sharing, true);
    });

    test('without sharing, the ping sends no position', async () => {
        const sent = [];
        const tracker = new HelpTracker({
            pingUrl: '/ping',
            geolocation: fakeGeolocation(),
            timers: fakeTimers(),
            fetchImpl: (url, options) => {
                sent.push(JSON.parse(options.body));
                return reply(200, { help: { status: 'new' } });
            },
        });

        tracker.start({ share: false });
        await tracker.ping();

        assert.deepEqual(sent, [{}]);
    });

    test('a 409 stops watching and pinging', async () => {
        const geolocation = fakeGeolocation();
        const timers = fakeTimers();
        const states = [];
        const tracker = new HelpTracker({
            pingUrl: '/ping',
            geolocation,
            timers,
            fetchImpl: () => reply(409, { help: { status: 'helped', label: 'Marked as helped. Stay safe.' } }),
            onState: (state) => states.push(state),
        });

        tracker.start({ share: true, fix: { latitude: 1, longitude: 2, accuracy: 3 } });
        await tracker.ping();

        assert.equal(geolocation.watching(), 0);
        assert.equal(timers.intervals.size, 0);
        assert.equal(states.at(-1).stopped, true);
        assert.equal(helpLine(states.at(-1)), 'Marked as helped. Stay safe.');
    });

    test('a helped answer stops it too', async () => {
        const timers = fakeTimers();
        const tracker = new HelpTracker({ pingUrl: '/ping', geolocation: fakeGeolocation(), timers, fetchImpl: () => reply(200, { help: { status: 'helped' } }) });

        tracker.start({ share: true });
        await tracker.ping();

        assert.equal(timers.intervals.size, 0);
    });

    test('an offline moment keeps it running', async () => {
        const timers = fakeTimers();
        const tracker = new HelpTracker({ pingUrl: '/ping', geolocation: fakeGeolocation(), timers, fetchImpl: () => Promise.reject(new Error('offline')) });

        tracker.start({ share: true });
        await tracker.ping();

        assert.equal(timers.intervals.size, 1);
    });

    test('the line says whether the location is shared', () => {
        const help = { status: 'new', label: 'Sent. The administrators can see your request.' };

        assert.equal(helpLine({ help, sharing: true, denied: false }), 'Sent. The administrators can see your request. Sharing your location while this page is open.');
        assert.equal(helpLine({ help, sharing: false, denied: true }), 'Sent. The administrators can see your request. Location is off. Add where you are in the note.');
    });
});
