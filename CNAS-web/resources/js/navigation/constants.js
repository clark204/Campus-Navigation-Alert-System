// Numbers the navigation modules share with the map drawing. Kept here, with no imports, so every module the mobile
// app imports from this folder stays free of MapLibre, CSS and the DOM (tests/js/navigation-shared.test.js).

// Height of one floor; a flight of stairs is priced from it in the walk graph.
export const METERS_PER_FLOOR = 3.5;

// Spoken directions are off until the person turns on "Voice directions" (web: portal Settings, or the switch on
// /navigate for guests; app: the Account tab). The mute button only changes the trip under way. The route to safety
// always speaks, whatever this says.
export const VOICE_DIRECTIONS_DEFAULT = false;
