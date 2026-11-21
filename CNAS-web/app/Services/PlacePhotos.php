<?php

namespace App\Services;

use App\Enums\PhotoKind;
use App\Models\Building;
use App\Models\PlacePhoto;
use App\Models\Room;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Photos of buildings and rooms. A normal photo is turned upright from its EXIF orientation, then copied with its
 * longer side at most 1600 px (thumbnail 480 px). A 360° photo gets the same copies as the walkway 360° photos
 * (PanoramaImages' sizes). Each place keeps its photos in order; the first is the cover.
 */
class PlacePhotos
{
    /** Most photos a building or room can have. */
    public const MAX_PER_PLACE = 6;

    private const FOLDER = 'place-photos';

    private const PHOTO_WEB_SIDE = 1600;

    private const PHOTO_THUMB_SIDE = 480;

    public function __construct(private readonly PhotoCopies $copies) {}

    /**
     * Adds a photo to the end of the place's photos (so it is the cover only when there are none yet).
     *
     * @throws RuntimeException when the photo can't be read.
     */
    public function add(Building|Room $place, PhotoKind $kind, string $file): PlacePhoto
    {
        $picture = $this->copies->decode($file);
        $name = Str::slug(class_basename($place).'-'.$place->code);

        $copies = $kind === PhotoKind::Photo
            ? $this->copies->save($this->uprighted($picture, $file), self::FOLDER, $name, self::PHOTO_WEB_SIDE, self::PHOTO_THUMB_SIDE)
            : $this->copies->save($picture, self::FOLDER, $name, PanoramaImages::WEB_WIDTH, PanoramaImages::THUMB_WIDTH);

        try {
            return DB::transaction(fn () => $place->photos()->create([
                'kind' => $kind,
                ...$copies,
                'position' => ($place->photos()->max('position') ?? -1) + 1,
            ]));
        } catch (Throwable $exception) {
            Storage::disk(PhotoCopies::DISK)->delete([$copies['image_path'], $copies['thumb_path']]);
            throw $exception;
        }
    }

    /**
     * Swaps a photo with its neighbour: 'up' moves it earlier, 'down' later. Nothing happens at either end.
     */
    public function move(Building|Room $place, PlacePhoto $photo, string $direction): void
    {
        DB::transaction(function () use ($place, $photo, $direction): void {
            $photos = $place->photos()->get()->all();
            $index = array_search($photo->id, array_column($photos, 'id'), true);
            $target = $direction === 'up' ? $index - 1 : $index + 1;

            if ($index === false || ! isset($photos[$target])) {
                return;
            }

            [$photos[$index], $photos[$target]] = [$photos[$target], $photos[$index]];
            foreach ($photos as $position => $ordered) {
                if ($ordered->position !== $position) {
                    $ordered->update(['position' => $position]);
                }
            }
        });
    }

    /**
     * Turns a photo upright from its EXIF orientation: a phone photo is often stored sideways with a tag saying how to
     * show it. Pictures without the tag (PNG, WebP) are returned as they are.
     */
    private function uprighted(GdImage $picture, string $file): GdImage
    {
        $exif = @exif_read_data($file);
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return match ($orientation) {
            2 => $this->flipped($picture, IMG_FLIP_HORIZONTAL),
            3 => $this->rotated($picture, 180),
            4 => $this->flipped($picture, IMG_FLIP_VERTICAL),
            5 => $this->rotated($this->flipped($picture, IMG_FLIP_HORIZONTAL), 90),
            6 => $this->rotated($picture, -90),
            7 => $this->rotated($this->flipped($picture, IMG_FLIP_HORIZONTAL), -90),
            8 => $this->rotated($picture, 90),
            default => $picture,
        };
    }

    private function flipped(GdImage $picture, int $mode): GdImage
    {
        imageflip($picture, $mode);

        return $picture;
    }

    private function rotated(GdImage $picture, int $degrees): GdImage
    {
        $rotated = imagerotate($picture, $degrees, 0);

        return $rotated instanceof GdImage ? $rotated : throw new RuntimeException('The photo could not be turned upright.');
    }
}
