<?php

namespace App\Services;

use App\Enums\BuildingType;
use App\Enums\PathType;
use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Path;
use App\Models\Room;
use App\Models\Stair;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fills the map tables from GeoJSON, either a folder (buildings.geojson, paths.geojson, entrances.geojson for the
 * rooms, and optionally stairs.geojson and landmarks.json) or the sections of campus.geojson, which `CampusMapFile` splits its features into.
 *
 * Rows are matched by code. A new code is added in full. For a code that already exists only its shape is
 * refreshed, so names and other details survive unless $overwrite replaces them from the file. Nothing is ever
 * deleted.
 */
class CampusMapImporter
{
    private const BUILDINGS_FILE = 'buildings.geojson';

    private const PATHS_FILE = 'paths.geojson';

    private const ROOMS_FILE = 'entrances.geojson';

    private const STAIRS_FILE = 'stairs.geojson';

    private const LANDMARKS_FILE = 'landmarks.json';

    /** The GeoJSON geometry type each kind of feature must have. */
    private const POLYGON = 'Polygon';

    private const LINE = 'LineString';

    private const POINT = 'Point';

    /**
     * @return array{
     *     imported: array{buildings: int, paths: int, stairs: int, rooms: int},
     *     updated: array{buildings: int, paths: int, stairs: int, rooms: int},
     *     missing: array{buildings: list<string>, paths: list<string>, stairs: list<string>, rooms: list<string>},
     * }
     *
     * @throws InvalidArgumentException when a required file is missing or unreadable, or a feature is invalid.
     */
    public function import(string $directory, bool $overwrite = false): array
    {
        return $this->importDocuments([
            self::BUILDINGS_FILE => $this->readFile($directory, self::BUILDINGS_FILE),
            self::PATHS_FILE => $this->readFile($directory, self::PATHS_FILE),
            self::ROOMS_FILE => $this->readFile($directory, self::ROOMS_FILE),
            self::STAIRS_FILE => $this->readFile($directory, self::STAIRS_FILE),
            self::LANDMARKS_FILE => $this->readFile($directory, self::LANDMARKS_FILE),
        ], $overwrite);
    }

