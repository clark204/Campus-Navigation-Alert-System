import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { bannerAlert, msUntilExpiry, rememberDismissed, showsRouteToSafety, showsSafeButton } from '../../resources/js/alerts/banner-state.js';
import { isIos, pushSupport } from '../../resources/js/alerts/device.js';
import { subscriptionPayload, urlBase64ToUint8Array } from '../../resources/js/alerts/push.js';
import { rememberSeen, SEEN_LIMIT, shouldPopUp, shouldTakeOver } from '../../resources/js/alerts/takeover-state.js';

const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1';
const ANDROID = 'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';

describe('alarm takeover', () => {
    test('a new active emergency takes over once per device', () => {
        const alert = { id: 7, status: 'active', severity: 'emergency' };
        assert.equal(shouldTakeOver(alert, []), true);
        assert.equal(shouldTakeOver(alert, rememberSeen([], 7)), false);
    });

    test('resolved alerts and no alert never take over', () => {
        assert.equal(shouldTakeOver({ id: 7, status: 'resolved', severity: 'emergency' }, []), false);
        assert.equal(shouldTakeOver(null, []), false);
    });

    test('only an emergency takes over: an advisory or a warning does not', () => {
        assert.equal(shouldTakeOver({ id: 8, status: 'active', severity: 'advisory' }, []), false);
        assert.equal(shouldTakeOver({ id: 9, status: 'active', severity: 'warning' }, []), false);
    });

    test('the remembered list keeps the newest alerts only', () => {
        let seen = [];
        for (let id = 1; id <= SEEN_LIMIT + 5; id++) seen = rememberSeen(seen, id);
        assert.equal(seen.length, SEEN_LIMIT);
        assert.equal(seen[0], SEEN_LIMIT + 5);
        assert.deepEqual(rememberSeen([3, 2, 1], 2), [2, 3, 1]);
    });
});

describe('push support', () => {
    const ready = { standalone: false, hasPushManager: true, hasNotification: true, vapidKey: 'BKey' };
    const browser = (userAgent, extra = {}) => ({ userAgent, platform: '', maxTouchPoints: 0, serviceWorker: {}, ...extra });

    test('an iPhone outside the Home Screen app is told to add CNAS to it', () => {
        assert.equal(pushSupport({ ...ready, navigator: browser(IPHONE), hasPushManager: false }), 'ios');
        assert.equal(pushSupport({ ...ready, navigator: browser(IPHONE), standalone: true }), 'ok');
    });

    test('an iPad reporting itself as a Mac is still an iPad', () => {
        assert.equal(isIos({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 5 }), true);
        assert.equal(isIos({ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 0 }), false);
    });

    test('Android Chrome can turn alerts on directly; browsers without push or a server key cannot', () => {
        assert.equal(pushSupport({ ...ready, navigator: browser(ANDROID) }), 'ok');
        assert.equal(pushSupport({ ...ready, navigator: browser(ANDROID), hasPushManager: false }), 'unsupported');
        assert.equal(pushSupport({ ...ready, navigator: browser(ANDROID), vapidKey: '' }), 'unsupported');
    });
});

describe('push subscription', () => {
    test('the VAPID key is decoded from base64url', () => {
        assert.deepEqual([...urlBase64ToUint8Array('AQID_-8')], [1, 2, 3, 255, 239]);
    });

    test('the payload sends the endpoint, keys and the encoding the browser supports', () => {
        const subscription = { toJSON: () => ({ endpoint: 'https://fcm.googleapis.com/x', keys: { p256dh: 'p', auth: 'a' }, expirationTime: null }) };
        assert.deepEqual(subscriptionPayload(subscription, ['aes128gcm', 'aesgcm']), {
            endpoint: 'https://fcm.googleapis.com/x',
            keys: { p256dh: 'p', auth: 'a' },
            content_encoding: 'aes128gcm',
        });
        assert.equal(subscriptionPayload(subscription, ['aesgcm']).content_encoding, 'aesgcm');
    });
});

describe('green banner', () => {
    const resolved = (extra = {}) => ({ id: 4, status: 'resolved', banner_expires_at: '2026-11-08T10:15:00+00:00', ...extra });
    const before = Date.parse('2026-11-08T10:14:59+00:00');
    const after = Date.parse('2026-11-08T10:15:00+00:00');

    test('shows until it expires, then hides', () => {
        assert.equal(bannerAlert(resolved(), { now: before })?.id, 4);
        assert.equal(bannerAlert(resolved(), { now: after }), null);
        assert.equal(msUntilExpiry(resolved(), before), 1000);
        assert.equal(msUntilExpiry({ id: 4, status: 'active' }), null);
    });

    test('stays hidden once this device closed it, but only for that alert', () => {
        assert.equal(bannerAlert(resolved(), { now: before, dismissedIds: rememberDismissed([], 4) }), null);
        assert.equal(bannerAlert(resolved({ id: 5 }), { now: before, dismissedIds: [4] })?.id, 5);
    });

    test('the admin console leaves it out; an active alert always shows', () => {
        assert.equal(bannerAlert(resolved(), { now: before, hideResolved: true }), null);
        const active = { id: 4, status: 'active' };
        assert.equal(bannerAlert(active, { now: after, dismissedIds: [4], hideResolved: true }), active);
        assert.equal(bannerAlert(null), null);
    });
});

describe('severity: warning popup', () => {
    test('a warning pops up once per device', () => {
        const alert = { id: 3, status: 'active', severity: 'warning' };
        assert.equal(shouldPopUp(alert, []), true);
        assert.equal(shouldPopUp(alert, rememberSeen([], 3)), false);
    });

    test('an advisory and an emergency never pop up (an emergency takes over instead)', () => {
        assert.equal(shouldPopUp({ id: 4, status: 'active', severity: 'advisory' }, []), false);
        assert.equal(shouldPopUp({ id: 5, status: 'active', severity: 'emergency' }, []), false);
    });

    test('a resolved warning does not pop up', () => {
        assert.equal(shouldPopUp({ id: 6, status: 'resolved', severity: 'warning' }, []), false);
    });
});

describe('severity: what the banner offers', () => {
    test("I'm safe is only offered during an active emergency", () => {
        assert.equal(showsSafeButton({ status: 'active', severity: 'emergency' }), true);
        assert.equal(showsSafeButton({ status: 'active', severity: 'warning' }), false);
        assert.equal(showsSafeButton({ status: 'active', severity: 'advisory' }), false);
        assert.equal(showsSafeButton({ status: 'resolved', severity: 'emergency' }), false);
    });

    test('an emergency always offers the route to safety', () => {
        assert.equal(showsRouteToSafety({ status: 'active', severity: 'emergency', has_location: false }), true);
    });

    test('a warning offers the route only when the alert has a location', () => {
        assert.equal(showsRouteToSafety({ status: 'active', severity: 'warning', has_location: true }), true);
        assert.equal(showsRouteToSafety({ status: 'active', severity: 'warning', has_location: false }), false);
    });

    test('an advisory never offers the route to safety', () => {
        assert.equal(showsRouteToSafety({ status: 'active', severity: 'advisory', has_location: true }), false);
    });
});
