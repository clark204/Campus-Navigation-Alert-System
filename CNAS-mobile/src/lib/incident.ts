// The incident pin helpers, imported from the website (CNAS-web resources/js/navigation/incident.js) so the app's send
// form ticks the same building under the pin and words the place like the web form and Alert::locationLabel().
import { buildingAt as buildingAtJs, locationLabel as locationLabelJs } from '@nav/incident.js';

type Position = [number, number];

/** The id of the building or facility under a [lng, lat] point, or null. */
export const buildingAt = buildingAtJs as (point: Position, buildings: { id: string; type: string; ring: Position[] }[]) => string | null;

/** "NEE Building", "NEE Building and Library", "NEE Building and 2 more buildings", "Marked on the map", "Whole campus". */
export const locationLabel = locationLabelJs as (buildingNames: string[], hasPin: boolean) => string;
