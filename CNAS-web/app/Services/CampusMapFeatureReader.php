<?php

namespace App\Services;

use App\Enums\BuildingType;
use App\Enums\PathType;
use App\Enums\RoomType;
use BackedEnum;
use InvalidArgumentException;

/**
 * Reads the features of a campus.geojson the way JOSM saves them, and checks each one before anything is stored:
 *
 * - a closed building or stair outline may come back as a LineString (its first and last points are the same);
 * - every property is text ("3", "false", "[124.74,8.54]"), so each value is converted to a number, a boolean or a list;
 * - rooms stacked on one spot are one feature whose codes are joined by ";". The order differs between the joined
 *   fields, so a stacked feature keeps only its position and building. Each room keeps its own other details.
 *
 * A problem is reported with the feature's number and name, so it can be found in JOSM.
 */
class CampusMapFeatureReader
{
    /** The shape each kind of feature must be drawn as. A closed building or stair may also come as a LineString. */
    private const GEOMETRY = ['building' => 'Polygon', 'stair' => 'Polygon', 'path' => 'LineString', 'room' => 'Point'];

    /** The letter every code of a kind starts with: b1, p2, r3, s4. */
    private const CODE_LETTER = ['building' => 'b', 'path' => 'p', 'room' => 'r', 'stair' => 's'];

    private const POSITION_PATTERN = '/^\[\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*\]$/';

