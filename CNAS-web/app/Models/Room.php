<?php

namespace App\Models;

use App\Enums\RoomType;
use App\Services\CampusMap;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * A room people can get directions to. Found by `code` ('r5'), the id in /navigate?to=r5. Latitude and longitude
 * are its door.
 *
 * @property string $code
 * @property string $name
 * @property int $building_id
 * @property int $floor
 * @property RoomType $type
 * @property bool $is_it IT college room.
 * @property string|null $door Label of the door, when it differs from the room name.
 * @property float $latitude
 * @property float $longitude
 */
#[Fillable(['code', 'name', 'building_id', 'floor', 'type', 'is_it', 'door', 'latitude', 'longitude'])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => CampusMap::forgetPayload());
        static::deleted(fn () => CampusMap::forgetPayload());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'floor' => 'integer',
            'type' => RoomType::class,
            'is_it' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * Rooms a class could meet in: every room except comfort rooms (the class room list on My schedule / My classes).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeClassRooms(Builder $query): Builder
    {
        return $query->where('type', '!=', RoomType::Cr);
    }

    /**
     * Every room a class could meet in (not comfort rooms) grouped by building, for the class form's room list (web
     * and app). Natural order, so ComLab 2 comes before ComLab 10.
     *
     * @return Collection<string, Collection<int, self>>
     */
    public static function classRoomsByBuilding(): Collection
    {
        return self::query()->classRooms()->with('building')->get()
            ->sortBy(fn (self $room) => $room->buildingLabel().' '.$room->floor.' '.$room->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->groupBy(fn (self $room) => $room->buildingLabel());
    }

    /**
     * Where the room is, in the words students use: "NEE Building · 2nd floor" (a landmark name such as "the Library"
     * reads "Library"). Needs `building` loaded.
     */
    public function locationLabel(): string
    {
        $building = $this->buildingLabel();

        return $this->floor > 1 ? "{$building} · ".Number::ordinal($this->floor).' floor' : $building;
    }

    /**
     * The room's building in the words students use ("NEE Building", "Library"). Needs `building` loaded.
     */
    public function buildingLabel(): string
    {
        return $this->building->knownName();
    }

    /**
     * The room as a choice in a list grouped by building: "NEE 201 · 2nd floor", or just the name on the ground floor.
     */
    public function choiceLabel(): string
    {
        return $this->floor > 1 ? "{$this->name} · ".Number::ordinal($this->floor).' floor' : $this->name;
    }

    /**
     * The photos of this place in order: the first is the cover.
     *
     * @return MorphMany<PlacePhoto, $this>
     */
    public function photos(): MorphMany
    {
        return $this->morphMany(PlacePhoto::class, 'place')->orderBy('position')->orderBy('id');
    }
}
