<?php

namespace App\Console\Commands;

use App\Services\PanoramaImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('panoramas:import
    {directory : Folder of 360° photos (equirectangular JPEGs with GPS); the number in each file name is its code (P3.JPG → pn3)}')]
#[Description('Add 360° photos from a folder: web copies and thumbnails on the public disk, position from EXIF GPS. Photos already imported are skipped; new ones start hidden.')]
class ImportPanoramas extends Command
{
    public function handle(PanoramaImporter $importer): int
    {
        try {
            $report = $importer->import((string) $this->argument('directory'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($report['added'] === [] ? 'Added: none.' : 'Added: '.implode(', ', $report['added']).'.');
        foreach ($report['skipped'] as $file => $reason) {
            $this->warn("Skipped {$file}: {$reason}.");
        }
        if ($report['added'] !== []) {
            $this->line('New photos are hidden until north is set and they are shown to users on the admin page "360° photos".');
        }

        return self::SUCCESS;
    }
}
