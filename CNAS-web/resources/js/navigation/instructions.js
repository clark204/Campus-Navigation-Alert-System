import { bearingDeg, distanceToRing, meters, projectOnSegment, sameCoord, turnDelta } from './geo.js';
import { nearestOnEdges } from './graph.js';

export const STRAIGHT_DEG = 20;
export const SLIGHT_DEG = 60;
export const TURN_DEG = 150;
// Bends closer together than this are announced as one turn (hand-drawn paths have small jogs).
const MERGE_METERS = 5;
// A turn with less walking than this before or after it is folded into the step beside it ("walk 1 m" helps no one).
const TINY_METERS = 3;
// A building this close to a turn is mentioned as a landmark ("Turn left near the Library").
const LANDMARK_METERS = 12;

export function classifyTurn(delta) {
    const angle = Math.abs(delta);
    if (angle < STRAIGHT_DEG) return 'straight';
    const side = delta > 0 ? 'right' : 'left';
    if (angle < SLIGHT_DEG) return `slight ${side}`;
    if (angle <= TURN_DEG) return side;
    return 'uturn';
}

const TURN_PHRASES = {
    'slight left': 'Slight left',
    'slight right': 'Slight right',
    left: 'Turn left',
    right: 'Turn right',
    uturn: 'Turn around',
};

export function ordinal(n) {
    const suffixes = ['th', 'st', 'nd', 'rd'];
    const v = n % 100;
    return n + (suffixes[(v - 20) % 10] || suffixes[v] || suffixes[0]);
}

const lowerFirst = (text) => text.charAt(0).toLowerCase() + text.slice(1);

// Which side something at `target` is on for a person walking from `from` to `at`.
export function sideOf(from, at, target) {
    const delta = turnDelta(bearingDeg(from, at), bearingDeg(at, target));
    const angle = Math.abs(delta);
    if (angle < STRAIGHT_DEG) return 'straight ahead';
    if (angle > TURN_DEG) return 'behind you';
    return delta > 0 ? 'on your right' : 'on your left';
}

/*
 * Friendly names from landmarks.json. Ids like "b7" or "p3" and raw stair names like "NEE Stair A"
 * are never spoken: unknown buildings are simply not mentioned.
 */
export function createNamer(graph, landmarks = {}) {
    const stairNames = new Map();
    const building = (id) => landmarks.buildings?.[id] ?? null;
    const path = (id) => landmarks.paths?.[id] ?? 'the walkway';

    const stairs = (stairId) => {
        if (stairNames.has(stairId)) return stairNames.get(stairId);
        const foot = graph.nodes.get(`${stairId}:f1`);
        const groundRooms = [...graph.nodes.values()].filter(
            (n) => n.kind === 'room' && n.floor === 1 && n.buildingId === foot.buildingId,
        );
        let name = 'the stairs';
        if (groundRooms.length) {
            const nearest = groundRooms.reduce((a, b) => (meters(a.coord, foot.coord) <= meters(b.coord, foot.coord) ? a : b));
            name = `the stairs near ${nearest.name}`;
        } else if (building(foot.buildingId)) {
            name = `the stairs in ${building(foot.buildingId)}`;
        }
        stairNames.set(stairId, name);
        return name;
    };

    return { building, path, stairs };
}

function nearestLandmark(coord, buildings, namer) {
    let best = null;
    for (const feature of buildings?.features ?? []) {
        const name = namer.building(feature.properties.id);
        if (!name || !['building', 'facility'].includes(feature.properties.type)) continue;
        const d = distanceToRing(coord, feature.geometry.coordinates[0]);
        if (d <= LANDMARK_METERS && (!best || d < best.d)) best = { name, d };
    }
    return best?.name ?? null;
}

// True when the route starts at a ground-floor door with the short link from the door to the walkway.
const startsAtDoor = ({ nodes, edges }) => nodes[0].kind === 'room' && edges[0]?.type === 'link' && nodes[1]?.kind === 'path';

