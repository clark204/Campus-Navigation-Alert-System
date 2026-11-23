import { showsRouteToSafety } from './banner-state.js';

/** "EMERGENCY · FIRE", or "DRILL · FIRE" for a drill: the same words as the push and the app. */
export function alertKind(alert) {
    if (!alert) return '';
    const level = alert.is_drill ? 'DRILL' : String(alert.severity_label ?? alert.severity ?? '').toUpperCase();
    return `${level} · ${String(alert.type_label ?? '').toUpperCase()}`;
}

/** "Sent at 2:14 PM" in the device's own clock. */
export function sentTime(alert, locale = undefined) {
    if (!alert?.sent_at) return '';
    return `Sent at ${new Date(alert.sent_at).toLocaleTimeString(locale, { hour: 'numeric', minute: '2-digit' })}`;
}

// Fields worked out from the alert rather than read from it.
const DERIVED = { kind: alertKind, sent_time: sentTime };

// Hidden when empty, so no blank box shows.
const HIDE_WHEN_EMPTY = new Set(['instructions', 'what_to_do']);

// Fills an alert into server-rendered markup (banner, takeover): [data-field="title"] gets alert.title, and so on.
// Empty instructions and "What to do now" are hidden; [data-type-icon] shows the icon for the alert's type.
export function fillAlert(root, alert) {
    root.querySelectorAll('[data-field]').forEach((field) => {
        const name = field.dataset.field;
        const value = DERIVED[name] ? DERIVED[name](alert) : (alert?.[name] ?? '');
        field.textContent = value;
        if (HIDE_WHEN_EMPTY.has(name)) field.hidden = value === '';
    });
    // The icons are SVG, which has no `hidden` property: toggle the attribute.
    root.querySelectorAll('[data-type-icon]').forEach((icon) => {
        icon.toggleAttribute('hidden', icon.dataset.typeIcon !== alert?.type);
    });
    root.toggleAttribute('data-drill', Boolean(alert?.is_drill));
}

// The banner: hidden without an alert, colored by severity while active (yellow advisory, orange warning, red emergency),
// green once resolved (all clear or false alarm).
export function renderBanner(banner, alert) {
    fillAlert(banner, alert);
    banner.hidden = alert === null;
    banner.dataset.severity = alert?.severity ?? '';
    // The compact banner on the emergency map has no route link (the map's own panel does that).
    const route = banner.querySelector('[data-route-to-safety]');
    if (route) route.hidden = !showsRouteToSafety(alert);
    // The location belongs to the emergency, not to the all clear.
    if (alert?.status !== 'active') banner.querySelector('[data-field="location"]').textContent = '';
    banner.dataset.version = alert?.version ?? '';
    banner.dataset.state = alert === null ? 'none' : alert.status === 'active' ? 'active' : 'clear';
}
