// Emergency alerts on every page (partials/alerts): polls the current alert every 15 seconds, keeps the banner up to
// date, takes over the screen with an alarm once per new alert on this device, handles "I'm safe", the one-time
// sound prompt and browser push. Its own entry point so the map layout, which doesn't load app.js, gets it too.
// The emergency map (resources/js/navigation/main.js) listens for the `cnas:alert` and `cnas:evacuate` events.
import { Alarm } from './alarm.js';
import { bannerAlert, msUntilExpiry, rememberDismissed, showsSafeButton } from './banner-state.js';
import { renderBanner } from './fill.js';
import { initPush } from './push.js';
import { load, save } from './storage.js';
import { Popup } from './popup.js';
import { Takeover } from './takeover.js';
import { rememberSeen, shouldPopUp, shouldTakeOver } from './takeover-state.js';

const POLL_MS = 15000;

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function init(root) {
    const banner = root.querySelector('[data-alert-banner]');
    const takeoverEl = root.querySelector('[data-alert-takeover]');
    const alarm = new Alarm({ alarmUrl: root.dataset.alarmSound, drillUrl: root.dataset.drillSound, chimeUrl: root.dataset.chimeSound });
    const takeover = takeoverEl ? new Takeover(takeoverEl, alarm) : null;
    const popupEl = root.querySelector('[data-alert-popup]');
    const popup = popupEl ? new Popup(popupEl, alarm) : null;
    const onEmergencyMap = () => document.querySelector('[data-nav]') !== null;

    let current = JSON.parse(root.querySelector('[data-alert-initial]').textContent || 'null');
    // Alerts this person marked themselves safe for (the server rendered the first one).
    const markedSafe = new Set(root.querySelector('[data-mark-safe][data-marked]') && current ? [current.id] : []);

    const renderSafeButtons = () => {
        root.querySelectorAll('form[data-mark-safe-form]').forEach((form) => {
            if (current?.status === 'active') form.action = root.dataset.safeUrl.replace('__ALERT__', current.id);
            // Only an emergency asks "I'm safe": the button is there only then.
            form.hidden = !showsSafeButton(current);
            const marked = current !== null && markedSafe.has(current.id);
            const button = form.querySelector('[data-mark-safe]');
            button.toggleAttribute('data-marked', marked);
            button.disabled = marked;
        });
    };

    // The banner shows less than the alert: the green one expires, can be closed, and is left out of the admin console.
    let expiryTimer = null;
    const showBanner = (alert) => {
        const shown = bannerAlert(alert, { dismissedIds: load('dismissed', []), hideResolved: 'hideResolved' in root.dataset });
        // Only redraw on a change, so screen readers don't re-announce the same alert.
        if ((shown?.version ?? '') !== banner.dataset.version) renderBanner(banner, shown);
        // Hide it on time even if the next poll is late (or the device is offline).
        clearTimeout(expiryTimer);
        const wait = msUntilExpiry(shown);
        if (wait !== null) expiryTimer = setTimeout(() => showBanner(current), wait + 250);
    };
    banner.querySelector('[data-dismiss-banner]')?.addEventListener('click', () => {
        if (current) save('dismissed', rememberDismissed(load('dismissed', []), current.id));
        showBanner(current);
    });

    const apply = (alert) => {
        current = alert;
        window.cnasAlert = alert;
        window.dispatchEvent(new CustomEvent('cnas:alert', { detail: alert }));
        showBanner(alert);
        renderSafeButtons();

        if (!takeover) return;
        const seen = load('seen', []);
        if (shouldTakeOver(alert, seen)) {
            save('seen', rememberSeen(seen, alert.id));
            takeover.show(alert);
        } else if (takeover.open) {
            if (alert?.status === 'active' && alert.id === takeover.alert.id) takeover.update(alert);
            else takeover.hide();
        }

        // A warning pops up once per device, with its chime; it closes when the alert ends or becomes an emergency.
        if (!popup) return;
        const popupSeen = load('popups', []);
        if (shouldPopUp(alert, popupSeen)) {
            save('popups', rememberSeen(popupSeen, alert.id));
            popup.show(alert);
        } else if (popup.open) {
            if (alert?.status === 'active' && alert.severity === 'warning' && alert.id === popup.alert.id) popup.update(alert);
            else popup.hide();
        }
    };

    const poll = async () => {
        try {
            const response = await fetch(root.dataset.pollUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (response.ok) apply((await response.json()).data);
        } catch {
            // Offline or the server is down: keep showing what we have and try again on the next poll.
        }
    };

    apply(current);
    setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', () => !document.hidden && poll());
    // A push arrived (public/sw.js): check now rather than at the next poll.
    navigator.serviceWorker?.addEventListener('message', (event) => event.data?.type === 'cnas:alert-push' && poll());

    // Any first tap or key press lets the alarm play later; the sound prompt exists to get that tap.
    const unlock = () => alarm.unlock();
    document.addEventListener('pointerdown', unlock, { once: true, capture: true });
    document.addEventListener('keydown', unlock, { once: true, capture: true });

    const soundPrompt = root.querySelector('[data-sound-prompt]');
    if (soundPrompt && load('sound') === null) soundPrompt.hidden = false;
    soundPrompt?.addEventListener('click', (event) => {
        const choice = event.target.closest('[data-sound-choice]')?.dataset.soundChoice;
        if (!choice) return;
        if (choice === 'on') alarm.unlock();
        save('sound', choice);
        soundPrompt.hidden = true;
    });

    // "Show route to safety": on the emergency map it starts the route in place (inside this tap, so voice works).
    root.addEventListener('click', (event) => {
        if (!event.target.closest('[data-route-to-safety]')) return;
        alarm.stop();
        if (onEmergencyMap()) {
            event.preventDefault();
            takeover?.hide();
            window.dispatchEvent(new CustomEvent('cnas:evacuate'));
        }
    });

    // "I'm safe": sent in the background; if that fails the form is posted the ordinary way.
    root.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-mark-safe-form]');
        if (!form || current?.status !== 'active') return;
        event.preventDefault();
        const alertId = current.id;
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        }).catch(() => null);
        if (response?.ok) {
            markedSafe.add(alertId);
            renderSafeButtons();
        } else if (response?.status !== 409) {
            form.submit();
        }
    });

    initPush({
        swUrl: root.dataset.swUrl,
        saveUrl: root.dataset.pushUrl,
        // Turning on alerts is a tap too: let the alarm sound from now on.
        onTurnOn: () => {
            alarm.unlock();
            if (load('sound') === null) save('sound', 'on');
            if (soundPrompt) soundPrompt.hidden = true;
        },
    }).catch((error) => console.error('Emergency alert notifications failed to load', error));
}

const root = document.querySelector('[data-alerts]');
if (root) init(root);
