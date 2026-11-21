// The "line it up" overlay on a 360° photo: the walkways near the photo's pin, drawn on the ground at the directions they
// have from the pin, turned by the photo's north. Pure direction maths (no DOM), so it can be tested in Node.
//
// A photo's north is the direction in the photo that faces north (degrees right of its centre). So a point on the
// ground at compass bearing B from the pin appears at photo yaw B + north. The ground is EYE_HEIGHT_METERS below the
// camera, so a point `d` meters away sits at pitch −atan(EYE / d).
import { angleBetween, bearingBetween, distanceBetween, normalize } from './panoramas.js';
import { projectOnSegment } from './navigation/geo.js';
import { walkOnFloor } from './navigation/graph.js';

export const EYE_HEIGHT_METERS = 1.6;
// Walkways this close to the pin are drawn.
export const OVERLAY_METERS = 40;
// How far a walkway line is cut into pieces, so bends show.
const PIECES = 6;
// Points this far off the middle of the view are not drawn (they are behind or at the edge).
const VIEW_HALF_DEGREES = 80;

const RADIANS = Math.PI / 180;

// Where a ground point at compass `bearing` and `meters` from the pin is in a photo with this north: { yaw, pitch }
// in degrees (yaw 0–360 right of the photo's centre, pitch negative = below the horizon).
export function groundView(bearing, north, meters) {
    const distance = Math.max(meters, 0.5);
    return {
        yaw: normalize(bearing + north),
        pitch: -Math.atan(EYE_HEIGHT_METERS / distance) / RADIANS,
    };
}

// The north that puts a walkway running in `walkBearing` at photo yaw `imageYaw`: "facing along the walkway", the
// starting guess before north is set.
export const guessNorth = (imageYaw, walkBearing) => normalize(imageYaw - walkBearing);

// The walkway pieces (edges of the graph on `floor`) within `radius` meters of the pin: [[a, b], …] in [lng, lat].
export function walkSegments(graph, pin, floor, radius = OVERLAY_METERS) {
    if (!graph) return [];
    const segments = [];
    for (const edge of graph.edges.values()) {
        if (!walkOnFloor(graph, floor)(edge)) continue;
        const [a, b] = [graph.nodes.get(edge.a).coord, graph.nodes.get(edge.b).coord];
        if (projectOnSegment(pin, a, b).distance <= radius) segments.push([a, b]);
    }
    return segments;
}

// The compass direction of the walkway nearest the pin (along its first-to-last point), or null without one.
export function nearestWalkBearing(graph, pin, floor) {
    if (!graph) return null;
    let best = null;
    for (const edge of graph.edges.values()) {
        if (!walkOnFloor(graph, floor)(edge)) continue;
        const [a, b] = [graph.nodes.get(edge.a).coord, graph.nodes.get(edge.b).coord];
        const distance = projectOnSegment(pin, a, b).distance;
        if (!best || distance < best.distance) best = { distance, bearing: bearingBetween(a, b) };
    }
    return best?.bearing ?? null;
}

// The polylines to draw for `segments` from the pin with this north: each is a list of { yaw, pitch }.
export function overlayLines(pin, segments, north) {
    return segments.map(([a, b]) => {
        const points = [];
        for (let i = 0; i <= PIECES; i++) {
            const t = i / PIECES;
            const point = [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
            points.push(groundView(bearingBetween(pin, point), north, distanceBetween(pin, point)));
        }
        return points;
    });
}

// Whether a point at photo yaw `yaw` is worth drawing while the view is turned to `viewYaw`.
export const inView = (yaw, viewYaw) => angleBetween(yaw, viewYaw) <= VIEW_HALF_DEGREES;
