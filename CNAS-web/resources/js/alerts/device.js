// iPhones and iPads only get web push from a Home Screen app (iOS 16.4+). iPadOS reports itself as a Mac with touch.
export function isIos({ userAgent = '', platform = '', maxTouchPoints = 0 } = {}) {
    return /iPad|iPhone|iPod/.test(userAgent) || (platform === 'MacIntel' && maxTouchPoints > 1);
}

/*
 * What "Turn on emergency alerts" can do on this device:
 *   'ios'         iPhone/iPad not opened from the Home Screen: show the Add to Home Screen steps
 *   'unsupported' no service worker, push or notifications here (or the server has no VAPID key)
 *   'ok'          push can be turned on
 */
export function pushSupport({ navigator: nav, standalone, hasPushManager, hasNotification, vapidKey }) {
    if (isIos(nav) && !standalone) return 'ios';
    if (!vapidKey || !('serviceWorker' in nav) || !hasPushManager || !hasNotification) return 'unsupported';
    return 'ok';
}

export const isStandalone = (win) => win.navigator.standalone === true || win.matchMedia?.('(display-mode: standalone)').matches === true;
