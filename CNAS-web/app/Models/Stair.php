<?php

namespace App\Models;

use App\Services\CampusMap;
use Database\Factories\StairFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staircase inside a building. Found by `code` ('s0'). `floors` is the highest floor it reaches.
 *
 * @property string $code
 * @property string $name
 * @property int $building_id
 * @property int $floors
 * @property float $center_latitude
 * @property float $center_longitude
 * @property list<list<array{float, float}>> $outline GeoJSON polygon coordinates.
 */
#[Fillable(['code', 'name', 'building_id', 'floors', 'center_latitude', 'center_longitude', 'outline'])]
class Stair extends Model
{
    /** @use HasFactory<StairFactory> */
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
            'floors' => 'integer',
            'center_latitude' => 'float',
            'center_longitude' => 'float',
            'outline' => 'array',
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
}
