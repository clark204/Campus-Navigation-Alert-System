import { ordinal } from './instructions.js';

/*
 * One floor at a time on /navigate: a route is shown as legs split at each stairs step (instructions.js). Each leg
 * ends at the stairs it leads to, or for the last leg at the destination, and that end is where the pin goes. This
 * only decides what is drawn and said; the route and the tracker are unchanged.
 *
 *   { index, floor, from, to, stairs: the stairs maneuver ending the leg | null,
 *     target: { kind: 'stairs' | 'destination', coord }, destination: { name, floor } }
 *
 * `from` and `to` are meters along the route.
 */
export function routeLegs(route, maneuvers, { destinationName }) {
    const stairsSteps = maneuvers.filter((m) => m.type === 'stairs');
    const destination = { name: destinationName, floor: route.nodes.at(-1).floor };
    const legs = [];
    let from = 0;
    let floor = route.nodes[0].floor;
    for (const stairs of stairsSteps) {
        const coord = route.nodes.find((node) => node.id === stairs.nodeId).coord;
        legs.push({ index: legs.length, floor, from, to: stairs.along, stairs, target: { kind: 'stairs', coord }, destination });
        from = stairs.along;
        floor = stairs.toFloor;
    }
    legs.push({
        index: legs.length,
        floor,
        from,
        to: route.length,
        stairs: null,
        target: { kind: 'destination', coord: route.nodes.at(-1).coord },
        destination,
    });
    return legs;
}

// The leg the person is on: one more for every stairs step they have confirmed (the tracker waits at each).
export function currentLeg(legs, tracker) {
    const confirmed = legs.filter((leg) => leg.stairs && tracker.confirmedStairs.has(leg.stairs.index)).length;
    return legs[Math.min(confirmed, legs.length - 1)];
}

// Said when a leg that ends at stairs begins: "Go to the stairs near NEE 101, then NEE 202 is on the 2nd floor".
export function legIntro(legs, leg) {
    if (!leg.stairs) return null;
    const { name, floor } = leg.destination;
    return `Go to ${leg.stairs.stairsName}, then ${name} is on the ${ordinal(floor)} floor`;
}

// Where the current leg leads, under the instruction card.
export function legLabel(legs, leg) {
    const { name, floor } = leg.destination;
    return leg.stairs ? `to ${leg.stairs.stairsName} · then ${name}, ${ordinal(floor)} floor` : `to ${name}`;
}

// The point `at` meters along the route.
function pointAt(route, at) {
    const { nodes, along } = route;
    const i = along.findIndex((value) => value >= at);
    if (i === -1) return nodes.at(-1).coord;
    if (i === 0 || along[i] === at) return nodes[i].coord;
    const f = (at - along[i - 1]) / (along[i] - along[i - 1]);
    const [a, b] = [nodes[i - 1].coord, nodes[i].coord];
    return [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f];
}

const sameCoord = (a, b) => a[0] === b[0] && a[1] === b[1];

function lineFeature(coordinates) {
    if (coordinates.length === 1) coordinates.push(coordinates[0]);
    return { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates } };
}

// The part of a leg still to walk, from `progress` meters along the route to the leg's end (GeoJSON LineString).
export function legLine(route, leg, progress) {
    const start = Math.max(progress, leg.from);
    const coordinates = [pointAt(route, start)];
    route.nodes.forEach((node, i) => {
        if (route.along[i] > start && route.along[i] < leg.to && !sameCoord(coordinates.at(-1), node.coord)) coordinates.push(node.coord);
    });
    const end = pointAt(route, leg.to);
    if (!sameCoord(coordinates.at(-1), end)) coordinates.push(end);
    return lineFeature(coordinates);
}

// For the preview: the route's line split into ground-floor parts and upstairs parts (lists of coordinates).
export function floorParts(route) {
    const parts = { ground: [], upper: [] };
    let key = null;
    let run = [];
    const finish = () => {
        if (run.length > 1) parts[key].push(run);
    };
    for (const node of route.nodes) {
        const nodeKey = node.floor > 1 ? 'upper' : 'ground';
        if (nodeKey !== key) {
            if (key !== null) finish();
            key = nodeKey;
            run = [];
        }
        if (!run.length || !sameCoord(run.at(-1), node.coord)) run.push(node.coord);
    }
    finish();
    return parts;
}