    /**
     * @param  array<int, mixed>  $features
     * @return list<array<string, mixed>> each feature checked and converted: its kind, its codes (none when it has
     *                                    none yet), its name, its shape and its typed values.
     *
     * @throws InvalidArgumentException naming the feature when something is not valid.
     */
    public function read(array $features): array
    {
        $entries = [];
        $owners = [];
        foreach ($features as $index => $feature) {
            $entry = $this->entry($index + 1, $feature);
            foreach ($entry['ids'] as $id) {
                if (isset($owners[$id])) {
                    throw new InvalidArgumentException("{$entry['label']} has the code {$id}, which {$owners[$id]} already has.");
                }
                $owners[$id] = $entry['label'];
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(int $number, mixed $feature): array
    {
        $properties = is_array($feature) ? ($feature['properties'] ?? null) : null;
        $geometry = is_array($feature) ? ($feature['geometry'] ?? null) : null;
        if (! is_array($properties) || ! is_array($geometry)) {
            throw new InvalidArgumentException("Feature {$number} is not a valid feature: it needs properties and a geometry.");
        }

        $name = $this->text($properties['name'] ?? null);
        $label = $name === null ? "Feature {$number}" : "Feature {$number} “{$name}”";

        $kind = $properties['kind'] ?? null;
        if (! is_string($kind) || ! array_key_exists($kind, self::GEOMETRY)) {
            throw new InvalidArgumentException("{$label} needs a kind: building, path, room or stair.");
        }

        $ids = $this->ids($label, $kind, $properties['id'] ?? null);
        $stacked = count($ids) > 1;

        return [
            'label' => $label,
            'kind' => $kind,
            'ids' => $ids,
            'stacked' => $stacked,
            'name' => $name,
            'geometry' => $this->shape($label, $kind, $geometry),
            ...$this->values($label, $kind, $properties, $stacked),
        ];
    }

    /**
     * The typed values a kind uses. A stacked room only needs its building (its position comes from the shape).
     *
     * @param  array<string, mixed>  $properties
     * @return array{floor: ?int, floors: ?int, type: ?string, is_it: ?bool, door: ?string, building_id: ?string, center: ?array{float, float}, landmark_name: ?string}
     */
    private function values(string $label, string $kind, array $properties, bool $stacked): array
    {
        $values = [
            'floor' => null, 'floors' => null, 'type' => null, 'is_it' => null,
            'door' => null, 'building_id' => null, 'center' => null,
            'landmark_name' => $this->text($properties['landmark_name'] ?? null),
        ];

        switch ($kind) {
            case 'building':
                $values['floors'] = $this->whole($label, 'floors', $properties['floors'] ?? null, null);
                $values['type'] = $this->choice($label, 'type', $properties['type'] ?? null, BuildingType::class);
                break;
            case 'path':
                $values['floor'] = $this->whole($label, 'floor', $properties['floor'] ?? null, 1);
                $values['type'] = $this->choice($label, 'type', $properties['type'] ?? null, PathType::class);
                break;
            case 'room':
                if ($stacked) {
                    $values['building_id'] = $this->stackedBuilding($label, $properties['building_id'] ?? null);
                    break;
                }
                $values['floor'] = $this->whole($label, 'floor', $properties['floor'] ?? null, 1);
                $values['type'] = $this->choice($label, 'type', $properties['type'] ?? null, RoomType::class);
                $values['is_it'] = $this->flag($label, 'is_it', $properties['is_it'] ?? null);
                $values['door'] = $this->text($properties['door'] ?? null);
                $values['building_id'] = $this->buildingCode($label, $properties['building_id'] ?? null);
                break;
            case 'stair':
                $values['floors'] = $this->whole($label, 'floors', $properties['floors'] ?? null, 1);
                $values['center'] = $this->center($label, $properties['center'] ?? null);
                $values['building_id'] = $this->buildingCode($label, $properties['building_id'] ?? null);
                break;
        }

        return $values;
    }

    /**
     * The codes a feature carries: none, one, or several for rooms stacked on one spot ("r16;r17;r18").
     *
     * @return list<string>
     */
    private function ids(string $label, string $kind, mixed $value): array
    {
        $text = $this->text($value);
        if ($text === null) {
            return [];
        }

        $letter = self::CODE_LETTER[$kind];
        $ids = array_map('trim', explode(';', $text));
        foreach ($ids as $id) {
            if (! preg_match('/^'.$letter.'\d+$/', $id)) {
                throw new InvalidArgumentException("{$label}: “{$id}” is not a {$kind} code (it looks like {$letter}1).");
            }
        }
        if (count($ids) > 1 && $kind !== 'room') {
            throw new InvalidArgumentException("{$label} has {$text}: only rooms can share one spot.");
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException("{$label} lists the same code twice: {$text}.");
        }

        return $ids;
    }

    /**
     * The shape as GeoJSON: a closed building or stair drawn as a LineString becomes a Polygon.
     *
     * @param  array<string, mixed>  $geometry
     * @return array{type: string, coordinates: array<int, mixed>}
     */
    private function shape(string $label, string $kind, array $geometry): array
    {
        $type = $geometry['type'] ?? null;
        $coordinates = $geometry['coordinates'] ?? null;
        $expected = self::GEOMETRY[$kind];

        if ($expected === 'Polygon' && $type === 'LineString') {
            $positions = $this->positions($label, $coordinates, 2);
            $closed = count($positions) >= 4 && $this->same($positions[0], $positions[array_key_last($positions)]);
            if (! $closed) {
                throw new InvalidArgumentException("{$label} is not a closed shape: join its last point to its first point in JOSM (it needs at least 3 corners).");
            }

            return ['type' => 'Polygon', 'coordinates' => [$positions]];
        }

        if ($type !== $expected) {
            throw new InvalidArgumentException("{$label} must be a {$expected}".(is_string($type) ? ", not a {$type}" : '').'.');
        }

        return match ($expected) {
            'Point' => ['type' => 'Point', 'coordinates' => $this->position($label, $coordinates)],
            'LineString' => ['type' => 'LineString', 'coordinates' => $this->positions($label, $coordinates, 2)],
            default => ['type' => 'Polygon', 'coordinates' => $this->rings($label, $coordinates)],
        };
    }

    /**
     * @return list<list<array{float, float}>>
     */
    private function rings(string $label, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidArgumentException("{$label} has no outline.");
        }

        return array_map(fn (mixed $ring) => $this->positions($label, $ring, 4), $value);
    }

    /**
     * @return list<array{float, float}>
     */
    private function positions(string $label, mixed $value, int $atLeast): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $atLeast) {
            throw new InvalidArgumentException("{$label} needs at least {$atLeast} points.");
        }

        return array_map(fn (mixed $position) => $this->position($label, $position), $value);
    }

