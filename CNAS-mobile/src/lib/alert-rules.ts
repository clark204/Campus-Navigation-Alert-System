// Which alert shows what, ported from CNAS-web (resources/js/alerts/takeover-state.js and banner-state.js) so the
// app and the site behave the same (docs/alerts.md):
// - advisory: banner only
// - warning: banner, plus a one-time popup with a chime
// - emergency: full-screen takeover with a looping alarm and vibration, then the banner, and "I'm safe"
import type { Alert } from './types';

export const SEEN_LIMIT = 20;

/** What this phone remembers as "already shown": the alert's version (id + status + last change). */
export const seenKey = (alert: Alert) => alert.version;

export const isEmergency = (alert: Alert | null) => alert?.severity === 'emergency';

export const shouldTakeOver = (alert: Alert | null, seen: string[]) =>
  alert !== null && alert.status === 'active' && alert.severity === 'emergency' && !seen.includes(seenKey(alert));

export const shouldPopUp = (alert: Alert | null, seen: string[]) =>
  alert !== null && alert.status === 'active' && alert.severity === 'warning' && !seen.includes(seenKey(alert));

/** "I'm safe" is only asked during an active emergency (the server refuses other levels). */
export const showsSafeButton = (alert: Alert | null) => alert?.status === 'active' && alert.severity === 'emergency';

/**
 * The alert the banner shows, or null. An active alert always shows. A resolved one ("All clear" or "False alarm")
 * shows until its banner_expires_at, unless this phone closed it.
 */
export function bannerAlert(alert: Alert | null, dismissedIds: number[], now = Date.now()): Alert | null {
  if (!alert) return null;
  if (alert.status === 'active') return alert;
  if (dismissedIds.includes(alert.id)) return null;
  return alert.banner_expires_at && Date.parse(alert.banner_expires_at) <= now ? null : alert;
}

/** Milliseconds until a resolved alert's green banner expires, or null when it isn't on a timer. */
export function msUntilExpiry(alert: Alert | null, now = Date.now()): number | null {
  return alert?.status === 'resolved' && alert.banner_expires_at ? Math.max(0, Date.parse(alert.banner_expires_at) - now) : null;
}

export const remember = <T,>(list: T[], item: T, limit = SEEN_LIMIT): T[] => [item, ...list.filter((x) => x !== item)].slice(0, limit);
