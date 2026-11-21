import { bearing } from '@turf/bearing';
import { distance } from '@turf/distance';

const RAD = Math.PI / 180;

// Great-circle distance in meters between two [lng, lat] points (same formula turf length/along use).
export const meters = (a, b) => distance(a, b, { units: 'meters' });

// Compass bearing from a to b in degrees, 0..360 clockwise from north.
export const bearingDeg = (a, b) => (bearing(a, b) + 360) % 360;

// Signed turn from one bearing to another in (-180, 180]: positive = right, negative = left.
export function turnDelta(fromBearing, toBearing) {
    const d = ((((toBearing - fromBearing) % 360) + 540) % 360) - 180;
    return d === -180 ? 180 : d;
}

// Smallest angle between two bearings, 0..180.
export const angleBetween = (a, b) => Math.abs(turnDelta(a, b));

export const sameCoord = (a, b) => a[0] === b[0] && a[1] === b[1];

// Projects p onto segment a-b in a local flat projection (accurate at campus scale).
// Returns the closest point, its position t (0 = a, 1 = b) and its distance from p in meters.
export function projectOnSegment(p, a, b) {
    const k = Math.cos(a[1] * RAD);
    const [bx, by] = [(b[0] - a[0]) * k, b[1] - a[1]];
    const [px, py] = [(p[0] - a[0]) * k, p[1] - a[1]];
    const len2 = bx * bx + by * by;
    const t = len2 === 0 ? 0 : Math.max(0, Math.min(1, (px * bx + py * by) / len2));
    const point = [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
    return { point, t, distance: meters(p, point) };
}

// Shortest distance in meters from a point to the edge of a polygon ring.
export function distanceToRing(p, ring) {
    let best = Infinity;
    for (let i = 1; i < ring.length; i++) {
        best = Math.min(best, projectOnSegment(p, ring[i - 1], ring[i]).distance);
    }
    return best;
}