// Which way someone stepping out of the start door faces (bearing, door -> walkway), or null when the route
// doesn't start at a door. Doors have no stored direction, so the link to the walkway stands in for it.
export function doorHeading(route) {
    return startsAtDoor(route) ? bearingDeg(route.nodes[0].coord, route.nodes[1].coord) : null;
}

// The first instruction relative to the way the person faces: under 30° off is straight ahead, over 150° is behind.
const FACING_STRAIGHT_DEG = 30;
function facingPhrase(delta, target) {
    const angle = Math.abs(delta);
    if (angle < FACING_STRAIGHT_DEG) return { modifier: 'straight', text: `Walk straight ahead toward ${target}` };
    if (angle > TURN_DEG) return { modifier: 'uturn', text: `Turn around and walk toward ${target}` };
    const side = delta > 0 ? 'right' : 'left';
    return { modifier: side, text: `Turn ${side} toward ${target}` };
}

/*
 * Turns a route into maneuvers, ordered by where they happen:
 *   { type: 'depart'|'turn'|'stairs'|'arrive', modifier, along, text, ahead, now, legBefore, ... }
 * `text` is shown on screen, `ahead` follows "In 30 meters, ", `now` is the at-the-spot prompt. Turns also carry
 * `bearingAfter`, the direction walked after the turn.
 *
 * `heading` is the way the person faces at the start (compass bearing). Left out, a start at a door assumes facing
 * out of the door; null means unknown, and the first instruction then names a landmark instead of a direction.
 */
