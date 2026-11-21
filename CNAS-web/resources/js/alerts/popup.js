import { fillAlert } from './fill.js';
import { showsRouteToSafety } from './banner-state.js';

// The one-time popup of a warning (partials/alert-popup): a small card with the message, the instructions and a chime.
// It doesn't block the page. "Close" or Escape hides it; the alert stays on the banner.
export class Popup {
    constructor(root, alarm) {
        this.root = root;
        this.alarm = alarm;
        this.alert = null;
        root.querySelector('[data-dismiss-popup]').addEventListener('click', () => this.hide());
        root.addEventListener('keydown', (event) => event.key === 'Escape' && this.hide());
    }

    get open() {
        return !this.root.hidden;
    }

    show(alert) {
        this.update(alert);
        if (!this.open) {
            this.root.hidden = false;
            this.root.querySelector('[data-popup-title]').focus();
            this.alarm.chime();
        }
    }

    update(alert) {
        this.alert = alert;
        fillAlert(this.root, alert);
        this.root.querySelector('[data-route-to-safety]').hidden = !showsRouteToSafety(alert);
    }

    hide() {
        this.root.hidden = true;
        this.alert = null;
    }
}
