// Which alert shows what, ported from CNAS-web (resources/js/alerts/takeover-state.js and banner-state.js) so the
// app and the site behave the same (docs/alerts.md):
// - advisory: a yellow toast that closes by itself (the web: a yellow banner)
// - warning: an orange toast with a chime that stays until closed (the web: banner and popup)
// - emergency: full-screen takeover with a looping alarm and vibration, then the red pill, and "I'm safe"
// - its end ("All clear" or "False alarm"): a green toast that closes by itself
// Each shows once per alert version on this phone. Every alert stays on the Alerts screen.
import type { Alert } from './types';

export const SEEN_LIMIT = 20;

/** What this phone remembers as "already shown": the alert's version (id + status + last change). */
export const seenKey = (alert: Alert) => alert.version;

export const isEmergency = (alert: Alert | null) => alert?.severity === 'emergency';

export const shouldTakeOver = (alert: Alert | null, seen: string[]) =>
  alert !== null && alert.status === 'active' && alert.severity === 'emergency' && !seen.includes(seenKey(alert));

/** A toast for anything but an active emergency (that has the takeover and the pill), once per version. */
export const shouldToast = (alert: Alert | null, seen: string[]) =>
  alert !== null && !(alert.status === 'active' && alert.severity === 'emergency') && !seen.includes(seenKey(alert));

/** The red pill at the top: an active emergency whose takeover is closed. Gone once it ends. */
export const showsPill = (alert: Alert | null) => alert?.status === 'active' && alert.severity === 'emergency';

/** "EMERGENCY · FIRE", "DRILL · FIRE", "WARNING · FLOOD"; once over "ALL CLEAR · FIRE" or "DRILL OVER · FIRE". Same as the push. */
export function alertKind(alert: Alert): string {
  const type = alert.type_label.toUpperCase();
  if (alert.status === 'resolved') return `${alert.is_drill ? 'DRILL OVER' : (alert.resolution_label ?? 'All clear').toUpperCase()} · ${type}`;
  return `${alert.is_drill ? 'DRILL' : alert.severity_label.toUpperCase()} · ${type}`;
}

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
