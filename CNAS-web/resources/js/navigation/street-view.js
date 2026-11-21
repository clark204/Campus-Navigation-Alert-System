// Street view mode on /navigate: the walkway between neighbouring 360° photo points lights up, and a tap on it opens
// the photo nearest to the tap. Also the map padding for the two layouts (bottom sheet on phones, card on desktops).
// Only reads the walkway graph (graph.js); no DOM, so it can be tested in Node.
import { photoWalks, snapPhotos } from '../photo-links.js';
import { angleBetween, bearingDeg, meters, projectOnSegment } from './geo.js';

// Two photos light the walkway between them when the walk from one to the next is at most this long…
export const JOIN_METERS = 30;
// …and a photo with no such neighbour lights this far each way along its own walkway, so there is something to tap.
export const ALONE_METERS = 5;
// A photo counts when it stands at most this far from a walkway on its floor (farther, it lights nothing, so no tap
// opens it).
export const SNAP_METERS = 8;
// A tap or click this close to a lit stretch counts as one on it (about a finger's width); the mouse pointer changes
// over the same area, so it shows wherever a click opens a photo.
export const TAP_PIXELS = 20;

const lerp = (a, b, t) => [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];

// The spot on a drawn line nearest to `coord`; `at` counts the line's points (2.5 = halfway from the third to the fourth).
function onLine(line, coord) {
    let best = null;
    for (let i = 1; i < line.length; i++) {
        const hit = projectOnSegment(coord, line[i - 1], line[i]);
        if (!best || hit.distance < best.distance) best = { point: hit.point, distance: hit.distance, at: i - 1 + hit.t };
    }
    return best;
}

// The part of a drawn line from the spot nearest to `from` to the spot nearest to `to`, with the bends between them.
function alongLine(line, from, to) {
    const [start, end] = [onLine(line, from), onLine(line, to)];
    const [low, high] = start.at <= end.at ? [start, end] : [end, start];
    const coords = [low.point, ...line.slice(Math.floor(low.at) + 1, Math.ceil(high.at)), high.point];
    return start.at <= end.at ? coords : coords.reverse();
}

/*
 * Which walkway stretches light up: { pieces, snaps, photos, unsnapped, stretch }.
 *   pieces: [{ photo, edgeId, floor, from, to, coords }] — meters along the edge from its `a` end
 *   snaps: Map id → { point, bearing, floor }: where the photo meets its walkway, and the walkway's direction there
 *   unsnapped: ids of photos too far from any walkway on their floor
 *   stretch(edgeId, from, to): the map coordinates of that part of an edge
 * Only walkways with photos on them light up: from each photo, the walk to the first photo reached each way along the
 * walkways (photo-links.js photoWalks; round a corner too), when it is at most `join` meters. Nothing lights past the
 * last photo of a walkway, nor on a side walkway without photos. A photo with no neighbour lights `alone` meters each
 * way.
 *
 * `paths` is the map's paths as they are drawn (GET /api/map). Joining paths moves their ends by up to 2 m in the graph
 * (graph.js stitchPaths), so a stretch is laid on the drawn line of its path, not on the graph's edge: the highlight
 * then sits on the walkway on the map. Without `paths` it follows the graph.
 */
export function streetViewCoverage(graph, points, { join = JOIN_METERS, alone = ALONE_METERS, snap = SNAP_METERS, paths = null } = {}) {
    const pieces = [];
    const snaps = new Map();
    const drawn = new Map((paths?.features ?? []).map((feature) => [feature.properties.id, feature.geometry.coordinates]));
    const ends = (edge) => [graph.nodes.get(edge.a).coord, graph.nodes.get(edge.b).coord];
    const stretch = (edgeId, from, to) => {
        const edge = graph.edges.get(edgeId);
        const [a, b] = ends(edge);
        const length = meters(a, b) || 1;
        const straight = [lerp(a, b, from / length), lerp(a, b, to / length)];
        const line = drawn.get(edge.path);
        return line?.length > 1 ? alongLine(line, ...straight) : straight;
    };

    const snapped = snapPhotos(graph, points, snap);
    const walks = photoWalks(graph, snapped, join);
    for (const [id, { edge: own, t, length, at, floor }] of snapped) {
        const add = (edge, from, to) => {
            if (to - from > 0.01) pieces.push({ photo: id, edgeId: edge.id, floor, from, to, coords: stretch(edge.id, from, to) });
        };
        snaps.set(id, { point: at, bearing: bearingDeg(...ends(own)), floor });

        // Each walk to a neighbour, edge by edge: trail[i] and trail[i + 1] are where the walk enters and leaves edges[i].
        const neighbours = walks.get(id);
        for (const { trail, edges } of neighbours) {
            edges.forEach((edge, i) => {
                const [from, to] = [trail[i], trail[i + 1]].map((coord) => meters(ends(edge)[0], coord)).sort((x, y) => x - y);
                add(edge, from, to);
            });
        }
        if (!neighbours.length) add(own, Math.max(0, t * length - alone), Math.min(length, t * length + alone));
    }
    const unsnapped = points.filter((point) => !snapped.has(point.id)).map((point) => point.id);
    return { pieces, snaps, photos: new Map(points.map((p) => [p.id, p])), unsnapped, stretch };
}

