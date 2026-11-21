<?php

namespace App\Models;

use App\Enums\PathType;
use App\Services\CampusMap;
use Database\Factories\PathFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A walkway, gate or upper-floor corridor people can walk along. Found by `code` ('p3'). Ground-floor lines arrive
 * through the map import; corridors on floor 2 and up are copied from them in the admin pages and redrawn there.
 *
 * @property string $code
 * @property string $name
 * @property PathType $type
 * @property int $floor 1 = ground floor.
 * @property string|null $landmark_name Friendly name used in walking directions ("the main walkway").
 * @property list<array{float, float}> $line GeoJSON line coordinates.
 */
#[Fillable(['code', 'name', 'type', 'floor', 'landmark_name', 'line'])]
class Path extends Model
{
    /** @use HasFactory<PathFactory> */
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
            'type' => PathType::class,
            'floor' => 'integer',
            'line' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
