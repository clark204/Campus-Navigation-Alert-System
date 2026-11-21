// Saved and recent places of the signed-in user, on /navigate (docs/portal.md). "Save" on the directions card
// toggles a room in Saved (PlaceController save/unsave); Start puts it at the top of Recent. Guests get no store.
// URL templates hold "__room__" for the room code, e.g. "/places/__room__/save".

const ROOM = '__room__';

export function createPlaceStore({ saved = [], saveUrl, recentUrl, csrfToken, fetchImpl = (...args) => fetch(...args) }) {
    const savedCodes = new Set(saved);
    const urlFor = (template, code) => template.replace(ROOM, encodeURIComponent(code));
    const send = (method, url) =>
        fetchImpl(url, {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
        });

    return {
        isSaved: (code) => savedCodes.has(code),

        // Saves or unsaves at once (the button changes on tap) and undoes it when the server refuses or can't be
        // reached. Resolves to the new state; rejects after undoing.
        async toggle(code) {
            const next = !savedCodes.has(code);
            const apply = (on) => (on ? savedCodes.add(code) : savedCodes.delete(code));
            apply(next);
            try {
                const response = await send(next ? 'POST' : 'DELETE', urlFor(saveUrl, code));
                if (!response.ok) throw new Error(`Saved places: HTTP ${response.status}`);
                return next;
            } catch (error) {
                apply(!next);
                throw error;
            }
        },

        // Best effort: a missed Recent entry must never get in the way of navigating.
        recordRecent(code) {
            return send('POST', urlFor(recentUrl, code)).then(
                (response) => response.ok,
                () => false,
            );
        },
    };
}