    /**
     * A [longitude, latitude] pair. JOSM writes numbers, but a number saved as text is accepted too.
     *
     * @return array{float, float}
     */
    private function position(string $label, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 2
            || ! $this->isNumber($value[0]) || ! $this->isNumber($value[1])) {
            throw new InvalidArgumentException("{$label} has a point that is not a longitude and latitude.");
        }

        return [(float) $value[0], (float) $value[1]];
    }

    /**
     * @return array{float, float}
     */
    private function center(string $label, mixed $value): array
    {
        if (is_array($value)) {
            return $this->position($label, $value);
        }

        $text = $this->text($value);
        if ($text === null) {
            throw new InvalidArgumentException("{$label} has no center point (center).");
        }
        if (! preg_match(self::POSITION_PATTERN, $text, $match)) {
            throw new InvalidArgumentException("{$label}: center must look like [longitude,latitude] (it is “{$text}”).");
        }

        return [(float) $match[1], (float) $match[2]];
    }

    /**
     * A whole number from 1 to the most floors a building can have. Empty means the default.
     */
    private function whole(string $label, string $field, mixed $value, ?int $default): ?int
    {
        $text = $this->text($value);
        if ($text === null) {
            return $default;
        }
        if (! ctype_digit($text) || (int) $text < 1 || (int) $text > CampusMap::MAX_FLOORS) {
            throw new InvalidArgumentException("{$label}: {$field} must be a whole number from 1 to ".CampusMap::MAX_FLOORS." (it is “{$text}”).");
        }

        return (int) $text;
    }

    /**
     * One of an enum's values. Required.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    private function choice(string $label, string $field, mixed $value, string $enum): string
    {
        $text = $this->text($value);
        $allowed = implode(', ', array_column($enum::cases(), 'value'));
        if ($text === null) {
            throw new InvalidArgumentException("{$label}: {$field} is missing. It must be one of {$allowed}.");
        }
        if ($enum::tryFrom($text) === null) {
            throw new InvalidArgumentException("{$label}: {$field} “{$text}” is not one of {$allowed}.");
        }

        return $text;
    }

    /**
     * "true" or "false" (any capitals). Empty means false. Anything else is refused.
     */
    private function flag(string $label, string $field, mixed $value): bool
    {
        $text = $this->text($value);
        if ($text === null) {
            return false;
        }

        return match (strtolower($text)) {
            'true' => true,
            'false' => false,
            default => throw new InvalidArgumentException("{$label}: {$field} must be true or false (it is “{$text}”)."),
        };
    }

    private function buildingCode(string $label, mixed $value): string
    {
        $text = $this->text($value);
        if ($text === null) {
            throw new InvalidArgumentException("{$label} has no building (building_id).");
        }

        return $text;
    }

    /**
     * The one building that stacked rooms are all in. Their building values are joined, so they must all be the same.
     */
    private function stackedBuilding(string $label, mixed $value): string
    {
        $text = $this->buildingCode($label, $value);
        $buildings = array_unique(array_map('trim', explode(';', $text)));
        if (count($buildings) !== 1) {
            throw new InvalidArgumentException("{$label}: stacked rooms must all be in one building (building_id is “{$text}”).");
        }

        return $buildings[0];
    }

    /**
     * A property as trimmed text, or null when it is empty or missing. Booleans read as "true" or "false".
     */
    private function text(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value));
    }

    /**
     * @param  array{float, float}  $a
     * @param  array{float, float}  $b
     */
    private function same(array $a, array $b): bool
    {
        return abs($a[0] - $b[0]) < 1e-9 && abs($a[1] - $b[1]) < 1e-9;
    }
}
