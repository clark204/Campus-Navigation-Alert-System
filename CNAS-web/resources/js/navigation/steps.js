import { loadSetting, saveSetting } from './storage.js';

export const DEFAULT_STRIDE = 0.7;
export const DEFAULT_THRESHOLD = 1.2;
const MIN_STEP_INTERVAL_MS = 300;

export const getStride = () => loadSetting('stride', DEFAULT_STRIDE);
export const setStride = (meters) => saveSetting('stride', meters);
export const getThreshold = () => loadSetting('stepThreshold', DEFAULT_THRESHOLD);
export const setThreshold = (value) => saveSetting('stepThreshold', value);

/*
 * Peak detector over acceleration magnitude samples (m/s², gravity included).
 * A fast low-pass smooths jitter, a slow one tracks gravity; a step is a peak of the difference
 * above `threshold`, at least `minIntervalMs` after the previous step.
 * Returns push(magnitude, timeMs) -> true when that sample completed a step.
 */
export function createStepCounter({
    threshold = DEFAULT_THRESHOLD,
    minIntervalMs = MIN_STEP_INTERVAL_MS,
    smoothing = 0.3,
    gravitySmoothing = 0.02,
} = {}) {
    let smooth = null;
    let gravity = null;
    let previous = null;
    let rising = false;
    let lastStepAt = -Infinity;

    const push = (magnitude, time) => {
        smooth = smooth === null ? magnitude : smooth + smoothing * (magnitude - smooth);
        gravity = gravity === null ? magnitude : gravity + gravitySmoothing * (magnitude - gravity);
        const value = smooth - gravity;
        let step = false;
        if (previous !== null) {
            if (value > previous) {
                rising = true;
            } else if (rising && value < previous) {
                rising = false;
                push.lastPeak = previous;
                if (previous > push.threshold && time - lastStepAt >= minIntervalMs) {
                    step = true;
                    lastStepAt = time;
                }
            }
        }
        previous = value;
        return step;
    };
    push.threshold = threshold;
    // Height of the latest peak, counted as a step or not (the debug panel shows it next to the threshold).
    push.lastPeak = null;
    return push;
}

// Listens to DeviceMotionEvent and calls onStep() for each detected step.
export class StepDetector {
    constructor(onStep) {
        this.onStep = onStep;
        this.counter = createStepCounter({ threshold: getThreshold() });
        this.receiving = false;
        // Motion events that carried a reading.
        this.events = 0;
        this.handle = (event) => {
            const a = event.accelerationIncludingGravity;
            if (!a || a.x === null) return;
            this.receiving = true;
            this.events++;
            if (this.counter(Math.hypot(a.x, a.y, a.z), event.timeStamp || performance.now())) this.onStep();
        };
    }

    set threshold(value) {
        this.counter.threshold = value;
    }

    get lastPeak() {
        return this.counter.lastPeak;
    }

    start() {
        window.addEventListener('devicemotion', this.handle);
    }

    stop() {
        window.removeEventListener('devicemotion', this.handle);
    }
}
