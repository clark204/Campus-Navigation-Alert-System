// The help queue's data, without the map (tested in tests/js/help-data.test.js): reading the points the help panel
// (admin/alert-help-panel) carries, telling which requests are new since the last refresh, and saying where a person
// is ("In the Library, 40 m from the incident").
import { buildingAt } from '../navigation/incident.js';
import { meters } from '../navigation/geo.js';

// { open, requests } from the panel's <script type="application/json" data-help-points>, or an empty queue.
export function readHelpPoints(root) {
    const script = root.querySelector('[data-help-points]');
    try {
        const data = JSON.parse(script?.textContent ?? '');
        return { open: Number(data.open) || 0, requests: Array.isArray(data.requests) ? data.requests : [] };
    } catch {
        return { open: 0, requests: [] };
    }
}

// Ids of open requests that weren't in `knownIds` (a Set). The first refresh only learns them: no beep on page load.
export function newRequestIds(knownIds, requests, firstLoad) {
    const open = requests.filter((request) => request.status !== 'helped').map((request) => request.id);
    return firstLoad ? [] : open.filter((id) => !knownIds.has(id));
}

export const hasPosition = (request) => Number.isFinite(request.latitude) && Number.isFinite(request.longitude);

// "In Science Building, 40 m from the incident", "Outside, 120 m from the incident", or "In Science Building".
// `buildings`: [{ id, name, type, ring }]; `incident`: [lng, lat] or null.
export function describeWhere(request, buildings, incident) {
    if (!hasPosition(request)) return null;
    const point = [request.longitude, request.latitude];
    const buildingId = buildingAt(point, buildings);
    const place = buildingId ? `In ${buildings.find((b) => b.id === buildingId)?.name ?? buildingId}` : 'Outside';
    if (!incident) return place;
    const distance = meters(point, incident);
    return `${place}, ${distance < 10 ? 'at' : `${Math.round(distance)} m from`} the incident`;
}

// The tab title with the open count first while anyone waits: "(2) need help · Emergency alerts".
export function titleWithCount(baseTitle, open) {
    return open > 0 ? `(${open}) need help · ${baseTitle}` : baseTitle;
}
