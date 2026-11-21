// Admin Settings → System status → Push subscribers: keeps the count and its OK / Needs attention badge current
// without a reload. Asks the server (admin.settings.push-subscribers, the database count) every 10 seconds, only while
// the System status tab is open and the browser tab is visible; right away when the tab opens, the page comes back
// into view, the connection returns, or this browser turns alerts on or off. After a failed request it waits longer
// each time (up to a minute) and says so. Polling, not a held-open stream: see Admin\SystemStatusController.

export const POLL_MS = 10_000;
export const MAX_RETRY_MS = 60_000;

/** Milliseconds until the next request: POLL_MS normally, doubling after each failure in a row, up to MAX_RETRY_MS. */
export function nextDelay(failures) {
    return Math.min(POLL_MS * 2 ** failures, MAX_RETRY_MS);
}

const timeFormat = new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' });

export function initPushSubscribersLive(item) {
    const panel = item.closest('[role="tabpanel"]');
    const summary = item.querySelector('[data-check-summary]');
    const fix = item.querySelector('[data-check-fix]');
    const badges = { ok: item.querySelector('[data-badge="ok"]'), attention: item.querySelector('[data-badge="attention"]') };
    const state = item.querySelector('[data-live-state]');
    let timer = null;
    let failures = 0;
    let busy = false;
    let stopped = false;

    const isWatched = () => document.visibilityState === 'visible' && !panel?.hidden;
    const showState = (text, tone = 'muted') => {
        state.textContent = text;
        state.dataset.tone = tone;
    };
    const apply = (data) => {
        badges.ok.hidden = !data.ok;
        badges.attention.hidden = data.ok;
        // Only changed text is written, so screen readers (aria-live) hear the count when it changes, not every poll.
        if (summary.textContent !== data.summary) summary.textContent = data.summary;
        fix.textContent = data.fix ?? '';
        fix.hidden = data.ok || !data.fix;
    };

    const refresh = async () => {
        clearTimeout(timer);
        if (stopped || busy || !isWatched()) return; // Resumes when the tab or page is shown again.
        busy = true;
        if (failures > 0) showState('Reconnecting…', 'warning');
        try {
            const response = await fetch(item.dataset.url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (response.status === 401 || response.status === 403 || response.status === 419) {
                stopped = true;
                showState('Your session has ended. Reload the page to sign in again and keep this count live.', 'error');
                return;
            }
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            apply(data);
            failures = 0;
            showState(`Live. Updated ${timeFormat.format(new Date(data.checked_at))}.`);
        } catch {
            failures += 1;
            showState(`Can't reach the server, so this count may be out of date. Trying again in ${Math.round(nextDelay(failures) / 1000)} s.`, 'error');
        } finally {
            busy = false;
            if (!stopped && isWatched()) timer = setTimeout(refresh, nextDelay(failures));
        }
    };

    panel?.addEventListener('settings:tab-shown', refresh);
    document.addEventListener('visibilitychange', refresh);
    window.addEventListener('online', refresh);
    document.addEventListener('cnas:push-subscription-changed', refresh);

    // The page already shows the count as of loading; the first request comes one interval later.
    showState('Live. Updates every 10 seconds while this tab is open.');
    if (isWatched()) timer = setTimeout(refresh, POLL_MS);
}
