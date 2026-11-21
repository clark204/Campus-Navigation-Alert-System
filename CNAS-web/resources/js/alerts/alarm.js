// The takeover's alarm: a looping sound (public/sounds, generated tones) and vibration until the person taps.
// Browsers only allow sound after the person has tapped the page, so unlock() runs inside a tap.
export const VIBRATE_PATTERN = [500, 200, 500, 200, 500, 200, 500];
export const DRILL_VIBRATE_PATTERN = [300, 300, 300];
const VIBRATE_PAUSE_MS = 800;

const total = (pattern) => pattern.reduce((sum, ms) => sum + ms, 0);

function makeAudio(src, volume, loop = true) {
    const audio = new Audio(src);
    audio.loop = loop;
    audio.preload = 'auto';
    audio.volume = volume;
    return audio;
}

export class Alarm {
    constructor({ alarmUrl, drillUrl, chimeUrl }) {
        this.sounds = { alarm: makeAudio(alarmUrl, 1), drill: makeAudio(drillUrl, 0.6) };
        // The warning's chime plays once, not in a loop.
        this.chimeSound = makeAudio(chimeUrl, 0.8, false);
        this.vibrateTimer = null;
        this.playing = null;
    }

    // Plays both sounds silently for a moment, inside a tap, so they may play later without one (iOS needs this per
    // audio element).
    unlock() {
        for (const audio of [...Object.values(this.sounds), this.chimeSound]) {
            if (audio === this.playing) continue;
            audio.muted = true;
            audio
                .play()
                .then(() => {
                    if (audio !== this.playing) audio.pause();
                    audio.currentTime = 0;
                })
                .catch(() => {})
                .finally(() => (audio.muted = false));
        }
    }

    // A short chime when a warning pops up. It may be refused before the first tap; then it is simply silent.
    chime() {
        this.chimeSound.currentTime = 0;
        this.chimeSound.play().catch(() => {});
    }

    start(isDrill) {
        this.stop();
        const audio = isDrill ? this.sounds.drill : this.sounds.alarm;
        this.playing = audio;
        audio.currentTime = 0;
        // Rejected when the page hasn't been tapped yet; the takeover and vibration still show.
        audio.play().catch(() => (this.playing = null));

        // Browsers refuse vibration before the first tap on the page (and warn on every try), so only then.
        if (typeof navigator.vibrate === 'function' && navigator.userActivation?.hasBeenActive !== false) {
            const pattern = isDrill ? DRILL_VIBRATE_PATTERN : VIBRATE_PATTERN;
            const buzz = () => navigator.vibrate(pattern);
            buzz();
            this.vibrateTimer = setInterval(buzz, total(pattern) + VIBRATE_PAUSE_MS);
        }
    }

    stop() {
        this.playing?.pause();
        this.playing = null;
        clearInterval(this.vibrateTimer);
        this.vibrateTimer = null;
        if (typeof navigator.vibrate === 'function') navigator.vibrate(0);
    }
}
