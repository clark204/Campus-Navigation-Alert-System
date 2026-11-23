// Admin > Alerts: the "I'm safe" panel (admin/alert-safety-panel) stays live. Every 10 s while the tab is visible it
// asks admin.alerts.safety for the panel's HTML and swaps it in, so new "I'm safe" and "I need help" answers show
// without a reload. Also at once when the tab comes back. On a failure the last panel stays.
export const POLL_MS = 10000;

export function initSafetyLive(section, fetchImpl = (...args) => fetch(...args)) {
    let busy = false;
    const refresh = async () => {
        if (busy || document.visibilityState !== 'visible') return;
        busy = true;
        try {
            const response = await fetchImpl(section.dataset.safetyLive, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            // Our own Blade partial from this site (names escaped by Blade), admin-only.
            if (response.ok) section.innerHTML = await response.text();
        } catch {
            // Offline for a moment: keep what is shown.
        } finally {
            busy = false;
        }
    };

    setInterval(refresh, POLL_MS);
    document.addEventListener('visibilitychange', refresh);
    return refresh;
}
