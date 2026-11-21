import { angleBetween } from './geo.js';
import { loadSetting, saveSetting } from './storage.js';

export const FAR_METERS = 30;
export const NEAR_METERS = 5;
export const LONG_STRAIGHT_METERS = 40;
// Walking within this many degrees of the direction after a turn means the turn is already made.
export const ALREADY_TURNED_DEG = 30;

const roundTo = (value, step) => Math.max(step, Math.round(value / step) * step);

/*
 * Decides what to say as progress changes. next(progress) returns at most one { key, text } that is due
 * and hasn't been said yet; every key is said once at most.
 *   far:      "In 30 meters, turn right near the Library"  (about 30 m before)
 *   near:     "Turn right now"                             (about 5 m before)
 *   straight: "Walk straight for 40 meters"                (after a turn, when the next one is far)
 * `heading` is the direction the person is walking, from GPS (null when unknown). A turn they are already
 * walking out of is not announced at all: voice never says "turn" when the way on is straight ahead.
 */
export function createPromptPlanner(maneuvers) {
    const said = new Set();

    const due = (progress, heading) => {
        for (let i = 1; i < maneuvers.length; i++) {
            const m = maneuvers[i];
            const prev = maneuvers[i - 1];
            const d = m.along - progress;
            // Passed already (or reached and announced): look at the one after it.
            if (d < -NEAR_METERS || (d <= 0 && said.has(`near:${i}`))) continue;

            if (m.type === 'turn' && heading !== null && d <= FAR_METERS && angleBetween(heading, m.bearingAfter) < ALREADY_TURNED_DEG) {
                said.add(`far:${i}`).add(`near:${i}`);
                return null;
            }

            if (d <= NEAR_METERS) {
                return { key: `near:${i}`, text: m.now, also: [`far:${i}`, `straight:${i}`] };
            }
            if (d <= FAR_METERS && d > NEAR_METERS + 5) {
                return { key: `far:${i}`, text: `In ${roundTo(d, 5)} meters, ${m.ahead}`, also: [`straight:${i}`] };
            }
            const leg = m.along - prev.along;
            if (leg >= LONG_STRAIGHT_METERS && progress >= prev.along && d > FAR_METERS + 5) {
                return { key: `straight:${i}`, text: `Walk straight for ${roundTo(d, 10)} meters` };
            }
            // Only the maneuver right ahead can be due.
            return null;
        }
        return null;
    };

    return {
        next(progress, heading = null) {
            const prompt = due(progress, heading);
            if (!prompt || said.has(prompt.key)) return null;
            said.add(prompt.key);
            prompt.also?.forEach((key) => said.add(key));
            return { key: prompt.key, text: prompt.text };
        },
        markSaid: (key) => said.add(key),
        said,
    };
}

// Web Speech API wrapper with an English voice and a remembered mute toggle.
export class Voice {
    constructor() {
        this.supported = 'speechSynthesis' in window;
        this.muted = loadSetting('muted', false);
        this.voice = null;
        if (this.supported) {
            this.pickVoice();
            speechSynthesis.addEventListener?.('voiceschanged', () => this.pickVoice());
        }
    }

    pickVoice() {
        const voices = speechSynthesis.getVoices();
        this.voice =
            voices.find((v) => v.lang === 'en-US' && v.localService) ??
            voices.find((v) => v.lang === 'en-US') ??
            voices.find((v) => v.lang?.startsWith('en')) ??
            null;
    }

    setMuted(muted) {
        this.muted = muted;
        saveSetting('muted', muted);
        if (muted && this.supported) speechSynthesis.cancel();
    }

    // iOS only lets a page speak after it spoke inside a tap, so call this from the Start click.
    unlock(text) {
        if (!this.supported) return;
        const utterance = this.utterance(text || ' ');
        if (this.muted || !text) utterance.volume = 0;
        speechSynthesis.cancel();
        speechSynthesis.speak(utterance);
    }

    say(text) {
        if (!this.supported || this.muted) return;
        // The newest prompt is the relevant one; drop anything still queued.
        speechSynthesis.cancel();
        speechSynthesis.speak(this.utterance(text));
    }

    silence() {
        if (this.supported) speechSynthesis.cancel();
    }

    utterance(text) {
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'en-US';
        if (this.voice) utterance.voice = this.voice;
        return utterance;
    }
}
