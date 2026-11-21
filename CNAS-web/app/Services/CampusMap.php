<?php

namespace App\Services;

use App\Enums\BuildingType;
use App\Models\Building;
use App\Models\Panorama;
use App\Models\Path;
use App\Models\PlacePhoto;
use App\Models\Room;
use App\Models\Stair;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The campus map as the rest of the app reads it: the GeoJSON payload every map draws (GET /api/map), the list of
 * buildings an alert can name, and the area an incident pin or a room's door must fall inside. The map tables
 * (buildings, paths, stairs, rooms, panoramas) are the source; the payload is cached until one of them changes.
 */
class CampusMap
{
    /** The most floors a building, path or 360° photo can be on. */
    public const MAX_FLOORS = 10;

    /** Kinds of shape an admin can mark as affected (not the campus outline or the evacuation area). */
    private const AFFECTED_TYPES = [BuildingType::Building->value, BuildingType::Facility->value];

    /** How far outside the campus outline (in degrees, about 100 m) an incident pin may still be dropped. */
    private const PIN_MARGIN_DEGREES = 0.001;

    /** Mean earth radius in meters, for distances on campus. */
    private const EARTH_RADIUS_METERS = 6_371_000;

    /** Cache key of the map payload served by GET /api/map. */
    private const PAYLOAD_CACHE_KEY = 'campus_map.payload';

    /**
     * Called whenever a building, path, stair, room or 360° photo changes (and after a map import), so the next request
     * rebuilds the map payload.
     */
    public static function forgetPayload(): void
    {
        Cache::forget(self::PAYLOAD_CACHE_KEY);
    }

    /**
     * The number in a map code ('b12' → 12), for listing features in map order rather than as text ('b10' < 'b2').
     */
    public static function codeNumber(string $code): int
    {
        return (int) preg_replace('/\D+/', '', $code);
    }

    /**
     * The whole map in the shape the map scripts consume: five GeoJSON feature collections (`entrances` are the
     * rooms; `panoramas` are the 360° photos shown to users, with root-relative image links) plus the friendly names
     * used in walking directions. Feature ids are the codes ('b1', 'r5', 'pn3').
     *
     * @return array{
     *     buildings: array{type: string, features: list<array<string, mixed>>},
     *     paths: array{type: string, features: list<array<string, mixed>>},
     *     entrances: array{type: string, features: list<array<string, mixed>>},
     *     stairs: array{type: string, features: list<array<string, mixed>>},
     *     panoramas: array{type: string, features: list<array<string, mixed>>},
     *     landmarks: array{buildings: array<string, string>, paths: array<string, string>},
     * }
     */
    public function payload(): array
    {
        return Cache::rememberForever(self::PAYLOAD_CACHE_KEY, fn (): array => $this->buildPayload());
    }

    /**
     * Buildings and facilities by code, in map order: ['b1' => 'NEE Building', ...].
     *
     * @return array<string, string>
     */
    public function buildings(): array
    {
        return collect($this->payload()['buildings']['features'])
            ->whereIn('properties.type', self::AFFECTED_TYPES)
            ->mapWithKeys(fn (array $feature) => [$feature['properties']['id'] => $feature['properties']['name']])
            ->all();
    }

    public function buildingName(string $code): ?string
    {
        return $this->buildings()[$code] ?? null;
    }

    /**
     * Whether a point is on (or just outside) the campus. False while the map has no campus outline.
     */
    public function isNearCampus(float $latitude, float $longitude): bool
    {
        $ring = $this->campusRing();
        if ($ring === []) {
            return false;
        }

        $longitudes = array_column($ring, 0);
        $latitudes = array_column($ring, 1);

        return $longitude >= min($longitudes) - self::PIN_MARGIN_DEGREES
            && $longitude <= max($longitudes) + self::PIN_MARGIN_DEGREES
            && $latitude >= min($latitudes) - self::PIN_MARGIN_DEGREES
            && $latitude <= max($latitudes) + self::PIN_MARGIN_DEGREES;
    }

    /**
     * The middle of the campus outline, or null while the map has none.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function campusCenter(): ?array
    {
        $ring = $this->campusRing();
        if ($ring === []) {
            return null;
        }
        $longitudes = array_column($ring, 0);
        $latitudes = array_column($ring, 1);

        return [
            'latitude' => round((min($latitudes) + max($latitudes)) / 2, 8),
            'longitude' => round((min($longitudes) + max($longitudes)) / 2, 8),
        ];
    }

    /**
     * How far a point is from the nearest walkway, gate or corridor on its floor, in meters; null when that floor has
     * none.
     */
    public function metersToNearestPath(float $latitude, float $longitude, int $floor): ?float
    {
        $nearest = null;
        foreach ($this->payload()['paths']['features'] as $feature) {
            if (($feature['properties']['floor'] ?? 1) !== $floor) {
                continue;
            }
            $points = array_map(fn (array $coordinate) => $this->toMeters($coordinate, $latitude, $longitude), $feature['geometry']['coordinates']);
            for ($i = 1; $i < count($points); $i++) {
                $distance = $this->distanceToSegment($points[$i - 1], $points[$i]);
                $nearest = $nearest === null ? $distance : min($nearest, $distance);
            }
        }

        return $nearest;
    }

