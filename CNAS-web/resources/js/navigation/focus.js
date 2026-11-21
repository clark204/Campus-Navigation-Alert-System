// What /navigate shows on the map: the room dots and the street view highlights, by whether navigation is running
// and whether street view mode is on. Pure, so it can be tested in Node. The route, the dot, the start and
// destination pins, buildings and walkways are never hidden.

// Room dots hide while navigating. The highlights follow street view mode.
export function focusFor({ navigating, streetView }) {
    return { entrances: !navigating, streetViewLayers: streetView };
}
