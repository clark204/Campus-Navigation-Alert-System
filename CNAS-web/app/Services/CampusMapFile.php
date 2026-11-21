<?php

namespace App\Services;

use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * The whole campus map as one GeoJSON file, campus.geojson: a single FeatureCollection with every building, path,
 * room (entrance) and stair. Each feature's `kind` property says which it is, and buildings and paths carry their
 * landmark name. It is edited in another app (JOSM saves it as text, see CampusMapFeatureReader), downloaded from
 * here, and imported back. The export uses the same payload that serves the map, so the file matches what users see.
 */
class CampusMapFile
{
    /** The section of the map each kind of feature imports into. */
    private const SECTIONS = ['building' => 'buildings', 'path' => 'paths', 'room' => 'entrances', 'stair' => 'stairs'];

    /** How each kind is named in the import result. */
    private const LABELS = ['building' => 'Building', 'path' => 'Path', 'room' => 'Room', 'stair' => 'Stair'];

    /** The import result's tables, by the kind they hold (the importer's names). */
    private const TABLES = ['buildings' => 'building', 'paths' => 'path', 'rooms' => 'room', 'stairs' => 'stair'];

    public function __construct(
        private readonly CampusMap $campusMap,
        private readonly CampusMapImporter $importer,
        private readonly CampusMapFeatureReader $reader,
        private readonly CampusMapCodes $codes,
    ) {}

