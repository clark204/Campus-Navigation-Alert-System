import { showsRouteToSafety } from './banner-state.js';
// Fills an alert into server-rendered markup (banner, takeover): [data-field="title"] gets alert.title, and so on.
// Empty instructions are hidden; [data-type-icon] shows the icon for the alert's type.
export function fillAlert(root, alert) {
    root.querySelectorAll('[data-field]').forEach((field) => {
        const value = alert?.[field.dataset.field] ?? '';
        field.textContent = value;
        if (field.dataset.field === 'instructions') field.hidden = value === '';
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
