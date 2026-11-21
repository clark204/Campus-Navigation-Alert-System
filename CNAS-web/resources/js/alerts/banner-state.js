// When the banner shows an alert. An active alert always shows. The green banner of a resolved alert ("All clear" or
// "False alarm") shows until its banner_expires_at, unless this device closed it or the page hides it (admin console).
export const DISMISSED_LIMIT = 20;

export function bannerAlert(alert, { dismissedIds = [], now = Date.now(), hideResolved = false } = {}) {
    if (!alert) return null;
    if (alert.status === 'active') return alert;
    if (hideResolved || dismissedIds.includes(alert.id)) return null;
    // No expiry sent (an older server): the poll stops returning it, so keep showing it until then.
    return alert.banner_expires_at && Date.parse(alert.banner_expires_at) <= now ? null : alert;
}

// Milliseconds until a shown resolved alert's banner expires, or null when it isn't on a timer.
export function msUntilExpiry(alert, now = Date.now()) {
    return alert?.status === 'resolved' && alert.banner_expires_at ? Math.max(0, Date.parse(alert.banner_expires_at) - now) : null;
}

// "I'm safe" is only asked during an emergency.
export const showsSafeButton = (alert) => alert?.status === 'active' && alert.severity === 'emergency';

// "Show route to safety": in an emergency always; in a warning when the alert has a location; never in an advisory.
export const showsRouteToSafety = (alert) => alert?.status === 'active'
    && (alert.severity === 'emergency' || (alert.severity === 'warning' && alert.has_location === true));

export const rememberDismissed = (dismissedIds, id) => [id, ...dismissedIds.filter((seen) => seen !== id)].slice(0, DISMISSED_LIMIT);
