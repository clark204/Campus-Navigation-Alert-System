<?php

namespace App\Models;

use App\Enums\PhotoKind;
use App\Services\CampusMap;
use App\Services\PhotoCopies;
use Database\Factories\PlacePhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * A photo of a building or room ("place"). The image and thumbnail are copies on the public disk; the original is
 * never kept. The first photo by position is the cover.
 *
 * @property PhotoKind $kind
 * @property string $image_path Path on the public disk.
 * @property string $thumb_path Path on the public disk.
 * @property int $width Width of the image copy in pixels.
 * @property int $height Height of the image copy in pixels.
 * @property int $position
 */
#[Fillable(['kind', 'image_path', 'thumb_path', 'width', 'height', 'position'])]
class PlacePhoto extends Model
{
    /** @use HasFactory<PlacePhotoFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saved(fn () => CampusMap::forgetPayload());
        static::deleted(function (PlacePhoto $photo): void {
            Storage::disk(PhotoCopies::DISK)->delete([$photo->image_path, $photo->thumb_path]);
            CampusMap::forgetPayload();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PhotoKind::class,
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function place(): MorphTo
    {
        return $this->morphTo();
    }

    public function imageUrl(): string
    {
        return self::url($this->image_path);
    }

    /**
     * The still shown when the photo is hovered: the web copy of a normal photo, the thumbnail of a 360° photo
     * (the same as the 360° photos list).
     */
    public function previewUrl(): string
    {
        return $this->kind === PhotoKind::Panorama ? $this->thumbUrl() : $this->imageUrl();
    }

    public function thumbUrl(): string
    {
        return self::url($this->thumb_path);
    }

    /**
     * A link without the host ('/storage/place-photos/…'), like the 360° photos: APP_URL may not be reachable from a phone.
     */
    private static function url(string $path): string
    {
        return (string) parse_url(Storage::disk(PhotoCopies::DISK)->url($path), PHP_URL_PATH);
    }
}
