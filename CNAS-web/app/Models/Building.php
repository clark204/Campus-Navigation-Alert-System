<?php

namespace App\Models;

use App\Enums\BuildingType;
use App\Services\CampusMap;
use Database\Factories\BuildingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * A shape on the campus map: the campus outline, a building, a facility or the evacuation area. Found by `code`
 * ('b1'), which alerts and the map API use as its id. Outlines arrive through the map import, not the admin pages.
 *
 * @property string $code
 * @property string $name
 * @property BuildingType $type
 * @property int|null $floors
 * @property string|null $landmark_name Friendly name used in walking directions ("the Library"); null = not mentioned.
 * @property list<list<array{float, float}>> $outline GeoJSON polygon coordinates.
 */
#[Fillable(['code', 'name', 'type', 'floors', 'landmark_name', 'outline'])]
class Building extends Model
{
    /** @use HasFactory<BuildingFactory> */
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
            'type' => BuildingType::class,
            'floors' => 'integer',
            'outline' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * The name students use: its landmark name without "the" ("the Library" → "Library"), else its name.
     */
    public function knownName(): string
    {
        $landmark = preg_replace('/^the\s+/i', '', (string) $this->landmark_name);

        return $landmark !== '' ? Str::ucfirst($landmark) : $this->name;
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * @return HasMany<Stair, $this>
     */
    public function stairs(): HasMany
    {
        return $this->hasMany(Stair::class);
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
