// Keeps the screen on while navigating. Browsers drop the lock when the tab is hidden,
// so it is taken again when the page becomes visible. Silently does nothing if unsupported.
export class ScreenWakeLock {
    sentinel = null;
    wanted = false;

    constructor() {
        document.addEventListener('visibilitychange', () => {
            if (this.wanted && document.visibilityState === 'visible') this.acquire();
        });
    }

    async acquire() {
        this.wanted = true;
        if (!('wakeLock' in navigator) || this.sentinel) return;
        try {
            this.sentinel = await navigator.wakeLock.request('screen');
            this.sentinel.addEventListener('release', () => (this.sentinel = null));
        } catch {
            // Denied (e.g. battery saver); navigation works without it.
        }
    }

    async release() {
        this.wanted = false;
        await this.sentinel?.release().catch(() => {});
        this.sentinel = null;
    }
}
