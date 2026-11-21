// localStorage can throw (private mode, blocked site data); choices are then only kept for this page.
const PREFIX = 'cnas.alerts.';

export function load(key, fallback = null) {
    try {
        const raw = localStorage.getItem(PREFIX + key);
        return raw === null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}

export function save(key, value) {
    try {
        localStorage.setItem(PREFIX + key, JSON.stringify(value));
    } catch {
        // Not remembered.
    }
}