// The lit stretches of one floor as GeoJSON lines for the map; pieces that overlap on a walkway are joined.
export function coverageLines(coverage, floor) {
    const byEdge = new Map();
    for (const piece of coverage.pieces.filter((p) => p.floor === floor)) {
        if (!byEdge.has(piece.edgeId)) byEdge.set(piece.edgeId, []);
        byEdge.get(piece.edgeId).push(piece);
    }
    const features = [];
    for (const [edgeId, pieces] of byEdge) {
        pieces.sort((x, y) => x.from - y.from);
        let run = null;
        const flush = () =>
            run && features.push({ type: 'Feature', properties: { meters: run.to - run.from }, geometry: { type: 'LineString', coordinates: coverage.stretch(edgeId, run.from, run.to) } });
        for (const piece of pieces) {
            if (run && piece.from <= run.to + 0.01) {
                run.to = Math.max(run.to, piece.to);
            } else {
                flush();
                run = { from: piece.from, to: piece.to };
            }
        }
        flush();
    }
    return { type: 'FeatureCollection', features };
}

// The photo a tap at `coord` opens: of the photos whose lit stretch is within `toleranceMeters` of the tap, the one
// nearest to it. null when no lit stretch is that close.
export function photoAtTap(coverage, coord, { toleranceMeters, floor = 1 }) {
    const candidates = new Set();
    const near = (coords) => coords.some((end, i) => i > 0 && projectOnSegment(coord, coords[i - 1], end).distance <= toleranceMeters);
    for (const piece of coverage.pieces) {
        if (piece.floor === floor && near(piece.coords)) candidates.add(piece.photo);
    }
    let best = null;
    for (const id of candidates) {
        const point = coverage.photos.get(id);
        const distance = meters(coord, point.coord);
        if (!best || distance < best.distance) best = { point, distance };
    }
    return best?.point ?? null;
}

// Off the route, a photo opens facing along its walkway: of the two ways, the one closer to how the map is turned.
export function alongPathFacing(snapped, mapBearing) {
    const reverse = (snapped.bearing + 180) % 360;
    return angleBetween(snapped.bearing, mapBearing) <= angleBetween(reverse, mapBearing) ? snapped.bearing : reverse;
}

// What a tap on the map does: in street view mode it only looks for a photo (it never picks a place or places a
// simulated fix); otherwise, while simulating a walk, it moves the fake GPS.
export function mapTapAction({ streetView, simulating, navigating }) {
    if (streetView) return 'street-view';
    if (simulating && navigating) return 'simulate';
    return 'none';
}

// The street view button is only there when some photo is shown to users.
export const streetViewAvailable = (points) => points.length > 0;

/*
 * Map padding so the route and the dot sit in the part of the map not covered: on a phone, above the bottom sheet
 * and below the debug panel; on a desktop, left of the card and right of the debug panel. Below an alert banner on
 * both. `card` and `debug` are screen rectangles ({ left, right, bottom }); `bannerBottom` is 0 without a banner.
 */
export function mapPadding({ desktop, viewport, sheetHeight = 0, card = null, debug = null, bannerBottom = 0 }) {
    const belowBanner = bannerBottom ? bannerBottom + 16 : 0;
    if (desktop) {
        return {
            top: Math.max(80, belowBanner),
            left: debug ? debug.right + 24 : 24,
            right: card ? viewport.width - card.left + 12 : 24,
            bottom: 24,
        };
    }
    const bottom = sheetHeight + 24;
    const top = Math.max(debug ? debug.bottom + 16 : 80, belowBanner);
    return { top: Math.max(0, Math.min(top, viewport.height - bottom - 120)), left: 24, right: 24, bottom };
}
