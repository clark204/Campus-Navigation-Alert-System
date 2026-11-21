// Movement on /navigate, kept light: the route's flowing dashes are an SVG over the map that CSS animates (so the
// map is not repainted for them); the route draws in from the start in a few steps; the dot glides between fixes;
// the dot's pulse and the pin's drop are CSS (navigate-page.blade.php, app.css). Everything switches off with
// prefers-reduced-motion; the flow and pulse also pause while the tab is hidden or a photo is open. Pure geometry and
// timing, so it can be tested in Node.
import { meters } from './geo.js';

export const DRAW_IN_MS = 500;
export const GLIDE_MS = 350;
// The route draws in over DRAW_IN_MS, in steps this far apart (each step repaints the map).
export const DRAW_STEP_MS = 100;
// The route's flowing dashes (an SVG path, dash 6 px, gap 10 px): moved every FLOW_TICK_MS, FLOW_PIXELS_PER_SECOND.
export const FLOW_TICK_MS = 250;
export const FLOW_PIXELS_PER_SECOND = 18;
export const FLOW_PERIOD_PIXELS = 16;

// Which effects run now. The pulse is CSS, so the script only reads its flag to pause it with the flow.
export function motionFor({ reducedMotion, hidden, viewerOpen }) {
    const running = !reducedMotion && !hidden && !viewerOpen;
    return { flow: running, pulse: running, pin: !reducedMotion, glide: !reducedMotion, drawIn: !reducedMotion };
}

const clamp = (t) => Math.min(1, Math.max(0, t));
export const easeOut = (t) => 1 - (1 - clamp(t)) ** 3;

// How much of the route is drawn `elapsedMs` after it appeared (0 → 1 over DRAW_IN_MS).
export const drawInFraction = (elapsedMs) => easeOut(elapsedMs / DRAW_IN_MS);

// The dot between its old and new spot, `elapsedMs` into the glide ([lng, lat] → [lng, lat]).
export function glidePoint(from, to, elapsedMs) {
    const t = easeOut(elapsedMs / GLIDE_MS);
    return [from[0] + (to[0] - from[0]) * t, from[1] + (to[1] - from[1]) * t];
}


// Lines are lists of [lng, lat] coordinates. Their length in meters:
function lineLength(coords) {
    let total = 0;
    for (let i = 1; i < coords.length; i++) total += meters(coords[i - 1], coords[i]);
    return total;
}

// The first `distance` meters of a line.
function cutLine(coords, distance) {
    const out = [coords[0]];
    let left = distance;
    for (let i = 1; i < coords.length; i++) {
        const step = meters(coords[i - 1], coords[i]);
        if (step >= left) {
            const t = step ? left / step : 0;
            const [a, b] = [coords[i - 1], coords[i]];
            out.push([a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t]);
            return out;
        }
        out.push(coords[i]);
        left -= step;
    }
    return out;
}

// The part of the route drawn so far: `parts` (lists of coordinates) cut to `fraction` of their total length.
export function partialLines(parts, fraction) {
    let budget = parts.reduce((sum, coords) => sum + lineLength(coords), 0) * clamp(fraction);
    const out = [];
    for (const coords of parts) {
        if (budget <= 0) break;
        const length = lineLength(coords);
        if (length <= budget) {
            out.push(coords);
            budget -= length;
        } else {
            out.push(cutLine(coords, budget));
            budget = 0;
        }
    }
    return out.filter((coords) => coords.length >= 2);
}

// The dash offset `elapsedMs` into the flow (negative: the dashes move toward the end of the path).
export const flowDashOffset = (elapsedMs) => {
    const shift = ((elapsedMs / 1000) * FLOW_PIXELS_PER_SECOND) % FLOW_PERIOD_PIXELS;
    return shift === 0 ? 0 : -shift;
};

// SVG path data for a line of screen points ([x, y] pairs, from map.project).
export const pathData = (points) => points.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(1)} ${y.toFixed(1)}`).join(' ');
