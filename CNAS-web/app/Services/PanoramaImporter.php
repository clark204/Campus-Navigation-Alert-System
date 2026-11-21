<?php

namespace App\Services;

use App\Models\Panorama;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Throwable;

/**
 * Adds 360° photos from a folder of equirectangular JPEGs (php artisan panoramas:import). The number in a file name
 * is its code ('P3.JPG' → 'pn3'); its position comes from the EXIF GPS. A code already in the database is skipped,
 * so positions, north and names fixed in the admin pages are never overwritten. New photos start hidden, with north
 * not set. The originals stay where they are; only the web copies are stored.
 */
class PanoramaImporter
{
    public function __construct(
        private readonly PanoramaImages $images,
        private readonly CampusMap $campusMap,
    ) {}

    /**
     * @return array{added: list<string>, skipped: array<string, string>} codes added, and why each skipped file was skipped (by file name)
     *
     * @throws InvalidArgumentException when the folder does not exist.
     */
    public function import(string $directory): array
    {
        if (! File::isDirectory($directory)) {
            throw new InvalidArgumentException("There is no folder at {$directory}.");
        }

        $files = collect(File::files($directory))
            ->filter(fn ($file) => in_array(strtolower($file->getExtension()), ['jpg', 'jpeg'], true))
            ->sortBy(fn ($file) => [$this->number($file->getFilename()) ?? PHP_INT_MAX, $file->getFilename()]);

        $added = [];
        $skipped = [];
        foreach ($files as $file) {
            $name = $file->getFilename();
            $problem = $this->problem($file->getPathname(), $name);
            if (is_string($problem)) {
                $skipped[$name] = $problem;

                continue;
            }
            [$code, $number, $position] = $problem;

            try {
                $this->images->create($file->getPathname(), [
                    'code' => $code,
                    'name' => "Photo point {$number}",
                    ...$position,
                    'floor' => 1,
                ]);
                $added[] = $code;
            } catch (Throwable $exception) {
                $skipped[$name] = $exception->getMessage();
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Why a file is skipped, or its code, number and position.
     *
     * @return string|array{string, int, array{latitude: float, longitude: float}}
     */
    private function problem(string $path, string $name): string|array
    {
        $number = $this->number($name);
        if ($number === null) {
            return 'there is no number in the file name to make its code from';
        }
        $code = Panorama::CODE_PREFIX.$number;
        if (Panorama::query()->where('code', $code)->exists()) {
            return "{$code} is already imported (kept as it is)";
        }
        if (($problem = $this->images->problem($path)) !== null) {
            return $problem;
        }
        $position = $this->images->position($path);
        if ($position === null) {
            return 'it has no GPS position (upload it in the admin page and place it on the map)';
        }
        if (! $this->campusMap->isNearCampus($position['latitude'], $position['longitude'])) {
            return "its GPS position ({$position['latitude']}, {$position['longitude']}) is not on campus";
        }

        return [$code, $number, $position];
    }

    private function number(string $name): ?int
    {
        return preg_match('/(\d+)/', pathinfo($name, PATHINFO_FILENAME), $match) ? (int) $match[1] : null;
    }
}
