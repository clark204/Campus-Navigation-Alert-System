<?php

namespace App\Services;

use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a photo into the two copies the site serves: a JPEG web copy and a JPEG thumbnail, on the public disk. GD
 * writes no EXIF, so the copies carry no GPS or camera details. The original is read and never kept.
 */
class PhotoCopies
{
    public const DISK = 'public';

    /** The web copy starts at the first quality and steps down until it is this small (or the last quality is reached). */
    public const WEB_TARGET_BYTES = 1_258_291;

    /** @var list<int> */
    private const WEB_QUALITIES = [82, 78, 74, 70];

    private const THUMB_QUALITY = 75;

    /**
     * Reads a JPEG, PNG or WebP photo.
     *
     * @throws RuntimeException when the file can't be read as one of those.
     */
    public function decode(string $file): GdImage
    {
        $size = @getimagesize($file);
        $image = match ($size === false ? null : $size[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($file),
            IMAGETYPE_PNG => @imagecreatefrompng($file),
            IMAGETYPE_WEBP => @imagecreatefromwebp($file),
            default => false,
        };

        return $image instanceof GdImage ? $image : throw new RuntimeException('The photo could not be read.');
    }

    /**
     * Writes the web copy (its longer side at most $webLongSide) and the thumbnail (at most $thumbLongSide) into
     * $folder on the public disk. The names carry a random part, so a re-used name never shows an old cached image.
     *
     * @return array{image_path: string, thumb_path: string, width: int, height: int} The paths, and the web copy's size.
     */
    public function save(GdImage $source, string $folder, string $name, int $webLongSide, int $thumbLongSide): array
    {
        $web = $this->scaled($source, $webLongSide);
        $thumb = $this->scaled($web, $thumbLongSide);

        foreach (self::WEB_QUALITIES as $quality) {
            $webBytes = $this->jpeg($web, $quality);
            if (strlen($webBytes) <= self::WEB_TARGET_BYTES) {
                break;
            }
        }

        $base = $folder.'/'.$name.'-'.Str::lower(Str::random(8));
        $paths = ['image_path' => "{$base}.jpg", 'thumb_path' => "{$base}-thumb.jpg"];

        $disk = Storage::disk(self::DISK);
        $disk->put($paths['image_path'], $webBytes);
        $disk->put($paths['thumb_path'], $this->jpeg($thumb, self::THUMB_QUALITY));

        return [...$paths, 'width' => imagesx($web), 'height' => imagesy($web)];
    }

    /**
     * The picture with its longer side at most $longSide, on a white background (a PNG's transparency becomes white).
     */
    private function scaled(GdImage $image, int $longSide): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $longSide / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $copy = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($copy, 0, 0, imagecolorallocate($copy, 255, 255, 255));
        imagecopyresampled($copy, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $copy;
    }

    private function jpeg(GdImage $image, int $quality): string
    {
        imageinterlace($image, true);
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }
}
