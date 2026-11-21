<?php

namespace App\Models;

use App\Services\CampusMap;
use Database\Factories\PanoramaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * A 360° photo point on the map ("street view"). Found by `code` ('pn3'). The photo is a 4096×2048 web copy plus a
 * small thumbnail on the public disk, made by PanoramaImages; the original is never kept.
 *
 * @property string $code
 * @property string $name
 * @property float $latitude
 * @property float $longitude
 * @property int $floor 1 = ground floor.
 * @property float|null $north_offset The direction in the photo that faces north, in degrees clockwise from the photo's centre column. Null until set.
 * @property bool $is_visible Shown to users (GET /api/map). Only possible once north is set.
 * @property string $image_path Path on the public disk.
 * @property string $thumb_path Path on the public disk.
 */
#[Fillable(['code', 'name', 'latitude', 'longitude', 'floor', 'north_offset', 'is_visible', 'image_path', 'thumb_path'])]
class Panorama extends Model
{
    /** @use HasFactory<PanoramaFactory> */
    use HasFactory;

    public const CODE_PREFIX = 'pn';

    /** Files on this disk are served at /storage/…. */
    public const DISK = 'public';

    protected static function booted(): void
    {
        // A photo without north would show its arrows pointing the wrong way, so it is never shown.
        static::saving(function (Panorama $panorama): void {
            if ($panorama->north_offset === null) {
                $panorama->is_visible = false;
            }
        });
        static::saved(fn () => CampusMap::forgetPayload());
        static::deleted(function (Panorama $panorama): void {
            Storage::disk(self::DISK)->delete([$panorama->image_path, $panorama->thumb_path]);
            CampusMap::forgetPayload();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'floor' => 'integer',
            'north_offset' => 'float',
            'is_visible' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @param  Builder<Panorama>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true)->whereNotNull('north_offset');
    }

    /**
     * The code a new photo gets: one past the highest number in use ('pn10' → 'pn11').
     */
    public static function nextCode(): string
    {
        $highest = static::query()->pluck('code')
            ->map(fn (string $code) => (int) substr($code, strlen(self::CODE_PREFIX)))
            ->max();

        return self::CODE_PREFIX.($highest === null ? 1 : $highest + 1);
    }

    public function hasNorth(): bool
    {
        return $this->north_offset !== null;
    }

    public function imageUrl(): string
    {
        return self::url($this->image_path);
    }

    public function thumbUrl(): string
    {
        return self::url($this->thumb_path);
    }

    /**
     * A link without the host ('/storage/panoramas/…'): APP_URL is localhost, which a phone on the tunnel can't reach.
     */
    private static function url(string $path): string
    {
        return (string) parse_url(Storage::disk(self::DISK)->url($path), PHP_URL_PATH);
    }
}
