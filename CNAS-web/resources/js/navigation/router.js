import { meters, sameCoord } from './geo.js';
import { otherEnd } from './graph.js';

// Minimal binary heap keyed by priority, enough for a campus-sized graph.
class MinHeap {
    items = [];

    get size() {
        return this.items.length;
    }

    push(value, priority) {
        const items = this.items;
        items.push({ value, priority });
        let i = items.length - 1;
        while (i > 0) {
            const parent = (i - 1) >> 1;
            if (items[parent].priority <= items[i].priority) break;
            [items[parent], items[i]] = [items[i], items[parent]];
            i = parent;
        }
    }

    pop() {
        const items = this.items;
        const top = items[0];
        const last = items.pop();
        if (items.length) {
            items[0] = last;
            let i = 0;
            for (;;) {
                const [l, r] = [2 * i + 1, 2 * i + 2];
                let smallest = i;
                if (l < items.length && items[l].priority < items[smallest].priority) smallest = l;
                if (r < items.length && items[r].priority < items[smallest].priority) smallest = r;
                if (smallest === i) break;
                [items[smallest], items[i]] = [items[i], items[smallest]];
                i = smallest;
            }
        }
        return top.value;
    }
}

// Dijkstra. `to` is a node id or a Set of them (the nearest reachable one wins). `cost` prices each edge; Infinity
// blocks it (e.g. inside an emergency danger zone). Returns { nodeIds, edges, cost } or null when unreachable.
export function shortestPath(graph, fromId, to, { cost = (edge) => edge.weight } = {}) {
    const targets = to instanceof Set ? to : new Set([to]);
    if (!graph.nodes.has(fromId) || ![...targets].some((id) => graph.nodes.has(id))) return null;
    const dist = new Map([[fromId, 0]]);
    const prev = new Map();
    const done = new Set();
    const heap = new MinHeap();
    heap.push(fromId, 0);
    let reached = null;

    while (heap.size) {
        const id = heap.pop();
        if (done.has(id)) continue;
        done.add(id);
        if (targets.has(id)) {
            reached = id;
            break;
        }
        for (const edgeId of graph.adjacency.get(id)) {
            const edge = graph.edges.get(edgeId);
            const weight = cost(edge);
            if (weight === Infinity) continue;
            const next = otherEnd(edge, id);
            const total = dist.get(id) + weight;
            if (total < (dist.get(next) ?? Infinity)) {
                dist.set(next, total);
                prev.set(next, { id, edge });
                heap.push(next, total);
            }
        }
    }

    if (reached === null) return null;
    const nodeIds = [reached];
    const edges = [];
    for (let id = reached; id !== fromId; ) {
        const step = prev.get(id);
        edges.unshift(step.edge);
        nodeIds.unshift(step.id);
        id = step.id;
    }
    return { nodeIds, edges, cost: dist.get(reached) };
}

/*
 * Route for navigation:
 *   nodes: graph nodes in walking order
 *   edges: edges[i] joins nodes[i] and nodes[i + 1]
 *   along: horizontal meters from the start to each node (stairs add nothing)
 *   length: total horizontal meters
 *   line: GeoJSON LineString Feature of the walk (repeated points at stairs removed)
 * `to` and `options` as for shortestPath.
 */
export function findRoute(graph, fromId, to, options) {
    const path = shortestPath(graph, fromId, to, options);
    if (!path) return null;

    const nodes = path.nodeIds.map((id) => graph.nodes.get(id));
    const along = [0];
    const coordinates = [nodes[0].coord];
    for (let i = 1; i < nodes.length; i++) {
        along.push(along[i - 1] + meters(nodes[i - 1].coord, nodes[i].coord));
        if (!sameCoord(coordinates.at(-1), nodes[i].coord)) coordinates.push(nodes[i].coord);
    }
    // A LineString needs two points even when start and end share a door point.
    if (coordinates.length === 1) coordinates.push(coordinates[0]);

    return {
        nodes,
        edges: path.edges,
        along,
        length: along.at(-1),
        cost: path.cost,
        line: { type: 'Feature', properties: {}, geometry: { type: 'LineString', coordinates } },
    };
}
