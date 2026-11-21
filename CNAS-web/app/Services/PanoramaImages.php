<?php

namespace App\Services;

use App\Models\Panorama;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Reads a 360° photo (an equirectangular JPEG) and makes the copies the site serves: a web copy at most 4096×2048
 * of about 1 MB and a 640×320 thumbnail, on the public disk (the copying itself is PhotoCopies). GD writes no EXIF, so
 * the copies carry no GPS or camera details. The photo content itself is never changed; the original is read and left
 * where it is.
 */
class PanoramaImages
{
    public const MIN_WIDTH = 2048;

    public const WEB_WIDTH = 4096;

    public const THUMB_WIDTH = 640;

    private const FOLDER = 'panoramas';

    public function __construct(private readonly PhotoCopies $copies) {}

    /**
     * Why a file can't be used as a 360° photo, or null when it can: a JPEG twice as wide as it is tall, at least
     * MIN_WIDTH wide.
     */
    public function problem(string $file): ?string
    {
        $size = @getimagesize($file);
        if ($size === false || $size[2] !== IMAGETYPE_JPEG) {
            return 'it is not a JPEG photo';
        }
        [$width, $height] = $size;
        if ($width !== 2 * $height) {
            return "it is {$width}×{$height}, not a full 360° photo (twice as wide as it is tall)";
        }
        if ($width < self::MIN_WIDTH) {
            return "it is only {$width} pixels wide (at least ".self::MIN_WIDTH.' needed)';
        }

        return null;
    }

    /**
     * Where the photo was taken, from its EXIF GPS, or null when it has none.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function position(string $file): ?array
    {
        $exif = @exif_read_data($file);
        if (! is_array($exif) || ! isset($exif['GPSLatitude'], $exif['GPSLongitude'])) {
            return null;
        }
        $latitude = $this->degrees($exif['GPSLatitude'], $exif['GPSLatitudeRef'] ?? 'N');
        $longitude = $this->degrees($exif['GPSLongitude'], $exif['GPSLongitudeRef'] ?? 'E');
        if ($latitude === null || $longitude === null || ($latitude === 0.0 && $longitude === 0.0)) {
            return null;
        }

        return ['latitude' => round($latitude, 8), 'longitude' => round($longitude, 8)];
    }

    /**
     * Makes the web copy and the thumbnail for a 360° photo.
     *
     * @return array{image_path: string, thumb_path: string}
     *
     * @throws \RuntimeException when the JPEG can't be decoded.
     */
    public function store(string $file, string $code): array
    {
        $copies = $this->copies->save($this->copies->decode($file), self::FOLDER, $code, self::WEB_WIDTH, self::THUMB_WIDTH);

        return ['image_path' => $copies['image_path'], 'thumb_path' => $copies['thumb_path']];
    }

    /**
     * Makes the copies and the photo's row; the copies are removed again if the row can't be saved.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(string $file, array $attributes): Panorama
    {
        $paths = $this->store($file, $attributes['code']);
        try {
            return Panorama::create([...$attributes, ...$paths]);
        } catch (Throwable $exception) {
            Storage::disk(Panorama::DISK)->delete(array_values($paths));
            throw $exception;
        }
    }

    /**
     * EXIF degrees, minutes and seconds (['8/1', '32/1', '2840/100']) as signed decimal degrees.
     */
    private function degrees(mixed $parts, mixed $reference): ?float
    {
        if (! is_array($parts) || count($parts) !== 3) {
            return null;
        }
        $values = [];
        foreach ($parts as $part) {
            $pieces = explode('/', (string) $part);
            if (count($pieces) === 2) {
                if ((float) $pieces[1] === 0.0) {
                    return null;
                }
                $values[] = (float) $pieces[0] / (float) $pieces[1];
            } elseif (is_numeric($part)) {
                $values[] = (float) $part;
            } else {
                return null;
            }
        }
        $degrees = $values[0] + $values[1] / 60 + $values[2] / 3600;

        return in_array(strtoupper((string) $reference), ['S', 'W'], true) ? -$degrees : $degrees;
    }
}
