// Admin > Alerts: panels that stay live without a reload. Each asks its URL for the panel's HTML every few seconds while
// the tab is visible (and at once when the tab comes back) and swaps it in: the "I'm safe" panel (admin/alert-safety-
// panel, every 10 s) and the help queue (admin/alert-help-panel, every 5 s). On a failure the last panel stays.
// After each swap the section fires 'live-panel-updated', which the help map (alerts/help-map.js) listens for.
export const POLL_MS = 10000;

export function initLivePanel(section, url, intervalMs = POLL_MS, fetchImpl = (...args) => fetch(...args)) {
    let busy = false;
    const refresh = async () => {
        if (busy || document.visibilityState !== 'visible') return;
        busy = true;
        try {
            const response = await fetchImpl(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            // Our own Blade partial from this site (names escaped by Blade), admin-only.
            if (response.ok) {
                section.innerHTML = await response.text();
                section.dispatchEvent(new CustomEvent('live-panel-updated', { bubbles: true }));
            }
        } catch {
            // Offline for a moment: keep what is shown.
        } finally {
            busy = false;
        }
    };

    setInterval(refresh, intervalMs);
    document.addEventListener('visibilitychange', refresh);
    // Others (an admin's own "I'm responding") can ask for a refresh now.
    section.addEventListener('live-panel-refresh', refresh);
    return refresh;
}

export function initSafetyLive(section, fetchImpl) {
    return initLivePanel(section, section.dataset.safetyLive, POLL_MS, fetchImpl);
}
