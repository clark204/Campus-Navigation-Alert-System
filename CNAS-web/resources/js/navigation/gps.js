import { bearingDeg, meters } from './geo.js';
import { movePoint } from './position.js';

// Fixes less precise than this (meters) are ignored.
export const GPS_MAX_ACCURACY = 25;
// coords.speed at or above this (m/s) means walking.
export const MOVING_SPEED = 0.5;
// Without a speed from the phone and without a step sensor: walking means covering this much within MOVED_WINDOW_MS.
const MOVED_METERS = 5;
const MOVED_WINDOW_MS = 5000;
// A heading from movement needs positions at least this far apart, so GPS jitter gives none.
export const HEADING_MIN_METERS = 5;
// A heading is kept this long after it was last measured (standing still keeps it a while), then it is unknown.
export const HEADING_KEEP_MS = 30000;
// How fast the uncertainty of the smoothed position grows while walking (m² per second), and the starting
// uncertainty of an exactly known place (a door, a QR checkpoint).
const WALK_VARIANCE_PER_S = 4;
const EXACT_VARIANCE = 1;
// Gaps longer than this between fixes count as this long.
const MAX_GAP_S = 10;
const TRAIL_MS = 20000;

const UNKNOWN = Object.freeze({ value: null, source: 'unknown' });
const finite = (value) => (Number.isFinite(value) ? value : null);

/*
 * Turns raw Geolocation fixes into a smoothed position and a direction of travel.
 *   push(position, { recentSteps }) -> { accepted, reason, accuracy, speed, moving, coord, heading }
 *     accepted = false with reason 'too inaccurate' (worse than GPS_MAX_ACCURACY) or 'standing still'.
 *     recentSteps: true/false when the step sensor works (steps in the last 2 s), null when it doesn't.
 *   coord:   the smoothed [lng, lat]
 *   heading: { value, source: 'gps' } or { value: null, source: 'unknown' }
 *   shift(from, to): the dot moved by steps; the smoothed position moves with it.
 *   reset(coord):    an exactly known place (start, "I'm here", stairs).
 *
 * Smoothing is a small Kalman filter: each fix pulls the position by a share that depends on how precise the fix
 * is compared with how unsure the position has become since the last one. Standing still nothing moves.
 */
export function createFixFilter({ start, variance = EXACT_VARIANCE } = {}) {
    let coord = start ?? null;
    let uncertainty = variance;
    let lastTime = null;
    let heading = null;
    let headingAt = -Infinity;
    // Recent fixes within the accuracy limit (to tell walking without a speed) and smoothed positions (for heading).
    let recent = [];
    let trail = coord ? [{ coord, t: null }] : [];

    const currentHeading = () => (heading !== null && lastTime - headingAt <= HEADING_KEEP_MS ? { value: heading, source: 'gps' } : UNKNOWN);

    function isMoving(raw, accuracy, speed, recentSteps, t) {
        if (speed !== null) return speed >= MOVING_SPEED;
        if (recentSteps === true || recentSteps === false) return recentSteps;
        const window = recent.filter((fix) => t - fix.t <= MOVED_WINDOW_MS);
        return window.length > 0 && meters(window[0].coord, raw) >= Math.max(MOVED_METERS, accuracy / 2);
    }

    function push(position, { recentSteps = null } = {}) {
        const { longitude, latitude, accuracy } = position.coords;
        const raw = [longitude, latitude];
        const t = position.timestamp ?? Date.now();
        const dt = lastTime === null ? 1 : Math.min(MAX_GAP_S, Math.max(0, (t - lastTime) / 1000));
        lastTime = t;
        let speed = finite(position.coords.speed);
        if (speed !== null && speed < 0) speed = null; // iOS reports -1 when it has no speed
        const result = { accepted: false, reason: '', accuracy, speed, moving: false, coord, heading: UNKNOWN };

        if (!(accuracy <= GPS_MAX_ACCURACY)) {
            result.reason = 'too inaccurate';
            result.heading = currentHeading();
            return result;
        }
        result.moving = isMoving(raw, accuracy, speed, recentSteps, t);
        recent = [...recent.filter((fix) => t - fix.t <= MOVED_WINDOW_MS), { coord: raw, t }];
        if (!result.moving) {
            result.reason = 'standing still';
            result.heading = currentHeading();
            return result;
        }

        if (coord === null) {
            coord = raw;
            uncertainty = accuracy * accuracy;
        } else {
            // Predict: carry on at the reported speed along the current heading, and grow less sure.
            const known = currentHeading();
            if (speed !== null && known.value !== null) coord = movePoint(coord, known.value, speed * dt);
            uncertainty += WALK_VARIANCE_PER_S * Math.max(dt, 0.2);
            // Correct: move toward the fix by the share the filter trusts it.
            const gain = uncertainty / (uncertainty + accuracy * accuracy);
            coord = [coord[0] + (raw[0] - coord[0]) * gain, coord[1] + (raw[1] - coord[1]) * gain];
            uncertainty *= 1 - gain;
        }

        // Direction of travel: the phone's own heading, else the bearing from a smoothed position 5 m or more back.
        const reported = finite(position.coords.heading);
        trail = [...trail.filter((point) => point.t === null || t - point.t <= TRAIL_MS), { coord, t }];
        if (reported !== null && reported >= 0) {
            heading = reported % 360;
            headingAt = t;
        } else {
            const from = trail.findLast((point) => meters(point.coord, coord) >= HEADING_MIN_METERS);
            if (from) {
                heading = bearingDeg(from.coord, coord);
                headingAt = t;
            }
        }

        result.accepted = true;
        result.coord = coord;
        result.heading = currentHeading();
        return result;
    }

    return {
        push,
        get coord() {
            return coord;
        },
        get heading() {
            return lastTime === null ? UNKNOWN : currentHeading();
        },
        get uncertainty() {
            return Math.sqrt(uncertainty);
        },
        shift(from, to) {
            if (!coord) return;
            coord = [coord[0] + to[0] - from[0], coord[1] + to[1] - from[1]];
        },
        reset(place) {
            coord = place;
            uncertainty = EXACT_VARIANCE;
            recent = [];
            trail = [{ coord: place, t: lastTime }];
        },
    };
}
