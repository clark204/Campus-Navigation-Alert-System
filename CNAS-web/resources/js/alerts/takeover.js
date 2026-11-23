import { fillAlert } from './fill.js';

// The full-screen alarm (partials/alert-takeover). While open, the rest of the page is inert and the alarm sounds
// until the first tap or key press on it. "Dismiss" or Escape collapses it to the banner.
export class Takeover {
    constructor(root, alarm) {
        this.root = root;
        this.alarm = alarm;
        this.alert = null;
        this.inerted = [];
        this.hint = root.querySelector('[data-silence-hint]');

        root.addEventListener('pointerdown', () => this.silence(), { capture: true });
        root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') this.hide();
            else this.silence();
        });
        root.querySelector('[data-dismiss-takeover]').addEventListener('click', () => this.hide());
    }

    get open() {
        return !this.root.hidden;
    }

    show(alert) {
        this.update(alert);
        if (!this.open) {
            this.returnFocus = document.activeElement;
            this.root.hidden = false;
            this.makeRestInert();
            document.documentElement.style.overflow = 'hidden';
        }
        this.alarm.start(alert.is_drill);
        this.hint.hidden = false;
        // "I'm safe" first (signed in), else the route to safety.
        (this.root.querySelector('[data-mark-safe]:not(:disabled)') ?? this.root.querySelector('[data-route-to-safety]')).focus();
    }

    update(alert) {
        // A new alert (or a new version of it) starts with "More details" closed again.
        if (this.alert?.version !== alert?.version) this.root.querySelector('[data-takeover-details]')?.removeAttribute('open');
        this.alert = alert;
        fillAlert(this.root, alert);
    }

    silence() {
        if (this.hint.hidden) return;
        this.alarm.stop();
        this.hint.hidden = true;
    }

    hide() {
        if (!this.open) return;
        this.alarm.stop();
        this.hint.hidden = true;
        this.root.hidden = true;
        this.inerted.forEach((element) => (element.inert = false));
        this.inerted = [];
        document.documentElement.style.overflow = '';
        this.returnFocus?.focus?.();
    }

    // Everything outside the takeover, at every level up to <body>.
    makeRestInert() {
        for (let node = this.root; node && node !== document.body; node = node.parentElement) {
            for (const sibling of node.parentElement?.children ?? []) {
                if (sibling !== node && !sibling.inert && sibling.tagName !== 'SCRIPT') {
                    sibling.inert = true;
                    this.inerted.push(sibling);
                }
            }
        }
    }
}
