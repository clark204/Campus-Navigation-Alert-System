import { along } from '@turf/along';
import { angleBetween, bearingDeg, meters, sameCoord } from './geo.js';
import { createFixFilter, GPS_MAX_ACCURACY } from './gps.js';
import { movePoint, projectOnRoute, snapToWalkway } from './position.js';

export { GPS_MAX_ACCURACY };
// Farther than this from the route, the dot is off it; this many accepted fixes in a row off it call for a new route.
export const OFF_ROUTE_METERS = 10;
export const OFF_ROUTE_FIXES = 3;
// Walking more than this many degrees against the route for WRONG_WAY_FIXES fixes is the wrong way; within
// RIGHT_WAY_DEG of it clears the warning.
export const WRONG_WAY_DEG = 120;
export const RIGHT_WAY_DEG = 60;
export const WRONG_WAY_FIXES = 3;
// Steps move the dot at most this far after the last GPS fix, so a lost signal can't send it running off.
export const STEP_HELPER_MAX_METERS = 15;
const ARRIVE_WITHIN = 2;
// The heading given at the start (out of the door) holds until the dot is this far from the start.
const START_HEADING_METERS = 5;
const UNKNOWN = Object.freeze({ value: null, source: 'unknown' });

// The straight pieces of the route line with where each starts and ends (meters along) and its bearing.
function buildSegments(coordinates) {
    const segments = [];
    let start = 0;
    for (let i = 1; i < coordinates.length; i++) {
        const length = meters(coordinates[i - 1], coordinates[i]);
        if (length === 0) continue;
        segments.push({ start, end: start + length, bearing: bearingDeg(coordinates[i - 1], coordinates[i]) });
        start += length;
    }
    return segments;
}

/*
 * Follows the person along a route. Progress = meters along the route line; it stops at each stairs maneuver until
 * the floor is confirmed, and "I'm here" jumps to the next maneuver.
 *
 * On the ground floor GPS leads (gps.js smooths the fixes and ignores them while standing still). The smoothed spot
 * goes onto the route when the route is within the fix's accuracy, otherwise onto the nearest walkway, and progress
 * is the nearest point of the route. A fix result has rerouteDue once the dot has been more than OFF_ROUTE_METERS
 * off the route for OFF_ROUTE_FIXES fixes (`graceFixes` holds that back just after a reroute), and
 * wrongWayStarted when walking against the route starts the warning.
 *
 * Steps fill in between fixes: one stride along the GPS heading, snapped to the walkway, or along the route when
 * there is no heading; at most STEP_HELPER_MAX_METERS after the last fix. Upper floors have no walkways and poor
 * GPS, so there steps slide the dot along the route.
 *
 * `filter` carries the smoothed GPS over from the previous tracker after a reroute; `startHeading` ({ value, source })
 * is the way the person faces at the start (out of the door, or the GPS heading when rerouting).
 */
export class Tracker {
    constructor(route, maneuvers, { stride, onChange, graph = null, filter = null, graceFixes = 0, startHeading = null } = {}) {
        this.route = route;
        this.maneuvers = maneuvers;
        this.stride = stride;
        this.onChange = onChange;
        this.graph = graph;
        this.graceFixes = graceFixes;
        this.startHeading = startHeading?.value === null || startHeading?.value === undefined ? null : startHeading;
        this.segments = buildSegments(route.line.geometry.coordinates);
        this.filter = filter ?? createFixFilter({ start: route.nodes[0].coord });
        this.progress = 0;
        this.steps = 0;
        this.lastStepAt = 0;
        this.fixes = 0;
        this.stepMetersSinceFix = 0;
        this.wrongWay = false;
        this.wrongWayStreak = 0;
        this.offRoute = 0;
        this.offRouteStreak = 0;
        this.lastGps = null;
        this.waitingAt = null;
        this.confirmedStairs = new Set();
        // Where the dot is when it is off the route line ([lng, lat]); null = on the line at `progress`.
        this.coord = null;
        this.edgeId = null;
        // What last placed the dot: 'start' | 'gps' | 'steps' | 'manual'.
        this.source = 'start';
        // The route's own ground-floor door and stair links: the dot may be on these as well as on the walkways.
        this.links = new Set(
            route.edges.filter((edge, i) => edge.type === 'link' && route.nodes[i].floor === 1 && route.nodes[i + 1].floor === 1).map((edge) => edge.id),
        );
        this.arriveManeuver = maneuvers.findLast((m) => m.type === 'arrive');
        this.setProgress(0);
    }