export function buildManeuvers(route, graph, { landmarks = {}, buildings = null, originName, destinationName, heading } = {}) {
    const { nodes, edges, along } = route;
    const namer = createNamer(graph, landmarks);
    const origin = nodes[0];
    const destination = nodes.at(-1);
    originName ??= origin.name ?? 'your start';
    destinationName ??= destination.name ?? 'your destination';
    const maneuvers = [];

    // Index of the last node where the person actually stands when arriving. For a ground-floor room
    // that is the walkway point beside the door, so the door link isn't announced as a turn.
    const endsAtDoorLink =
        destination.kind === 'room' && edges.at(-1)?.type === 'link' && nodes.at(-2)?.kind === 'path';
    const arriveIndex = endsAtDoorLink ? nodes.length - 2 : nodes.length - 1;
    // The same at the start: the short link from the door to the walkway is not a step of its own. Its bend
    // becomes the direction of the first instruction ("Head left toward ...").
    const startsAtDoorLink = startsAtDoor(route);
    const facing = heading === undefined ? doorHeading(route) : heading;

    // Nodes where the position changes (floors of a stairwell share one point).
    const moves = [0];
    for (let i = 1; i < nodes.length; i++) {
        if (!sameCoord(nodes[i].coord, nodes[moves.at(-1)].coord)) moves.push(i);
    }

    // --- Turns
    const bends = [];
    // A turn toward the stairs and a turn out of them upstairs are separate, however close.
    let crossedStairs = false;
    for (let k = 1; k < moves.length - 1; k++) {
        const i = moves[k];
        // A point on the stairs themselves (a corridor drawn to end on the stair point) is no turn: the person changes
        // floor there, so the way in and the way out are on different floors.
        const atStairs = nodes.slice(i, moves[k + 1]).some((n) => n.kind === 'stair');
        if (atStairs) crossedStairs = true;
        if (i >= arriveIndex || atStairs) continue;
        const delta = turnDelta(
            bearingDeg(nodes[moves[k - 1]].coord, nodes[i].coord),
            bearingDeg(nodes[i].coord, nodes[moves[k + 1]].coord),
        );
        const fromStart = startsAtDoorLink && i === 1;
        if (Math.abs(delta) < STRAIGHT_DEG && !fromStart) continue;
        const last = bends.at(-1);
        if (last && !crossedStairs && along[i] - last.lastAlong < (last.fromStart ? TINY_METERS : MERGE_METERS)) {
            last.delta = Math.max(-180, Math.min(180, last.delta + delta));
            last.lastAlong = along[i];
            last.nextMove = moves[k + 1];
        } else {
            bends.push({ index: i, delta, lastAlong: along[i], previousMove: moves[k - 1], nextMove: moves[k + 1], fromStart });
        }
        crossedStairs = false;
    }

    const departBend = bends[0]?.fromStart ? bends.shift() : null;

    // A turn just before the end is dropped; the arrival then says where the destination is as seen while
    // walking up to that turn.
    let approach = null;
    const lastBend = bends.at(-1);
    if (
        lastBend &&
        along[arriveIndex] - lastBend.lastAlong < TINY_METERS &&
        nodes.slice(lastBend.index, arriveIndex + 1).every((n) => n.kind !== 'stair')
    ) {
        bends.pop();
        approach = { from: nodes[lastBend.previousMove].coord, at: nodes[lastBend.index].coord };
    }

    for (const bend of bends) {
        const modifier = classifyTurn(bend.delta);
        if (modifier === 'straight') continue;
        const node = nodes[bend.index];
        const next = nodes[bend.nextMove];
        let hint = '';
        if (next.kind === 'stair') hint = ` toward ${namer.stairs(next.stairId)}`;
        else if (bend.nextMove >= arriveIndex && destination.kind === 'room') hint = ` toward ${destinationName}`;
        else if (node.floor === 1) {
            // Upstairs the person is inside a building, so buildings make no landmark there.
            const landmark = nearestLandmark(node.coord, buildings, namer);
            if (landmark) hint = ` near ${landmark}`;
        }
        const phrase = TURN_PHRASES[modifier];
        maneuvers.push({
            type: 'turn',
            modifier,
            along: along[bend.index],
            nodeId: node.id,
            bearingAfter: bearingDeg(node.coord, next.coord),
            text: `${phrase}${hint}`,
            ahead: lowerFirst(`${phrase}${hint}`),
            now: `${phrase} now`,
        });
    }

    // --- Stairs: one maneuver per run of stair edges
    for (let i = 0; i < edges.length; i++) {
        if (edges[i].type !== 'stairs' || edges[i - 1]?.type === 'stairs') continue;
        let end = i;
        while (edges[end + 1]?.type === 'stairs') end++;
        const fromFloor = nodes[i].floor;
        const toFloor = nodes[end + 1].floor;
        const direction = toFloor > fromFloor ? 'up' : 'down';
        const stairsName = namer.stairs(nodes[i].stairId);
        const text = `Go ${direction} ${stairsName} to the ${ordinal(toFloor)} floor`;
        maneuvers.push({
            type: 'stairs',
            modifier: direction,
            along: along[i],
            nodeId: nodes[i].id,
            fromFloor,
            toFloor,
            // "the stairs near NEE 101", for sentences about the stairs (legs.js).
            stairsName,
            text,
            ahead: lowerFirst(text),
            now: text,
        });
    }

    // --- Arrival
    let where = '';
    if (endsAtDoorLink && approach) {
        where = sideOf(approach.from, approach.at, destination.coord);
    } else if (endsAtDoorLink) {
        const standAt = nodes[arriveIndex].coord;
        const cameFrom = nodes.slice(0, arriveIndex).reverse().find((n) => !sameCoord(n.coord, standAt));
        if (cameFrom) where = sideOf(cameFrom.coord, standAt, destination.coord);
    } else if (destination.kind === 'room' && destination.floor > 1) {
        // Upper floors have no mapped corridors; assume the corridor runs above the nearest walkway, so
        // the walk goes from the stairs' spot on that walkway to the door's spot on it.
        const hit = nearestOnEdges(graph, destination.coord);
        if (hit) {
            const [a, b] = [graph.nodes.get(hit.edge.a).coord, graph.nodes.get(hit.edge.b).coord];
            const stairsSpot = projectOnSegment(nodes.at(-2).coord, a, b).point;
            if (meters(stairsSpot, hit.point) > 2) where = sideOf(stairsSpot, hit.point, destination.coord);
        }
        if (where === 'behind you') where = '';
    }
    const arriveText = where
        ? `You have arrived. ${destinationName} is ${where}`
        : `You have arrived at ${destinationName}`;
    maneuvers.push({
        type: 'arrive',
        modifier: where,
        along: along[arriveIndex],
        nodeId: nodes[arriveIndex].id,
        text: arriveText,
        ahead: `you will arrive at ${destinationName}`,
        now: arriveText,
    });

    // --- Departure. The door link is never a step of its own: with a known heading the first instruction says
    // which way to go relative to it; without one it names something to walk toward, never left or right.
    const firstMove = nodes[moves[1]];
    const firstStairs = nodes.find((n) => n.kind === 'stair');
    const target = firstStairs ? namer.stairs(firstStairs.stairId) : destinationName;
    const depart = { type: 'depart', modifier: '', along: 0, nodeId: origin.id, text: null, ahead: null };
    if (origin.kind === 'room' && firstMove?.kind === 'stair') depart.text = `Walk from ${originName} to ${namer.stairs(firstMove.stairId)}`;
    else if (facing !== null && moves.length > 1) {
        // The first walkway stretch: past the door link (its bend is departBend), or from the start itself.
        const first = bearingDeg(nodes[moves[0]].coord, nodes[moves[1]].coord);
        const leg = departBend
            ? (first + departBend.delta + 360) % 360
            : startsAtDoorLink && moves[2] !== undefined
              ? bearingDeg(nodes[moves[1]].coord, nodes[moves[2]].coord)
              : first;
        Object.assign(depart, facingPhrase(turnDelta(facing, leg), target));
    } else if (origin.kind === 'room' && origin.floor > 1) depart.text = `Leave ${originName}`;
    maneuvers.push(depart);

    const order = { depart: 0, turn: 1, stairs: 2, arrive: 3 };
    maneuvers.sort((a, b) => a.along - b.along || order[a.type] - order[b.type]);

    if (depart.text === null) {
        // "Walk toward ...": what the first stretch leads to, or a building beside the first turn.
        const first = maneuvers[1];
        let toward = destinationName;
        if (first?.type === 'stairs') toward = namer.stairs(nodes.find((n) => n.id === first.nodeId).stairId);
        else if (first?.type === 'turn') toward = nearestLandmark(graph.nodes.get(first.nodeId).coord, buildings, namer) ?? destinationName;
        depart.text = `Walk toward ${toward}`;
    }
    depart.now = depart.text;

    // Fold a turn with almost no walking beside it into the stairs or departure step it belongs to.
    for (let i = maneuvers.length - 2; i > 0; i--) {
        const turn = maneuvers[i];
        if (turn.type !== 'turn') continue;
        const [before, after] = [maneuvers[i - 1], maneuvers[i + 1]];
        const phrase = TURN_PHRASES[turn.modifier];
        let merged;
        if (before.type !== 'turn' && turn.along - before.along < TINY_METERS) {
            merged = before;
            merged.text = `${before.text}, then ${lowerFirst(phrase)}`;
        } else if (after.type === 'stairs' && after.along - turn.along < TINY_METERS) {
            merged = after;
            merged.text = `${phrase} and ${lowerFirst(after.text)}`;
        } else continue;
        if (merged.ahead !== null) merged.ahead = lowerFirst(merged.text);
        merged.now = merged.text;
        maneuvers.splice(i, 1);
    }
    maneuvers.forEach((m, i) => {
        m.index = i;
        m.legBefore = i === 0 ? 0 : m.along - maneuvers[i - 1].along;
    });
    return maneuvers;
}
