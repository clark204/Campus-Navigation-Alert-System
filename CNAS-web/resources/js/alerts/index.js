// Emergency alerts on every page (partials/alerts): polls the current alert every 15 seconds, keeps the banner up to
// date, takes over the screen with an alarm once per new alert on this device, handles "I'm safe", the one-time
// sound prompt and browser push. Its own entry point so the map layout, which doesn't load app.js, gets it too.
// The emergency map (resources/js/navigation/main.js) listens for the `cnas:alert` and `cnas:evacuate` events.
import { Alarm } from './alarm.js';
import { bannerAlert, msUntilExpiry, rememberDismissed, showsSafeButton } from './banner-state.js';
import { renderBanner } from './fill.js';
import { firstFix, helpLine, HelpTracker } from './help-tracker.js';
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
    // Alerts this person asked for help in (one answer per alert: the latest one counts).
    const askedForHelp = new Set(root.querySelector('[data-need-help][data-requested]') && current ? [current.id] : []);

    // While their help request is open: share where they are and show "<admin> is on the way" (help-tracker.js).
    let tracker = null;
    const showHelpState = (state) => {
        root.querySelectorAll('[data-help-state]').forEach((line) => {
            line.textContent = helpLine(state);
            line.hidden = false;
        });
        root.querySelectorAll('[data-need-help-status]').forEach((line) => (line.textContent = ''));
        if (state.stopped && current) {
            askedForHelp.delete(current.id);
            renderSafeButtons();
        }
    };
    const startTracking = (alertId, { share, fix = null }) => {
        tracker?.stop();
        tracker = new HelpTracker({
            pingUrl: root.dataset.helpPingUrl.replace('__ALERT__', alertId),
            csrfToken: csrfToken(),
            geolocation: navigator.geolocation,
            onState: showHelpState,
        });
        tracker.start({ share, fix });
    };
    const stopTracking = () => {
        tracker?.stop();
        tracker = null;
    };

    const renderSafeButtons = () => {
        // Only an emergency asks "I'm safe" or "I need help": the buttons are there only then.
        root.querySelectorAll('[data-safety-answers]').forEach((answers) => (answers.hidden = !showsSafeButton(current)));
        root.querySelectorAll('form[data-mark-safe-form]').forEach((form) => {
            if (current?.status === 'active') form.action = root.dataset.safeUrl.replace('__ALERT__', current.id);
            const marked = current !== null && markedSafe.has(current.id);
            const button = form.querySelector('[data-mark-safe]');
            button.toggleAttribute('data-marked', marked);
            button.disabled = marked;
        });
        root.querySelectorAll('[data-need-help]').forEach((details) => {
            const form = details.querySelector('form[data-need-help-form]');
            if (current?.status === 'active') form.action = root.dataset.helpUrl.replace('__ALERT__', current.id);
            details.toggleAttribute('data-requested', current !== null && askedForHelp.has(current.id));
        });
        // The help state line goes with an open request, except "Marked as helped", which stays to be read.
        root.querySelectorAll('[data-help-state]').forEach((line) => {
            const open = current !== null && askedForHelp.has(current.id);
            if (!open && (markedSafe.has(current?.id) || current?.status !== 'active')) line.hidden = true;
        });
    };

    // The banner shows less than the alert: the green one expires, can be closed, and is left out of the admin console.
    // A banner that was on the page from the first render keeps its place. One that shows up later (a poll found a new
    // alert) floats over the top of the page instead, so nothing the person is reading jumps down.
    const bannerInFlow = !banner.hidden;
    let expiryTimer = null;
    const showBanner = (alert) => {
        const shown = bannerAlert(alert, { dismissedIds: load('dismissed', []), hideResolved: 'hideResolved' in root.dataset });
        // Only redraw on a change, so screen readers don't re-announce the same alert.
        if ((shown?.version ?? '') !== banner.dataset.version) renderBanner(banner, shown);
        banner.toggleAttribute('data-floating', !bannerInFlow && !banner.hidden);
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
        // The alert ended or changed: nothing to share any more.
        if (tracker && (alert?.status !== 'active' || alert.id !== current?.id)) stopTracking();
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
    // Came back to a page with their request still open: keep sharing (if they agreed before) and learn its state.
    if (current?.status === 'active' && askedForHelp.has(current.id)) {
        startTracking(current.id, { share: load('helpShare', false) === true });
        tracker.ping();
    }
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
            askedForHelp.delete(alertId);
            stopTracking();
            renderSafeButtons();
        } else if (response?.status !== 409) {
            form.submit();
        }
    });

    // "I need help" with the note: sent in the background like "I'm safe"; it replaces an earlier "I'm safe".
    root.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-need-help-form]');
        if (!form || current?.status !== 'active') return;
        event.preventDefault();
        const alertId = current.id;
        const status = form.querySelector('[data-need-help-status]');
        const button = form.querySelector('button[type="submit"]');
        const share = form.querySelector('[data-help-share]')?.checked ?? false;
        // Where they are, if they agreed and the browser answers in time (the request goes without it otherwise).
        button.disabled = true;
        if (share) status.textContent = 'Finding your location…';
        const { fix, denied } = share ? await firstFix(navigator.geolocation) : { fix: null, denied: false };
        form.querySelector('[data-help-latitude]').value = fix?.latitude ?? '';
        form.querySelector('[data-help-longitude]').value = fix?.longitude ?? '';
        form.querySelector('[data-help-accuracy]').value = fix?.accuracy ?? '';
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
            body: new FormData(form),
        }).catch(() => null);
        button.disabled = false;
        if (response?.ok) {
            const { help } = await response.json();
            askedForHelp.add(alertId);
            markedSafe.delete(alertId);
            save('helpShare', share && !denied);
            startTracking(alertId, { share: share && !denied, fix });
            showHelpState({ help, sharing: fix !== null, denied, stopped: false });
            // The note is sent: fold it away so the state line and the route to safety stay in view.
            root.querySelectorAll('[data-need-help]').forEach((details) => details.removeAttribute('open'));
            renderSafeButtons();
        } else if (response?.status === 422) {
            const { errors } = await response.json();
            status.textContent = errors?.note?.[0] ?? 'Check your message and try again.';
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