    get length() {
        return this.route.length;
    }

    get remaining() {
        return Math.max(0, this.arriveManeuver.along - this.progress);
    }

    get arrived() {
        return !this.waitingAt && this.progress >= this.arriveManeuver.along - ARRIVE_WITHIN;
    }

    // The maneuver the person is heading to (or the stairs they are on).
    get next() {
        if (this.waitingAt) return this.waitingAt;
        return this.maneuvers.find((m) => m.type !== 'depart' && m.along > this.progress + 0.5) ?? this.arriveManeuver;
    }

    get distanceToNext() {
        return Math.max(0, this.next.along - this.progress);
    }

    // Which way the person is going: { value, source: 'gps' | 'door' (or the start heading's source) | 'unknown' }.
    get heading() {
        const gps = this.filter.heading;
        if (gps.value !== null) return gps;
        if (this.startHeading && meters(this.position(), this.route.nodes[0].coord) <= START_HEADING_METERS) return this.startHeading;
        return UNKNOWN;
    }

    // Floor of the part of the route at `at` meters (just past a flight of stairs: the floor it leads to).
    floorAt(at = this.progress) {
        const { nodes, along: alongs } = this.route;
        const index = alongs.findIndex((value, i) => i > 0 && value > at);
        return nodes[index === -1 ? nodes.length - 1 : index - 1].floor;
    }

    // True on the ground floor with a map, where GPS and the walkways place the dot.
    get free() {
        return this.graph !== null && !this.waitingAt && this.floorAt() === 1;
    }

    // The stretch of the route between the stairs already climbed and the next ones, in meters along.
    section() {
        let from = 0;
        let to = this.length;
        for (const m of this.maneuvers) {
            if (m.type !== 'stairs') continue;
            if (this.confirmedStairs.has(m.index)) from = Math.max(from, m.along);
            else to = Math.min(to, m.along);
        }
        return { from, to };
    }

    // How far `coord` is from the route, and where along it, within the current section.
    onRoute(coord) {
        return projectOnRoute(this.route, coord, { ...this.section(), near: this.progress });
    }

    // Puts the dot at `coord` (on a walkway) and works out progress from it. Off the route, progress stays put.
    place(coord, edgeId, source) {
        this.source = source;
        const hit = this.onRoute(coord);
        this.offRoute = hit.distance;
        if (hit.distance > OFF_ROUTE_METERS) {
            this.coord = coord;
            this.edgeId = edgeId;
            this.onChange?.(this);
            return;
        }
        this.setProgress(hit.along, { coord, edgeId });
    }

    // `coord` is given when the dot is off the route line; otherwise it is on the line at `meters`.
    setProgress(meters, { coord = null, edgeId = null } = {}) {
        let p = Math.max(0, Math.min(this.length, meters));
        const stairs = this.maneuvers.find((m) => m.type === 'stairs' && !this.confirmedStairs.has(m.index));
        this.waitingAt = null;
        if (stairs && p >= stairs.along) {
            p = stairs.along;
            this.waitingAt = stairs;
            coord = null;
        }
        this.coord = coord;
        this.edgeId = coord ? edgeId : null;
        if (!coord) this.offRoute = 0;
        this.progress = p;
        this.onChange?.(this);
    }

    // Index of the segment the person is on; at a bend that is the one they are about to walk.
    segmentIndexAt(at = this.progress) {
        const index = this.segments.findIndex((s) => at < s.end);
        return index === -1 ? this.segments.length - 1 : index;
    }

    // Bearing of the route segment at `at` (0..360 from north), or null for a route with no length.
    routeBearing(at = this.progress) {
        return this.segments[this.segmentIndexAt(at)]?.bearing ?? null;
    }

