// Which alerts this device has already shown (newest first), so each one happens once per device: the full-screen
// takeover of an emergency, and the popup of a warning. Only an emergency takes over the screen.
export const SEEN_LIMIT = 20;

export const isEmergency = (alert) => alert?.severity === 'emergency';

export const shouldTakeOver = (alert, seenIds) => alert?.status === 'active' && isEmergency(alert) && !seenIds.includes(alert.id);

export const shouldPopUp = (alert, seenIds) => alert?.status === 'active' && alert?.severity === 'warning' && !seenIds.includes(alert.id);

export const rememberSeen = (seenIds, id) => [id, ...seenIds.filter((seen) => seen !== id)].slice(0, SEEN_LIMIT);