    /**
     * The map as GeoJSON text.
     */
    public function export(): string
    {
        $payload = $this->campusMap->payload();

        return json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                ...$this->tagged($payload['buildings'], 'building', $payload['landmarks']['buildings']),
                ...$this->tagged($payload['paths'], 'path', $payload['landmarks']['paths']),
                ...$this->tagged($payload['entrances'], 'room'),
                ...$this->tagged($payload['stairs'], 'stair'),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Imports campus.geojson text. Each feature is checked and converted (CampusMapFeatureReader), given its code
     * (CampusMapCodes), and then stored. Names on the map are kept; shapes and other details come from the file.
     * Nothing is deleted, and a file with a problem changes nothing.
     *
     * @return array{
     *     added: list<array{kind: string, name: string, code: string}>,
     *     updated: list<array{kind: string, name: string, code: string}>,
     *     matched: list<array{kind: string, name: string, code: string}>,
     *     duplicates: list<array{kind: string, name: string, code: string}>,
     *     kept: list<array{kind: string, name: string, code: string}>,
     * }
     *
     * @throws InvalidArgumentException when the text is not a GeoJSON FeatureCollection or a feature is invalid.
     */
    public function import(string $geoJson): array
    {
        $collection = json_decode($geoJson, true);
        if (! is_array($collection)) {
            throw new InvalidArgumentException('The file is not valid JSON.');
        }
        if (($collection['type'] ?? null) !== 'FeatureCollection' || ! is_array($collection['features'] ?? null)) {
            throw new InvalidArgumentException('The file is not a GeoJSON FeatureCollection.');
        }

        $entries = $this->codes->assign($this->reader->read($collection['features']));

        $sections = ['buildings' => [], 'paths' => [], 'entrances' => [], 'stairs' => []];
        $landmarks = ['buildings' => [], 'paths' => []];
        foreach ($entries as $entry) {
            foreach ($entry['codes'] as $code) {
                $sections[self::SECTIONS[$entry['kind']]][] = $this->feature($entry, $code);
                if ($entry['landmark_name'] !== null && in_array($entry['kind'], ['building', 'path'], true)) {
                    $landmarks[$entry['kind'] === 'building' ? 'buildings' : 'paths'][$code] = $entry['landmark_name'];
                }
            }
        }

        $result = $this->importer->importCampus([
            'buildings' => $this->collection($sections['buildings']),
            'paths' => $this->collection($sections['paths']),
            'entrances' => $this->collection($sections['entrances']),
            'stairs' => $this->collection($sections['stairs']),
            'landmarks' => $landmarks,
        ]);

        return $this->report($result, $entries);
    }

    /**
     * The feature the importer stores for one code of an entry.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function feature(array $entry, string $code): array
    {
        $properties = match ($entry['kind']) {
            'building' => ['id' => $code, 'name' => $entry['name'], 'type' => $entry['type'], 'floors' => $entry['floors'], 'landmark_name' => $entry['landmark_name']],
            'path' => ['id' => $code, 'name' => $entry['name'], 'type' => $entry['type'], 'floor' => $entry['floor'], 'landmark_name' => $entry['landmark_name']],
            'room' => $entry['stacked']
                ? ['id' => $code, 'building_id' => $entry['building_id'], 'position_only' => true]
                : ['id' => $code, 'name' => $entry['name'], 'building_id' => $entry['building_id'], 'floor' => $entry['floor'], 'type' => $entry['type'], 'is_it' => $entry['is_it'], 'door' => $entry['door']],
            'stair' => ['id' => $code, 'name' => $entry['name'], 'building_id' => $entry['building_id'], 'floors' => $entry['floors'], 'center' => $entry['center']],
        };

        return ['type' => 'Feature', 'properties' => $properties, 'geometry' => $entry['geometry']];
    }

    /**
     * What changed, by name. A feature matched by name is listed once, under "matched"; a flagged duplicate is listed
     * under "duplicates" rather than "added".
     *
     * @param  array<string, mixed>  $result  from CampusMapImporter::importCampus()
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, list<array{kind: string, name: string, code: string}>>
     */
    private function report(array $result, array $entries): array
    {
        $matched = [];
        $duplicates = [];
        $matchedCodes = [];
        $duplicateCodes = [];
        foreach ($entries as $entry) {
            $item = ['kind' => self::LABELS[$entry['kind']], 'name' => (string) ($entry['name'] ?? $entry['codes'][0]), 'code' => $entry['codes'][0]];
            if ($entry['matched']) {
                $matched[] = $item;
                $matchedCodes[$entry['codes'][0]] = true;
            }
            if ($entry['duplicate']) {
                $duplicates[] = $item;
                $duplicateCodes[$entry['codes'][0]] = true;
            }
        }

        $added = [];
        $updated = [];
        $kept = [];
        foreach (self::TABLES as $table => $kind) {
            foreach ($result['items'][$table]['added'] as $item) {
                if (! isset($duplicateCodes[$item['code']])) {
                    $added[] = ['kind' => self::LABELS[$kind], 'name' => $item['name'], 'code' => $item['code']];
                }
            }
            foreach ($result['items'][$table]['updated'] as $item) {
                if (! isset($matchedCodes[$item['code']])) {
                    $updated[] = ['kind' => self::LABELS[$kind], 'name' => $item['name'], 'code' => $item['code']];
                }
            }
            foreach ($result['items'][$table]['missing'] as $item) {
                $kept[] = ['kind' => self::LABELS[$kind], 'name' => $item['name'], 'code' => $item['code']];
            }
        }

        return ['added' => $added, 'updated' => $updated, 'matched' => $matched, 'duplicates' => $duplicates, 'kept' => $kept];
    }

    /**
     * The features of one payload collection, each with its kind (and landmark name, when the kind has one).
     *
     * @param  array{features: list<array<string, mixed>>}  $collection
     * @param  array<string, string>|null  $landmarks  by code, for the kinds that have landmark names
     * @return list<array<string, mixed>>
     */
    private function tagged(array $collection, string $kind, ?array $landmarks = null): array
    {
        return array_map(function (array $feature) use ($kind, $landmarks): array {
            // Photos are not part of the file: they are managed on the building and room pages.
            $properties = ['kind' => $kind] + Arr::except($feature['properties'], ['photos']);
            if ($landmarks !== null) {
                $properties['landmark_name'] = $landmarks[$feature['properties']['id']] ?? null;
            }

            return ['type' => 'Feature', 'properties' => $properties, 'geometry' => $feature['geometry']];
        }, $collection['features']);
    }

    /**
     * @param  list<mixed>  $features
     * @return array{type: string, features: list<mixed>}
     */
    private function collection(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }
}