    /*
     * Takes a Geolocation position. `recentSteps` says whether the step sensor saw steps in the last moments
     * (null without a step sensor); it helps tell walking from standing when the phone gives no speed.
     * Returns { accuracy, speed, moving, used, reason, offRoute, rerouteDue, wrongWayStarted }.
     */
    gps(position, { recentSteps = null } = {}) {
        const status = {
            accuracy: position.coords.accuracy,
            speed: null,
            moving: false,
            used: false,
            reason: '',
            offRoute: this.offRoute,
            rerouteDue: false,
            wrongWayStarted: false,
        };
        if (this.waitingAt) status.reason = 'on stairs';
        else if (!this.free) status.reason = this.graph ? 'upper floor' : 'no map';
        else {
            const fix = this.filter.push(position, { recentSteps });
            status.speed = fix.speed;
            status.moving = fix.moving;
            if (!fix.accepted) status.reason = fix.reason;
            else {
                status.used = true;
                this.fixes++;
                this.stepMetersSinceFix = 0;
                this.placeFix(fix.coord, status.accuracy);
                status.offRoute = this.offRoute;
                this.offRouteStreak = this.offRoute > OFF_ROUTE_METERS ? this.offRouteStreak + 1 : 0;
                if (this.graceFixes > 0) this.graceFixes--;
                else status.rerouteDue = this.offRouteStreak >= OFF_ROUTE_FIXES;
                status.wrongWayStarted = this.checkDirection(fix.heading);
            }
        }
        this.lastGps = status;
        if (!status.used) this.onChange?.(this);
        return status;
    }

    // The smoothed fix goes onto the route when the route is within the fix's accuracy, else onto a walkway.
    placeFix(coord, accuracy) {
        const hit = this.onRoute(coord);
        const snapped = hit.distance <= Math.min(accuracy, OFF_ROUTE_METERS) ? null : snapToWalkway(this.graph, coord, this.edgeId, this.links);
        if (snapped) {
            this.place(snapped.point, snapped.edgeId, 'gps');
        } else {
            this.source = 'gps';
            this.setProgress(hit.along);
        }
    }

    // Counts fixes walking against the route; true on the one that starts the wrong-way warning.
    checkDirection(heading) {
        const routeBearing = this.routeBearing();
        if (heading.value === null || routeBearing === null || this.offRoute > OFF_ROUTE_METERS) return false;
        const difference = angleBetween(heading.value, routeBearing);
        if (difference <= WRONG_WAY_DEG) {
            this.wrongWayStreak = 0;
            if (difference < RIGHT_WAY_DEG) this.wrongWay = false;
            return false;
        }
        this.wrongWayStreak++;
        if (this.wrongWayStreak < WRONG_WAY_FIXES || this.wrongWay) return false;
        this.wrongWay = true;
        return true;
    }

    // One detected step. Returns { moved, reason }.
    step({ now = Date.now() } = {}) {
        this.steps++;
        this.lastStepAt = now;
        if (this.waitingAt) return { moved: false, reason: 'on stairs' };
        const free = this.free;
        if (free && this.fixes > 0 && this.stepMetersSinceFix + this.stride > STEP_HELPER_MAX_METERS + 1e-9) {
            return { moved: false, reason: 'waiting for GPS' };
        }
        const before = this.position();
        const heading = this.filter.heading;
        const snapped = free && heading.value !== null ? snapToWalkway(this.graph, movePoint(before, heading.value, this.stride), this.edgeId, this.links) : null;
        if (snapped) {
            this.place(snapped.point, snapped.edgeId, 'steps');
        } else {
            this.source = 'steps';
            this.setProgress(this.progress + this.stride);
        }
        if (free) {
            this.stepMetersSinceFix += this.stride;
            this.filter.shift(before, this.position());
        }
        return { moved: true, reason: '' };
    }

    // For manual mode, where nothing moves the dot by itself and the warning would otherwise stay up.
    clearWrongWay() {
        this.wrongWayStreak = 0;
        this.wrongWay = false;
        this.onChange?.(this);
    }

    // "I'm here" (and "Next" in manual mode): the person is at the maneuver shown on the card.
    imHere() {
        if (this.waitingAt) return;
        this.source = 'manual';
        this.setProgress(this.next.along);
        this.filter.reset(this.position());
    }

    confirmFloor() {
        if (!this.waitingAt) return;
        this.confirmedStairs.add(this.waitingAt.index);
        this.setProgress(this.progress);
        this.filter.reset(this.position());
    }

    // Where the dot is; with `at`, the point that many meters along the route line.
    position(at) {
        if (at === undefined && this.coord) return this.coord;
        return along(this.route.line, Math.max(0, Math.min(this.length, at ?? this.progress)), { units: 'meters' }).geometry.coordinates;
    }

    // The route line still to walk (GeoJSON LineString Feature), starting where the dot is on it.
    remainingLine() {
        const coordinates = [this.position(this.progress)];
        this.route.nodes.forEach((node, i) => {
            if (this.route.along[i] > this.progress && !sameCoord(coordinates.at(-1), node.coord)) coordinates.push(node.coord);
        });
        if (coordinates.length === 1) coordinates.push(coordinates[0]);
        return { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates } };
    }
}
