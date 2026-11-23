import { VOICE_DIRECTIONS_DEFAULT } from './constants.js';

// localStorage can throw (private mode, blocked site data); settings then just aren't remembered.
export function loadSetting(key, fallback) {
    try {
        const raw = localStorage.getItem(`cnas.nav.${key}`);
        return raw === null ? fallback : JSON.parse(raw);
    } catch {
        return fallback;
    }
}

// A trip is remembered this long, so scanning a QR checkpoint on the way reopens the page with the same destination.
const TRIP_MAX_AGE_MS = 2 * 60 * 60 * 1000;

export const rememberTrip = (placeId, now = Date.now()) => saveSetting('trip', { to: placeId, at: now });
export const forgetTrip = () => saveSetting('trip', null);

// The destination (place id) of a trip that is still under way, or null.
export function recallTrip(now = Date.now()) {
    const trip = loadSetting('trip', null);
    return trip && now - trip.at < TRIP_MAX_AGE_MS ? trip.to : null;
}

// "Voice directions" (cnas.nav.voice), off by default. The old remembered mute (cnas.nav.muted, voice on unless muted)
// is removed, so browsers that used it also start with voice off.
export function voiceDirectionsOn() {
    removeSetting('muted');
    return loadSetting('voice', VOICE_DIRECTIONS_DEFAULT) === true;
}

export const setVoiceDirections = (on) => saveSetting('voice', on === true);

function removeSetting(key) {
    try {
        localStorage.removeItem(`cnas.nav.${key}`);
    } catch {
        // Nothing stored, or storage is blocked.
    }
}

export function saveSetting(key, value) {
    try {
        localStorage.setItem(`cnas.nav.${key}`, JSON.stringify(value));
    } catch {
        // Not remembered; the current session still uses the value.
    }
}