    /**
     * A [lng, lat] point in meters east and north of the origin (flat-earth: fine across a campus).
     *
     * @param  array<int, float|int>  $coordinate
     * @return array{float, float}
     */
    private function toMeters(array $coordinate, float $originLatitude, float $originLongitude): array
    {
        $radians = M_PI / 180;

        return [
            ($coordinate[0] - $originLongitude) * $radians * self::EARTH_RADIUS_METERS * cos($originLatitude * $radians),
            ($coordinate[1] - $originLatitude) * $radians * self::EARTH_RADIUS_METERS,
        ];
    }

    /**
     * Distance from the origin to the segment a–b.
     *
     * @param  array{float, float}  $a
     * @param  array{float, float}  $b
     */
    private function distanceToSegment(array $a, array $b): float
    {
        [$dx, $dy] = [$b[0] - $a[0], $b[1] - $a[1]];
        $lengthSquared = $dx * $dx + $dy * $dy;
        $t = $lengthSquared > 0 ? max(0, min(1, -($a[0] * $dx + $a[1] * $dy) / $lengthSquared)) : 0;

        return hypot($a[0] + $t * $dx, $a[1] + $t * $dy);
    }

    /**
     * @return list<array<int, float|int>>
     */
    private function campusRing(): array
    {
        $campus = collect($this->payload()['buildings']['features'])
            ->firstWhere('properties.type', BuildingType::Campus->value);

        return $campus['geometry']['coordinates'][0] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $buildings = $this->inCodeOrder(Building::query()->with('photos')->get());
        $paths = $this->inCodeOrder(Path::query()->get());
        $buildingCodes = $buildings->pluck('code', 'id');

        return [
            'buildings' => $this->collection($buildings->map(fn (Building $building) => $this->feature(
                [
                    'id' => $building->code,
                    'name' => $building->name,
                    'type' => $building->type->value,
                    'floors' => $building->floors,
                    'photos' => $this->photos($building),
                ],
                'Polygon',
                $building->outline,
            ))),
            'paths' => $this->collection($paths->map(fn (Path $path) => $this->feature(
                ['id' => $path->code, 'name' => $path->name, 'type' => $path->type->value, 'floor' => $path->floor],
                'LineString',
                $path->line,
            ))),
            'entrances' => $this->collection($this->inCodeOrder(Room::query()->with('photos')->get())->map(fn (Room $room) => $this->feature(
                [
                    'id' => $room->code,
                    'name' => $room->name,
                    'building_id' => $buildingCodes[$room->building_id],
                    'floor' => $room->floor,
                    'type' => $room->type->value,
                    'is_it' => $room->is_it,
                    'door' => $room->door,
                    'photos' => $this->photos($room),
                ],
                'Point',
                [$room->longitude, $room->latitude],
            ))),
            'stairs' => $this->collection($this->inCodeOrder(Stair::query()->get())->map(fn (Stair $stair) => $this->feature(
                [
                    'id' => $stair->code,
                    'name' => $stair->name,
                    'building_id' => $buildingCodes[$stair->building_id],
                    'floors' => $stair->floors,
                    'center' => [$stair->center_longitude, $stair->center_latitude],
                ],
                'Polygon',
                $stair->outline,
            ))),
            'panoramas' => $this->collection($this->inCodeOrder(Panorama::query()->visible()->get())->map(fn (Panorama $panorama) => $this->feature(
                [
                    'id' => $panorama->code,
                    'name' => $panorama->name,
                    'floor' => $panorama->floor,
                    'north_offset' => $panorama->north_offset,
                    'image' => $panorama->imageUrl(),
                    'thumb' => $panorama->thumbUrl(),
                ],
                'Point',
                [$panorama->longitude, $panorama->latitude],
            ))),
            'landmarks' => [
                'buildings' => $buildings->whereNotNull('landmark_name')->pluck('landmark_name', 'code')->all(),
                'paths' => $paths->whereNotNull('landmark_name')->pluck('landmark_name', 'code')->all(),
            ],
        ];
    }

    /**
     * A place's photos in order, for the map: the first is the cover.
     *
     * @return list<array{kind: string, image: string, thumb: string, width: int, height: int}>
     */
    private function photos(Building|Room $place): array
    {
        return $place->photos
            ->map(fn (PlacePhoto $photo) => [
                'kind' => $photo->kind->value,
                'image' => $photo->imageUrl(),
                'thumb' => $photo->thumbUrl(),
                'width' => $photo->width,
                'height' => $photo->height,
            ])
            ->values()
            ->all();
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, TModel>  $models
     * @return Collection<int, TModel>
     */
    private function inCodeOrder(Collection $models): Collection
    {
        return $models->sortBy(fn (Model $model) => self::codeNumber($model->code))->values();
    }

    /**
     * A GeoJSON feature. Properties without a value (a campus outline's floors, a room without a door label) are
     * left out, as they are in the files the map was first drawn from.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<int, mixed>  $coordinates
     * @return array{type: string, properties: array<string, mixed>, geometry: array{type: string, coordinates: array<int, mixed>}}
     */
    private function feature(array $properties, string $geometryType, array $coordinates): array
    {
        return [
            'type' => 'Feature',
            'properties' => array_filter($properties, fn (mixed $value) => $value !== null),
            'geometry' => ['type' => $geometryType, 'coordinates' => $coordinates],
        ];
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $features
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    private function collection(iterable $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => collect($features)->values()->all()];
    }
}
