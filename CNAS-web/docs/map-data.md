# Campus map data

Read this before touching buildings, paths, stairs, rooms, or the map export and import.

## Decisions
- Since 2026-11-12 the map lives in the database, not in `public/maps/*.geojson` (those files are gone).
- Map data is edited in another app. This site has no map editors: Admin > Map data downloads the whole map as one GeoJSON file, `campus.geojson`, and imports one back. 360° photos stay editable here (see navigation.md).
- Shapes, floors, types and positions come from the other app. An import updates them for codes already on the map, adds new codes, and deletes nothing. Codes missing from the file are kept and listed after the import, so removals made in the other app are not applied yet.
- Names are edited here, on Admin > Buildings (name, landmark name) and Admin > Rooms (name). An import keeps the names and landmark names of codes already on the map. New codes take the file's names.
- Photos: `place_photos` (morphs to a building or room; the first by position is the cover, up to 6 per place). A normal photo is JPEG, PNG or WebP up to 8 MB, turned upright from its EXIF orientation, with a web copy (long side 1600 px) and a thumbnail (480 px). A 360 photo follows the walkway 360° rules (`PanoramaImages`) and gets its copies at 4096 and 640 px. Copies are re-encoded by GD, so they carry no EXIF (no GPS). Deleting a photo deletes its files.
- The Buildings and Rooms lists search as you type and show 10 per page. Every row is in the page, so the search and paging run in the browser (`table-search.js`) with no request. The building chips on Rooms are page links (`?building=`).
- Photos are served in `/api/map` (each building and room feature has `photos`: kind, image, thumb, width, height). They are not in `campus.geojson` and not on /navigate yet.
- 360° photos are not in `campus.geojson`; they have their own upload and import.
- Route calculation stays in the browser. The server only stores and serves the map.
- Scope agreed with the instructor: focus on the IT department. Rooms have an `is_it` flag.
- Students do not know building numbers or stair labels, so each building and path has a `landmark_name` (the name students actually use) for directions.
- Colors: buildings are brick red, the campus and grounds are green. Alarm-affected buildings are drawn at full alarm red with a thick dark edge, because their fill is barely darker than a normal building (about 1.3:1).
- Gates are start points in /navigate. The Vehicle Gate (p15) sits 1.3 m from the Entrance Gate (p1), so it is joined to it on the walk graph (see gotchas). It needs no extra walkway.

## Gotchas
- Path ends within 2 m of another path join it (`SNAP_METERS` in `navigation/graph.js`). Keep a new path at least 2 m from others unless it should connect.
- Entrance dots are dark maroon with a white ring, so they stay visible on brick-red buildings.

## Tables
- `buildings`: code, name, type (campus, building, facility, evacuation), floors, landmark_name, outline (json polygon)
- `paths`: code, name, type (walkway, gate), floor (default 1), landmark_name, line (json)
- `stairs`: code, name, building_id, floors, center lat/lng, outline (json)
- `rooms`: code, name, building_id, floor, type (classroom, lab, office, cr), is_it, door, lat/lng
- `place_photos`: kind (photo or panorama), image_path, thumb_path, width, height, position, place (morph to buildings or rooms)
- `panoramas`: see navigation.md

## Where the code is
- Models: `Building`, `Path`, `Stair`, `Room`, `PlacePhoto` (`photos()` on Building and Room, ordered by position)
- Read side: `app/Services/CampusMap.php`, served by `GET /api/map` (`Api/MapController`, ETag cached). Front end loads it with `resources/js/map-data.js`.
- Export and import: `app/Services/CampusMapFile.php` (campus.geojson, built from the same payload as the map), `Admin/CampusMapController` (routes `admin.map.*`), page `resources/views/admin/map-data-page.blade.php`
- Import: `app/Services/CampusMapImporter.php`. `importCampus()` takes the sections that `CampusMapFile` splits the file into and keeps existing names; `import()` reads a folder (used by the seeder `CampusMapSeeder` and `php artisan map:import <folder>`, which keeps names unless `--overwrite`).
- Names and photos: `Admin/BuildingController`, `Admin/RoomController` (routes `admin.buildings.*`, `admin.rooms.*`; photo routes are nested under the place), requests `UpdateBuildingRequest`, `UpdateRoomRequest`, `StorePlacePhotosRequest`, service `PlacePhotos` (add, move, EXIF), `PhotoCopies` (decode, scale, write; shared with `PanoramaImages`), views `admin/buildings-page`, `building-edit-page`, `rooms-page`, `room-edit-page` and the shared `admin/place-photos` partial, JS `place-photos.js` (file types and the 360° viewer).
- campus.geojson is one FeatureCollection. Each feature has `properties.kind` (`building`, `path`, `room` or `stair`), so the import knows which section it belongs to. Buildings and paths carry `landmark_name`. Rooms are `entrances` in the map payload.
- `MAX_FLOORS` (10) lives in `CampusMap`.
- Map look: `resources/js/map-style.js`, `campus-map.js` (MapLibre GL)

## Tests
`tests/Feature/Admin/CampusMapFileTest.php`, `BuildingTest.php`, `RoomTest.php`, `PlacePhotoTest.php`, `tests/Feature/CampusMapImporterTest.php`, `CampusMapModelsTest.php`, `MapApiTest.php`, `tests/js/map-data.test.js`, `tests/js/place-photos.test.js`

## Editing in JOSM
JOSM saves `campus.geojson` differently from the export, and the import accepts both (`CampusMapFeatureReader`, `CampusMapCodes`):
- A closed building or stair is saved as a LineString. The first and last points must be the same; it becomes a Polygon. A shape that is not closed is refused, naming the feature.
- Every value is text. `"3"` is 3, `"true"`/`"false"` is a real boolean (`"false"` stays false), and a stair `center` such as `"[124.74,8.54]"` is a point. A value that is not valid is refused, naming the feature.
- Rooms stacked on one spot are one feature with their codes joined by `;` (`r16;r17;r18`). Each listed room must already be on the map; only its position and building change. Its own name, floor, type, door and is_it stay. A new room must be added one at a time.
- A feature drawn without a code is matched by name (ignoring capitals and extra spaces) to an existing feature of the same kind whose code no other feature in the file uses. One match: it keeps that code (redrawn). No match: it gets the next free code for its kind. More than one match: it is added as new and listed under "Possible duplicates".
- The `generator` key and other extra top-level keys are ignored.
- Names and landmark names on the map are never overwritten. Nothing is deleted.
- The result page lists by name: added, updated, matched by name, possible duplicates, and kept because not in the file.

Fixture and tests: `tests/Fixtures/josm/campus.geojson`, `tests/Feature/Admin/CampusMapJosmTest.php`.
