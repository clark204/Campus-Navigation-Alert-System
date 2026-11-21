import { meters } from './geo.js';
import { MOVING_SPEED } from './gps.js';

// Debug-only fake GPS (/navigate?debug=1, "Simulate"): walking speed in m/s, one fix per SIM_INTERVAL_MS.
export const SIM_SPEED = 1.2;
export const SIM_ACCURACY = 5;
export const SIM_INTERVAL_MS = 1000;
// After a tap (or the end of a walk) the fake GPS stays at that spot and reports the slowest speed that still counts
// as moving, so the dot catches up through the filter (walking speed would make the filter's prediction carry the dot
// past the spot). At most this many fixes; then it reports standing still (speed 0), as a phone at rest does.
const MAX_CATCH_UP_FIXES = 20;
// The dot is close enough to the spot to stop catching up.
export const CAUGHT_UP_METERS = 1.5;

// A Geolocation-like position: a speed, no heading (so heading comes from movement, as on a phone without one).
export function simulatedFix([longitude, latitude], { speed, t, accuracy = SIM_ACCURACY }) {
    return { coords: { longitude, latitude, accuracy, speed, heading: null, altitude: null, altitudeAccuracy: null }, timestamp: t };
}

// Points every `spacing` meters along `coords`, not including the first point, always ending at the last.
export function pointsAlong(coords, spacing) {
    const points = [];
    let travelled = 0;
    let next = spacing;
    for (let i = 1; i < coords.length; i++) {
        const [a, b] = [coords[i - 1], coords[i]];
        const length = meters(a, b);
        if (length === 0) continue;
        while (next <= travelled + length + 1e-9) {
            const f = (next - travelled) / length;
            points.push([a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f]);
            next += spacing;
        }
        travelled += length;
    }
    const end = coords.at(-1);
    if (!points.length || meters(points.at(-1), end) > 0.01) points.push(end);
    return points;
}

/*
 * The fake GPS. tick() (called once a second by the page) sends one fix through `emit`, the same path real fixes
 * take. Modes:
 *   'walking'     moving along a path at SIM_SPEED
 *   'catching up' standing at a spot but still reporting walking speed, until caughtUp(coord) says the dot is there
 *   'standing'    reporting speed 0
 * place(coord): the fake position jumps there (a tap). walk(coords): along a line (the rest of the route).
 * walkTo(coord): in a straight line from where it is. stop(): stays where it is.
 */
export function createSimulator({ emit, now = () => Date.now(), caughtUp = () => true, speed = SIM_SPEED }) {
    let coord = null;
    let path = [];
    let mode = 'standing';
    let catchUpLeft = 0;

    const startCatchingUp = () => {
        mode = 'catching up';
        catchUpLeft = MAX_CATCH_UP_FIXES;
    };

    return {
        get coord() {
            return coord;
        },
        get mode() {
            return mode;
        },
        get busy() {
            return mode !== 'standing';
        },
        // Seconds of walking left (one fix a second).
        get secondsLeft() {
            return path.length;
        },
        place(target) {
            coord = target;
            path = [];
            startCatchingUp();
        },
        walk(coords) {
            const line = coord ? [coord, ...coords] : coords;
            coord ??= coords[0];
            path = pointsAlong(line, speed * (SIM_INTERVAL_MS / 1000));
            mode = 'walking';
        },
        walkTo(target) {
            this.walk([target]);
        },
        stop() {
            path = [];
            mode = 'standing';
        },
        tick() {
            if (!coord) return;
            if (mode === 'walking') {
                coord = path.shift();
                if (!path.length) startCatchingUp();
                emit(simulatedFix(coord, { speed, t: now() }));
                return;
            }
            if (mode === 'catching up' && (caughtUp(coord) || catchUpLeft <= 0)) mode = 'standing';
            if (mode === 'catching up') {
                catchUpLeft--;
                emit(simulatedFix(coord, { speed: MOVING_SPEED, t: now() }));
                return;
            }
            emit(simulatedFix(coord, { speed: 0, t: now() }));
        },
    };
}
