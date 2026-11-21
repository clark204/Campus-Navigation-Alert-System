<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Small real pictures for the building and room photo tests: JPEGs with an optional EXIF orientation tag (a phone
 * photo stored sideways), and PNG and WebP pictures. Drawn with GD, so every file really decodes.
 */
final class PhotoFixture
{
    public static function jpeg(int $width = 300, int $height = 200, ?int $orientation = null): string
    {
        $jpeg = self::picture($width, $height, fn ($image) => imagejpeg($image, null, 80));

        return $orientation === null ? $jpeg : substr($jpeg, 0, 2).self::exifOrientation($orientation).substr($jpeg, 2);
    }

    public static function png(int $width = 300, int $height = 200): string
    {
        return self::picture($width, $height, fn ($image) => imagepng($image));
    }

    public static function webp(int $width = 300, int $height = 200): string
    {
        return self::picture($width, $height, fn ($image) => imagewebp($image, null, 80));
    }

    public static function upload(string $name, string $contents): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $contents);
    }

    /**
     * @param  callable(\GdImage): mixed  $encode
     */
    private static function picture(int $width, int $height, callable $encode): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width - 1, intdiv($height, 2), imagecolorallocate($image, 120, 170, 230));
        imagefilledrectangle($image, 0, intdiv($height, 2), $width - 1, $height - 1, imagecolorallocate($image, 150, 140, 120));
        ob_start();
        $encode($image);

        return (string) ob_get_clean();
    }

    /**
     * An APP1 "Exif" segment holding only the Orientation tag (1 to 8), as cameras and phones write it.
     */
    private static function exifOrientation(int $orientation): string
    {
        $tiff = 'II'.pack('v', 42).pack('V', 8)
            .pack('v', 1)
            .pack('vvV', 0x0112, 3, 1).pack('v', $orientation)."\0\0"
            .pack('V', 0);

        $payload = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }
}
