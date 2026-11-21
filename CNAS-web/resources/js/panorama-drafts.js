// The 360° map editor's state: the photos as saved, the edits not saved yet, and which photo is selected. Pure, so it
// can be tested in Node. Every change returns a new state; the saved photos are never changed here.
//
// The rules kept here: north is blank or 0–360 (360 is north again); a photo can only be shown with north set
// (clearing north hides it); a changed photo is one whose position, floor, name, north or visibility differs from saved.

const COORD_PLACES = 8;
const round = (value, places) => Math.round(value * 10 ** places) / 10 ** places;

// A photo's editable details, as numbers and booleans, plus its image (shown in the viewer; never edited or sent).
export function photoDetails(photo) {
    return {
        code: photo.code,
        image: photo.image ?? null,
        name: String(photo.name ?? ''),
        floor: Number(photo.floor) || 1,
        latitude: round(Number(photo.latitude), COORD_PLACES),
        longitude: round(Number(photo.longitude), COORD_PLACES),
        north_offset: photo.north_offset === null || photo.north_offset === undefined || photo.north_offset === '' ? null : Number(photo.north_offset),
        is_visible: Boolean(photo.is_visible),
    };
}

export function createDrafts(photos) {
    const saved = new Map(photos.map((photo) => [photo.code, photoDetails(photo)]));
    return { saved, drafts: new Map(saved), selected: null };
}

const withDraft = (state, code, change) => {
    if (!state.drafts.has(code)) return state;
    const drafts = new Map(state.drafts);
    drafts.set(code, { ...state.drafts.get(code), ...change(state.drafts.get(code)) });
    return { ...state, drafts };
};

export const select = (state, code) => (state.saved.has(code) ? { ...state, selected: code } : state);

// A pin dragged (or its position typed in).
export function move(state, code, { latitude, longitude }) {
    return withDraft(state, code, () => ({
        latitude: round(Number(latitude), COORD_PLACES),
        longitude: round(Number(longitude), COORD_PLACES),
    }));
}

export const setFloor = (state, code, floor) => withDraft(state, code, () => ({ floor: Number(floor) || 1 }));

export const setName = (state, code, name) => withDraft(state, code, () => ({ name: String(name).trim() }));

// North in degrees (blank = not set). Clearing north hides the photo, as the server requires.
export function setNorth(state, code, north) {
    const value = north === null || north === undefined || String(north).trim() === '' ? null : round(normalizeDegrees(Number(north)), 2);
    return withDraft(state, code, () => ({ north_offset: value, ...(value === null ? { is_visible: false } : {}) }));
}

// "Show to users". Refused (the draft is kept as it was) when north is not set.
export function setVisible(state, code, visible) {
    const draft = state.drafts.get(code);
    if (!draft || (visible && draft.north_offset === null)) return state;
    return withDraft(state, code, () => ({ is_visible: Boolean(visible) }));
}

const normalizeDegrees = (degrees) => ((degrees % 360) + 360) % 360;

const FIELDS = ['name', 'floor', 'latitude', 'longitude', 'north_offset', 'is_visible'];
export function isChanged(state, code) {
    const saved = state.saved.get(code);
    const draft = state.drafts.get(code);
    return Boolean(saved && draft) && FIELDS.some((field) => saved[field] !== draft[field]);
}

// The codes of the changed photos, in code order (the list the "Save all" button saves).
export const changedCodes = (state) => [...state.drafts.keys()].filter((code) => isChanged(state, code)).sort();
export const hasUnsaved = (state) => changedCodes(state).length > 0;

// Throws away every edit, or only the ones of one photo.
export function discard(state, code = null) {
    if (code === null) return { ...state, drafts: new Map(state.saved) };
    if (!state.saved.has(code)) return state;
    const drafts = new Map(state.drafts);
    drafts.set(code, state.saved.get(code));
    return { ...state, drafts };
}

// What the server is sent: the given photos (default: the changed ones), with every field.
export function payloadFor(state, codes = changedCodes(state)) {
    return codes.map((code) => {
        const draft = state.drafts.get(code);
        return {
            code,
            name: draft.name,
            floor: draft.floor,
            latitude: draft.latitude,
            longitude: draft.longitude,
            north_offset: draft.north_offset,
            is_visible: draft.is_visible,
        };
    });
}

// After the server saved these photos: they are the saved state now.
export function markSaved(state, codes) {
    const saved = new Map(state.saved);
    for (const code of codes) if (state.drafts.has(code)) saved.set(code, state.drafts.get(code));
    return { ...state, saved };
}
