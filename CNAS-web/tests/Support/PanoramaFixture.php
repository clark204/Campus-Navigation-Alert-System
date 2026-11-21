<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Small real JPEGs standing in for 360° photos: drawn with GD, optionally with an EXIF block holding a GPS position
 * (GD writes none, so it is spliced in after the start-of-image marker, the way cameras write it).
 */
final class PanoramaFixture
{
    /** A spot on the main walkway, inside the campus outline of BuildingFactory::campus(). */
    public const LATITUDE = 8.5412066;

    public const LONGITUDE = 124.7482424;

    /**
     * @param  array{float, float}|null  $gps  [latitude, longitude]
     */
    public static function jpeg(int $width = 2048, int $height = 1024, ?array $gps = [self::LATITUDE, self::LONGITUDE]): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, intdiv($height, 2), imagecolorallocate($image, 120, 170, 230));
        imagefilledrectangle($image, 0, intdiv($height, 2), $width - 1, $height - 1, imagecolorallocate($image, 150, 140, 120));
        ob_start();
        imagejpeg($image, null, 80);
        $jpeg = (string) ob_get_clean();

        return $gps === null ? $jpeg : substr($jpeg, 0, 2).self::exifGps(...$gps).substr($jpeg, 2);
    }

    /**
     * @param  array{float, float}|null  $gps
     */
    public static function file(string $directory, string $name, int $width = 2048, int $height = 1024, ?array $gps = [self::LATITUDE, self::LONGITUDE]): string
    {
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, self::jpeg($width, $height, $gps));

        return $path;
    }

    /**
     * @param  array{float, float}|null  $gps
     */
    public static function upload(string $name = 'walkway.jpg', int $width = 2048, int $height = 1024, ?array $gps = [self::LATITUDE, self::LONGITUDE]): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::jpeg($width, $height, $gps));
    }

    /**
     * An APP1 "Exif" segment: a little-endian TIFF with IFD0 pointing at a GPS IFD (latitude and longitude, each as
     * degrees, minutes and seconds).
     */
    private static function exifGps(float $latitude, float $longitude): string
    {
        $entry = fn (int $tag, int $type, int $count, string $value) => pack('vvV', $tag, $type, $count).str_pad($value, 4, "\0");
        $rationals = function (float $decimal): string {
            $decimal = abs($decimal);
            $degrees = (int) floor($decimal);
            $minutes = (int) floor(($decimal - $degrees) * 60);
            $seconds = (int) round((($decimal - $degrees) * 60 - $minutes) * 60 * 10000);

            return pack('VVVVVV', $degrees, 1, $minutes, 1, $seconds, 10000);
        };

        // Header (8) + IFD0 with 1 entry (18) = 26: the GPS IFD with 4 entries (54) ends at 80, then the two values.
        $tiff = "II\x2A\x00".pack('V', 8)
            .pack('v', 1).$entry(0x8825, 4, 1, pack('V', 26)).pack('V', 0)
            .pack('v', 4)
            .$entry(0x0001, 2, 2, $latitude < 0 ? 'S' : 'N')
            .$entry(0x0002, 5, 3, pack('V', 80))
            .$entry(0x0003, 2, 2, $longitude < 0 ? 'W' : 'E')
            .$entry(0x0004, 5, 3, pack('V', 104))
            .pack('V', 0)
            .$rationals($latitude).$rationals($longitude);
        $body = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($body) + 2).$body;
    }
}