    /**
     * Imports the map from its sections: a FeatureCollection each for buildings, paths, entrances (the rooms) and
     * stairs, and the landmark names by code. Used by campus.geojson, which splits its features into these.
     *
     * @param  array{
     *     buildings: array{type: string, features: list<mixed>},
     *     paths: array{type: string, features: list<mixed>},
     *     entrances: array{type: string, features: list<mixed>},
     *     stairs: array{type: string, features: list<mixed>},
     *     landmarks: array{buildings: array<string, string>, paths: array<string, string>},
     * }  $sections
     * @return array{
     *     imported: array{buildings: int, paths: int, stairs: int, rooms: int},
     *     updated: array{buildings: int, paths: int, stairs: int, rooms: int},
     *     missing: array{buildings: list<string>, paths: list<string>, stairs: list<string>, rooms: list<string>},
     * }
     *
     * @throws InvalidArgumentException when a feature is invalid.
     */
    public function importCampus(array $sections): array
    {
        return $this->importDocuments([
            self::BUILDINGS_FILE => $sections['buildings'],
            self::PATHS_FILE => $sections['paths'],
            self::ROOMS_FILE => $sections['entrances'],
            self::STAIRS_FILE => $sections['stairs'],
            self::LANDMARKS_FILE => $sections['landmarks'],
        ], overwrite: false, keepNames: true);
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $documents  decoded files by file name, null when absent
     * @return array{
     *     imported: array{buildings: int, paths: int, stairs: int, rooms: int},
     *     updated: array{buildings: int, paths: int, stairs: int, rooms: int},
     *     missing: array{buildings: list<string>, paths: list<string>, stairs: list<string>, rooms: list<string>},
     * }
     *
     * @throws InvalidArgumentException when a required file is missing or a feature is invalid.
     */
    private function importDocuments(array $documents, bool $overwrite, bool $keepNames = false): array
    {
        $buildings = $this->features($documents, self::BUILDINGS_FILE, self::POLYGON);
        $paths = $this->features($documents, self::PATHS_FILE, self::LINE);
        $rooms = $this->features($documents, self::ROOMS_FILE, self::POINT);
        $stairs = $this->features($documents, self::STAIRS_FILE, self::POLYGON, required: false);
        $landmarks = $this->landmarks($documents);

        $results = DB::transaction(function () use ($buildings, $paths, $rooms, $stairs, $landmarks, $overwrite, $keepNames) {
            $results = [
                'buildings' => $this->sync(Building::class, $this->buildingRows($buildings, $landmarks), $overwrite, $keepNames),
                'paths' => $this->sync(Path::class, $this->pathRows($paths, $landmarks), $overwrite, $keepNames),
            ];
            $buildingIds = Building::query()->pluck('id', 'code')->all();

            return $results + [
                'stairs' => $this->sync(Stair::class, $this->stairRows($stairs, $buildingIds), $overwrite, $keepNames),
                'rooms' => $this->sync(Room::class, $this->roomRows($rooms, $buildingIds), $overwrite, $keepNames),
            ];
        });

        CampusMap::forgetPayload();

        return [
            'imported' => array_map(fn (array $result) => $result['imported'], $results),
            'updated' => array_map(fn (array $result) => $result['updated'], $results),
            'missing' => array_map(fn (array $result) => $result['missing'], $results),
            'items' => array_map(fn (array $result) => $result['items'], $results),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<string, array{details: array<string, mixed>, shape: array<string, mixed>}>  $rows  by code
     * @return array{imported: int, updated: int, missing: list<string>}
     */
    private function sync(string $model, array $rows, bool $overwrite, bool $keepNames): array
    {
        $existing = $model::query()->get()->keyBy('code');
        $imported = 0;
        $updated = 0;
        $added = [];
        $changed = [];

        foreach ($rows as $code => $row) {
            $record = $existing->get($code);

            if ($record === null) {
                $model::query()->create(['code' => $code, ...$row['details'], ...$row['shape']]);
                $imported++;
                $added[] = ['code' => (string) $code, 'name' => (string) ($row['details']['name'] ?? $code)];

                continue;
            }

            $record->fill(match (true) {
                $overwrite => [...$row['details'], ...$row['shape']],
                // Names are edited on this site, so they stay; the rest of the file's details still update.
                $keepNames => [...Arr::except($row['details'], ['name', 'landmark_name']), ...$row['shape']],
                default => $row['shape'],
            });
            if ($record->isDirty()) {
                $record->save();
                $updated++;
                $changed[] = ['code' => (string) $code, 'name' => (string) $record->name];
            }
        }

        $missing = $existing->keys()
            ->diff(array_keys($rows))
            ->sortBy(fn (string $code) => CampusMap::codeNumber($code))
            ->values()
            ->all();

        return [
            'imported' => $imported,
            'updated' => $updated,
            'missing' => $missing,
            'items' => [
                'added' => $added,
                'updated' => $changed,
                'missing' => array_map(fn (string $code) => ['code' => $code, 'name' => (string) $existing->get($code)->name], $missing),
            ],
        ];
    }

    /**
     * @param  list<array{code: string, properties: array<string, mixed>, coordinates: array<int, mixed>}>  $features
     * @param  array{buildings: array<string, string>, paths: array<string, string>}|null  $landmarks
     * @return array<string, array{details: array<string, mixed>, shape: array<string, mixed>}>
     */
    private function buildingRows(array $features, ?array $landmarks): array
    {
        $rows = [];
        foreach ($features as $feature) {
            ['code' => $code, 'properties' => $properties] = $feature;
            $rows[$code] = [
                'details' => [
                    'name' => (string) ($properties['name'] ?? $code),
                    'type' => $this->enum(BuildingType::class, $properties['type'] ?? null, self::BUILDINGS_FILE, $code),
                    'floors' => isset($properties['floors']) ? (int) $properties['floors'] : null,
                    ...$this->landmarkName($landmarks, 'buildings', $code),
                ],
                'shape' => ['outline' => $feature['coordinates']],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{code: string, properties: array<string, mixed>, coordinates: array<int, mixed>}>  $features
     * @param  array{buildings: array<string, string>, paths: array<string, string>}|null  $landmarks
     * @return array<string, array{details: array<string, mixed>, shape: array<string, mixed>}>
     */
    private function pathRows(array $features, ?array $landmarks): array
    {
        $rows = [];
        foreach ($features as $feature) {
            ['code' => $code, 'properties' => $properties] = $feature;
            $rows[$code] = [
                'details' => [
                    'name' => (string) ($properties['name'] ?? $code),
                    'type' => $this->enum(PathType::class, $properties['type'] ?? null, self::PATHS_FILE, $code),
                    'floor' => $this->pathFloor($properties['floor'] ?? 1, $code),
                    ...$this->landmarkName($landmarks, 'paths', $code),
                ],
                'shape' => ['line' => $feature['coordinates']],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{code: string, properties: array<string, mixed>, coordinates: array<int, mixed>}>  $features
     * @param  array<string, int>  $buildingIds  by building code
     * @return array<string, array{details: array<string, mixed>, shape: array<string, mixed>}>
     */
    private function stairRows(array $features, array $buildingIds): array
    {
        $rows = [];
        foreach ($features as $feature) {
            ['code' => $code, 'properties' => $properties] = $feature;
            $center = $properties['center'] ?? null;
            if (! $this->isPosition($center)) {
                throw new InvalidArgumentException(self::STAIRS_FILE.": stair {$code} has no center point.");
            }

            $rows[$code] = [
                'details' => [
                    'name' => (string) ($properties['name'] ?? $code),
                    'building_id' => $this->buildingId($buildingIds, $properties, self::STAIRS_FILE, "stair {$code}"),
                    'floors' => (int) ($properties['floors'] ?? 1),
                ],
                'shape' => [
                    'center_longitude' => (float) $center[0],
                    'center_latitude' => (float) $center[1],
                    'outline' => $feature['coordinates'],
                ],
            ];
        }

        return $rows;
    }

    /**
     * A room's position is one of its details, not a shape: rooms are placed in the admin pages.
     *
     * @param  list<array{code: string, properties: array<string, mixed>, coordinates: array<int, mixed>}>  $features
     * @param  array<string, int>  $buildingIds  by building code
     * @return array<string, array{details: array<string, mixed>, shape: array<string, mixed>}>
     */
    private function roomRows(array $features, array $buildingIds): array
    {
        $rows = [];
        foreach ($features as $feature) {
            ['code' => $code, 'properties' => $properties, 'coordinates' => $coordinates] = $feature;
            $building = $this->buildingId($buildingIds, $properties, self::ROOMS_FILE, "room {$code}");

            // A room stacked on a spot in JOSM: only its position and building change. Its name, floor, type, door and
            // is_it are its own and stay as they are.
            if ($properties['position_only'] ?? false) {
                $rows[$code] = [
                    'details' => ['building_id' => $building, 'longitude' => (float) $coordinates[0], 'latitude' => (float) $coordinates[1]],
                    'shape' => [],
                ];

                continue;
            }

            $rows[$code] = [
                'details' => [
                    'name' => (string) ($properties['name'] ?? $code),
                    'building_id' => $building,
                    'floor' => (int) ($properties['floor'] ?? 1),
                    'type' => $this->enum(RoomType::class, $properties['type'] ?? null, self::ROOMS_FILE, $code),
                    // A string "false" is false: (bool) would read it as true.
                    'is_it' => filter_var($properties['is_it'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'door' => isset($properties['door']) ? (string) $properties['door'] : null,
                    'longitude' => (float) $coordinates[0],
                    'latitude' => (float) $coordinates[1],
                ],
                'shape' => [],
            ];
        }

        return $rows;
    }

    /**
     * The landmark name to store for a code, or nothing when there is no landmarks file (names are then left as
     * they are).
     *
     * @param  array{buildings: array<string, string>, paths: array<string, string>}|null  $landmarks
     * @return array{landmark_name?: string|null}
     */
    private function landmarkName(?array $landmarks, string $group, string $code): array
    {
        return $landmarks === null ? [] : ['landmark_name' => $landmarks[$group][$code] ?? null];
    }

    /**
     * @param  array<string, int>  $buildingIds
     * @param  array<string, mixed>  $properties
     */
    private function buildingId(array $buildingIds, array $properties, string $file, string $what): int
    {
        $buildingCode = (string) ($properties['building_id'] ?? '');

        return $buildingIds[$buildingCode]
            ?? throw new InvalidArgumentException("{$file}: {$what} is in unknown building {$buildingCode}.");
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum
     */
    private function enum(string $enum, mixed $value, string $file, string $code): BackedEnum
    {
        return (is_string($value) ? $enum::tryFrom($value) : null)
            ?? throw new InvalidArgumentException("{$file}: {$code} has an unknown type.");
    }

    /**
     * A path's floor: a whole number from 1 (the ground floor) to the most floors a building can have.
     */
    private function pathFloor(mixed $value, string $code): int
    {
        if (! is_int($value) || $value < 1 || $value > CampusMap::MAX_FLOORS) {
            throw new InvalidArgumentException(self::PATHS_FILE.": {$code} has a floor that isn't 1 to ".CampusMap::MAX_FLOORS.'.');
        }

        return $value;
    }

    /**
     * @return list<array{code: string, properties: array<string, mixed>, coordinates: array<int, mixed>}>
     */
    private function features(array $documents, string $file, string $geometryType, bool $required = true): array
    {
        $decoded = $documents[$file] ?? null;
        if ($decoded === null) {
            if ($required) {
                throw new InvalidArgumentException("{$file} was not found.");
            }

            return [];
        }

        $list = $decoded['features'] ?? null;
        if (! is_array($list) || ! array_is_list($list)) {
            throw new InvalidArgumentException("{$file} has no list of features.");
        }

        $features = [];
        foreach ($list as $feature) {
            $properties = is_array($feature) ? ($feature['properties'] ?? null) : null;
            $code = is_array($properties) ? ($properties['id'] ?? null) : null;
            if (! is_string($code) || $code === '') {
                throw new InvalidArgumentException("{$file}: every feature needs an id.");
            }

            // The maps draw each file as one kind of shape, so anything else (a MultiPolygon export, a room drawn
            // as a line) is refused here rather than stored and found out when every map stops loading.
            $type = $feature['geometry']['type'] ?? null;
            if ($type !== $geometryType) {
                throw new InvalidArgumentException(
                    "{$file}: {$code} must be a {$geometryType}".(is_string($type) ? ", not a {$type}" : '').'.'
                );
            }

            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (! $this->isShape($geometryType, $coordinates)) {
                throw new InvalidArgumentException("{$file}: {$code} does not have valid {$geometryType} coordinates.");
            }

            $features[] = ['code' => $code, 'properties' => $properties, 'coordinates' => $coordinates];
        }

        return $features;
    }

    /**
     * Whether coordinates have the GeoJSON structure of the given geometry type.
     */
    private function isShape(string $geometryType, mixed $coordinates): bool
    {
        return match ($geometryType) {
            self::POINT => $this->isPosition($coordinates),
            self::LINE => $this->isPositionList($coordinates, 2),
            self::POLYGON => is_array($coordinates) && array_is_list($coordinates) && $coordinates !== []
                && collect($coordinates)->every(fn (mixed $ring) => $this->isPositionList($ring, 4)),
            default => false,
        };
    }

    /**
     * A [longitude, latitude] pair of numbers.
     */
    private function isPosition(mixed $value): bool
    {
        return is_array($value) && array_is_list($value) && count($value) >= 2
            && (is_int($value[0]) || is_float($value[0]))
            && (is_int($value[1]) || is_float($value[1]));
    }

    private function isPositionList(mixed $value, int $atLeast): bool
    {
        return is_array($value) && array_is_list($value) && count($value) >= $atLeast
            && collect($value)->every(fn (mixed $position) => $this->isPosition($position));
    }

    /**
     * @return array{buildings: array<string, string>, paths: array<string, string>}|null null without the file
     */
    private function landmarks(array $documents): ?array
    {
        $decoded = $documents[self::LANDMARKS_FILE] ?? null;
        if ($decoded === null) {
            return null;
        }

        return [
            'buildings' => array_filter((array) ($decoded['buildings'] ?? []), is_string(...)),
            'paths' => array_filter((array) ($decoded['paths'] ?? []), is_string(...)),
        ];
    }

    /**
     * @return array<string, mixed>|null null when the file is absent
     */
    private function readFile(string $directory, string $file): ?array
    {
        $path = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$file;

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : throw new InvalidArgumentException("{$file} is not valid JSON.");
    }
}
