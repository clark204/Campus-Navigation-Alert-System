import { isStandalone, pushSupport } from './device.js';
import { load, save } from './storage.js';

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content ?? '';

const MESSAGES = {
    failed: "Emergency alerts couldn't be turned on. Check your connection and try again.",
    permissionDismissed: 'Allow notifications when your browser asks, to turn on emergency alerts.',
};

// VAPID keys are base64url; PushManager.subscribe wants the raw bytes.
export function urlBase64ToUint8Array(base64) {
    const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(padded), (char) => char.charCodeAt(0));
}

// The body for push-subscriptions.store. Browsers that don't list aes128gcm only speak the older aesgcm.
export function subscriptionPayload(subscription, supportedEncodings = []) {
    const { endpoint, keys } = subscription.toJSON();
    return { endpoint, keys, content_encoding: supportedEncodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm' };
}

/*
 * Registers the service worker (also what makes CNAS installable) and drives every [data-push-alerts] section:
 * "Turn on emergency alerts", "Turn off", and the iPhone Home Screen steps. An existing subscription is saved again
 * when someone signs in or out on this browser, so it follows the account. `onTurnOn` runs inside the tap.
 */
export async function initPush({ swUrl, saveUrl, onTurnOn }) {
    const sections = [...document.querySelectorAll('[data-push-alerts]')];
    const vapidKey = meta('vapid-public-key');
    const subscriber = meta('push-subscriber');
    const support = pushSupport({
        navigator,
        standalone: isStandalone(window),
        hasPushManager: 'PushManager' in window,
        hasNotification: 'Notification' in window,
        vapidKey,
    });

    if ('serviceWorker' in navigator) {
        await navigator.serviceWorker.register(swUrl, { scope: '/' }).catch(() => null);
    }

    const send = async (method, body) => {
        const response = await fetch(saveUrl, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': meta('csrf-token') },
            body: JSON.stringify(body),
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
    };
    const saveSubscription = async (subscription) => {
        await send('POST', subscriptionPayload(subscription, PushManager.supportedContentEncodings ?? []));
        save('synced', `${subscriber}|${subscription.endpoint}`);
    };
    // Lets the page react to this browser turning alerts on or off (admin Settings refreshes its subscriber count).
    const announceChange = () => document.dispatchEvent(new CustomEvent('cnas:push-subscription-changed'));
    const subscription = async () =>
        support === 'ok' ? (await navigator.serviceWorker.ready).pushManager.getSubscription() : null;

    const render = (state, error = '') => {
        for (const section of sections) {
            const hideStates = (section.dataset.pushHideStates ?? '').split(' ');
            section.dataset.state = state;
            section.hidden =
                hideStates.includes(state) || (section.hasAttribute('data-push-dismissible') && load('push-dismissed', false));
            const errorEl = section.querySelector('[data-push-error]');
            errorEl.textContent = error;
            errorEl.hidden = !error;
            section.querySelector('[data-push-turn-on]').disabled = state === 'working';
        }
    };

    const currentState = async () => {
        if (support !== 'ok') return support;
        if (Notification.permission === 'denied') return 'denied';
        return Notification.permission === 'granted' && (await subscription()) ? 'on' : 'off';
    };

    // Keep the server's copy linked to whoever uses this browser now.
    if (support === 'ok' && Notification.permission === 'granted') {
        const existing = await subscription().catch(() => null);
        if (existing && load('synced') !== `${subscriber}|${existing.endpoint}`) {
            await saveSubscription(existing).catch(() => {});
        }
    }

    if (!sections.length) return;
    render(await currentState().catch(() => 'unsupported'));

    const turnOn = async () => {
        onTurnOn?.();
        // Asked first, inside the tap: Safari ignores the request after an await.
        const permissionRequest = Notification.requestPermission();
        render('working');
        const permission = await permissionRequest;
        if (permission !== 'granted') {
            render(permission === 'denied' ? 'denied' : 'off', permission === 'denied' ? '' : MESSAGES.permissionDismissed);
            return;
        }
        try {
            const registration = await navigator.serviceWorker.ready;
            const created =
                (await registration.pushManager.getSubscription()) ??
                (await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidKey),
                }));
            await saveSubscription(created);
            render('on');
            announceChange();
        } catch {
            render('off', MESSAGES.failed);
        }
    };

    const turnOff = async () => {
        render('working');
        try {
            const existing = await subscription();
            if (existing) {
                await send('DELETE', { endpoint: existing.endpoint }).catch(() => {});
                await existing.unsubscribe();
            }
            save('synced', null);
            render('off');
            announceChange();
        } catch {
            render(await currentState());
        }
    };

    for (const section of sections) {
        section.querySelector('[data-push-turn-on]').addEventListener('click', turnOn);
        section.querySelector('[data-push-turn-off]').addEventListener('click', turnOff);
        section.querySelector('[data-push-dismiss]')?.addEventListener('click', () => {
            save('push-dismissed', true);
            section.hidden = true;
        });
    }
}
