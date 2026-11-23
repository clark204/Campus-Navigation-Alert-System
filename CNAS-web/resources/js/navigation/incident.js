// The incident pin on the alert send form: which building is under the pin, and how the alert's place reads. Shared by
// the web picker (resources/js/alert-picker.js) and the mobile app's send form (CNAS-mobile, @nav/incident.js), so both
// tick the same building and say the same thing on the confirm step.
import { pointInRing } from './evacuation.js';

// Building types an alert can name as affected (CampusMap::AFFECTED_TYPES).
export const AFFECTED_TYPES = ['building', 'facility'];

// Same wording as Alert::locationLabel().
export function locationLabel(buildingNames, hasPin) {
    if (buildingNames.length === 1) return buildingNames[0];
    if (buildingNames.length === 2) return `${buildingNames[0]} and ${buildingNames[1]}`;
    if (buildingNames.length > 2) return `${buildingNames[0]} and ${buildingNames.length - 1} more buildings`;
    return hasPin ? 'Marked on the map' : 'Whole campus';
}

// The id of the building or facility under a [lng, lat] point, or null. `buildings` are { id, type, ring } (ring: the
// outer [lng, lat] ring).
export function buildingAt(point, buildings) {
    return buildings.find((building) => AFFECTED_TYPES.includes(building.type) && pointInRing(point, building.ring))?.id ?? null;
}
