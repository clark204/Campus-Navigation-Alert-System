// "I need help" on the web (partials/alert-safe-button, resources/js/alerts/index.js): once the request is sent, the
// person's browser keeps telling the administrators where they are and learns where the request is. Every PING_MS it
// posts the latest position (when they share it) to alerts.help.ping (HelpPingController), and back comes the state:
// "The administrators can see your request", "<admin> is on the way" or "Marked as helped". A 409 (helped, answered
// "I'm safe", or the alert is over) stops it. Only while the page is open: there is no background location on the web.
// Tested in tests/js/help-tracker.test.js with a fake geolocation, fetch and clock.
export const PING_MS = 15000;
export const FIRST_FIX_TIMEOUT_MS = 8000;

const toFix = (position) => ({
    latitude: position.coords.latitude,
    longitude: position.coords.longitude,
    accuracy: Number.isFinite(position.coords.accuracy) ? Math.round(position.coords.accuracy) : null,
});

// One position for the request itself, or null when location is refused, off or too slow (the request goes anyway).
export function firstFix(geolocation, timeoutMs = FIRST_FIX_TIMEOUT_MS) {
    if (!geolocation) return Promise.resolve({ fix: null, denied: false });
    return new Promise((resolve) => {
        geolocation.getCurrentPosition(
            (position) => resolve({ fix: toFix(position), denied: false }),
            (error) => resolve({ fix: null, denied: error?.code === 1 }),
            { enableHighAccuracy: true, timeout: timeoutMs, maximumAge: 10000 },
        );
    });
}

export class HelpTracker {
    // onState({ help, sharing, denied, stopped }): help is the server's { status, label, responder_name } or null.
    constructor({ pingUrl, csrfToken, geolocation, fetchImpl = (...args) => fetch(...args), timers = globalThis, onState = () => {} }) {
        this.pingUrl = pingUrl;
        this.csrfToken = csrfToken;
        this.geolocation = geolocation;
        this.fetch = fetchImpl;
        this.timers = timers;
        this.onState = onState;
        this.fix = null;
        this.sharing = false;
        this.denied = false;
        this.watchId = null;
        this.timer = null;
        this.running = false;
    }

    // share: whether the person agreed to share their location. fix: the position sent with the request, if any.
    start({ share, fix = null } = {}) {
        this.stop();
        this.running = true;
        this.fix = fix;
        this.sharing = Boolean(share && this.geolocation);
        if (this.sharing) {
            this.watchId = this.geolocation.watchPosition(
                (position) => {
                    this.fix = toFix(position);
                    this.denied = false;
                },
                (error) => {
                    if (error?.code === 1) {
                        this.denied = true;
                        this.sharing = false;
                        this.report(this.lastHelp ?? null);
                    }
                },
                { enableHighAccuracy: true, maximumAge: 5000 },
            );
        }
        this.timer = this.timers.setInterval(() => this.ping(), PING_MS);
    }

    stop() {
        this.running = false;
        if (this.watchId !== null) this.geolocation?.clearWatch(this.watchId);
        if (this.timer !== null) this.timers.clearInterval(this.timer);
        this.watchId = null;
        this.timer = null;
    }

    report(help, stopped = false) {
        this.lastHelp = help;
        this.onState({ help, sharing: this.sharing && this.fix !== null, denied: this.denied, stopped });
    }

    async ping() {
        if (!this.running) return;
        const body = this.sharing && this.fix ? this.fix : {};
        let response;
        try {
            response = await this.fetch(this.pingUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });
        } catch {
            return; // Offline for a moment: try again at the next ping.
        }
        const data = await response.json().catch(() => ({}));
        if (response.status === 409 || data.help?.status === 'helped') {
            this.stop();
            this.report(data.help ?? null, true);
        } else if (response.ok) {
            this.report(data.help ?? null);
        }
    }
}

// The line under "Help requested": where the request is, and whether the location is shared.
export function helpLine({ help, sharing, denied }) {
    const state = help?.label ?? 'Sent. The administrators can see your request.';
    if (help?.status === 'helped') return state;
    if (denied) return `${state} Location is off. Add where you are in the note.`;
    return sharing ? `${state} Sharing your location while this page is open.` : state;
}
