# Live navigation and panoramas

Read this before touching /navigate, routing, voice, tracking, or the 360 photos.

## Decisions
- Goal: Google Maps-style walking navigation with a moving dot and voice guidance.
- GPS is unreliable at TCC (tree canopy and roofs, 10 to 30 m error). Never trust raw GPS alone for routing.
- Clark's test phone has no compass. Navigation must still work without one.
- Phones cannot detect floor changes. For upper-floor rooms the destination pin goes on the stairs first, then moves to the room after the user goes up.
- Directions use names students know (`landmark_name`), never codes like "Building 04" or "NEE Stair A".
- If the user walks a different way, the route is redrawn.
- Voice uses the Web Speech API. On iPhone it must be unlocked by a tap (Start button).
- No WebXR AR (test phone is not ARCore-supported). Street View-style 360 photos are used instead.

## Where the code is
All in `resources/js/navigation/`, page `resources/views/pages/navigate-page.blade.php` (route `/navigate`):
- `graph.js`: builds the walk graph from paths, doors, and stairs. A path end within 2 m of another path joins it at the closest point on that path (splitting the segment), not at its nearest vertex. Joining at a vertex moved Main Walkway's start 1.5 m along the Entrance Gate and the route slanted off the drawn walkway.
- `router.js`: shortest path
- `legs.js`, `instructions.js`: turn-by-turn steps and floor changes
- `position.js`, `gps.js`, `motion.js`, `steps.js`: where the user is
- `tracker.js`: progress along the route, re-routing
- `voice.js`, `wakelock.js`, `focus.js`, `storage.js`
- `evacuation.js`: safe route during an alert (see alerts.md)
- `simulate.js`: fake a walk for testing without going to campus
- `saved-places.js`: "Save" next to "Where to?" (signed in, room destinations); Start adds the room to Recent (see portal.md)
- `main.js`: wires it all to the MapLibre map; has a debug panel
Packages: MapLibre GL and single Turf modules (along, bearing, distance, length, nearest-point-on-line). Do not add the full Turf bundle.

## Mobile app (CNAS-mobile)
- The app imports these modules straight from this folder (its `metro.config.js`, alias `@nav/`), so it gives the same routes and directions. Shared: `constants`, `evacuation`, `geo`, `gps`, `graph`, `instructions`, `legs`, `motion`, `position`, `route-panoramas`, `router`, `simulate`, `steps`, `storage`, `tracker`, `voice`. Only `main.js` (and the other page scripts) may touch the DOM or MapLibre.
- Those modules import only each other and single Turf modules. `METERS_PER_FLOOR` lives in `navigation/constants.js` for that reason (`map-style.js` re-exports it). `tests/js/navigation-shared.test.js` fails when a shared module imports anything else or needs a DOM to load.
- The app ports `main.js`'s session (texts, reroute, manual mode, legs) to `CNAS-mobile/src/lib/navigation/session.ts`. Change both when the behaviour changes.

## Panoramas (360 photos)
- Shot about every 3 m along walkways, named p1, p2, ... Admin places each one on the map.
- Table `panoramas`: code, name, lat/lng, floor, north_offset, is_visible, image_path, thumb_path
- Server: `Panorama` model, `PanoramaImages`, `PanoramaImporter`, `Admin/PanoramaController`, command `ImportPanoramas`
- Admin JS: `panoramas*.js`, `panorama-*.js`, `photo-links.js`
- Viewer: `navigation/street-view.js`, `navigation/route-panoramas.js` (Photo Sphere Viewer)
- Street view mode has no camera markers: users tap a highlighted walkway, like Google Maps. A photo more than 8 m from every walkway lights nothing, so it can only be reached from the route or from another photo's arrows.
- Only walkways with photos light up: the stretch between neighbouring photos, when the walk between them is at most 30 m (`JOIN_METERS`), round corners too. Nothing lights past the last photo or on a side walkway without photos. A photo with no neighbour lights 5 m each way (`ALONE_METERS`). The walks come from `photoWalks` in `photo-links.js`, the same ones the viewer's arrows use.
- Gotcha: the highlights are laid on the paths as drawn (`paths` from `/api/map`), not on the graph's edges. Joining paths moves their ends by up to 2 m in the graph, which put the highlight beside the walkway.
- Gotcha (admin "Edit on map", `panorama-map-editor.js`): never assign `className` to a pin that is already on the map. It removes MapLibre's marker classes and every pin after the first is drawn in the wrong place. Toggle single classes instead.
- Status 2026-11-14: only p1 to p10 exist (main walkway from the Entrance Gate).

## Testing
- JS tests: `npm run test:js` (`tests/js/navigation.test.js`, `street-view.test.js`, `route-panoramas.test.js`, `focus-motion.test.js`)
- Motion sensors and voice need HTTPS on a real phone (use a tunnel) and `npm run build`.
