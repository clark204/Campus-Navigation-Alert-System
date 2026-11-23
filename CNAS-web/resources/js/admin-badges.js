// Sidebar badges that stay up to date on admin pages (partials/app-sidebar, items with `live`): the open concerns
// count. Asks its URL every 30 s while the tab is visible, and at once when the tab comes back. The server caches the
// count (ConcernInbox::openCount), so this is cheap.
export const POLL_MS = 30000;

// The badge text and whether it shows, for a count from the server (null keeps what is shown).
export function badgeState(count) {
    if (!Number.isInteger(count) || count < 0) return null;
    return { text: count > 99 ? '99+' : String(count), hidden: count === 0 };
}

export function initLiveBadges(badges, fetchImpl = (...args) => fetch(...args)) {
    const refresh = async (badge) => {
        const response = await fetchImpl(badge.dataset.liveBadge, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }).catch(() => null);
        if (!response?.ok) return;
        const state = badgeState((await response.json().catch(() => ({})))?.count);
        if (!state) return;
        badge.querySelector('[data-live-badge-count]').textContent = state.text;
        badge.hidden = state.hidden;
    };
    const refreshAll = () => {
        if (document.visibilityState === 'visible') badges.forEach(refresh);
    };

    setInterval(refreshAll, POLL_MS);
    document.addEventListener('visibilitychange', refreshAll);
}
