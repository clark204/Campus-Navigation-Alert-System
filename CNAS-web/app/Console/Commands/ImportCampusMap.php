<?php

namespace App\Console\Commands;

use App\Services\CampusMapImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('map:import
    {directory : Folder holding buildings.geojson, paths.geojson, entrances.geojson and optionally stairs.geojson and landmarks.json}
    {--overwrite : Also replace names and other details edited in the admin pages with the values in the files}')]
#[Description('Add new campus map features from GeoJSON files and refresh the shapes of existing ones. Never deletes.')]
class ImportCampusMap extends Command
{
    public function handle(CampusMapImporter $importer): int
    {
        try {
            $report = $importer->import((string) $this->argument('directory'), (bool) $this->option('overwrite'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Added: '.$this->counts($report['imported']).'.');
        $this->info('Updated: '.$this->counts($report['updated']).'.');

        foreach (array_filter($report['missing']) as $kind => $codes) {
            $this->warn("In the database but not in the files (kept): {$kind} ".implode(', ', $codes).'.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{buildings: int, paths: int, stairs: int, rooms: int}  $counts
     */
    private function counts(array $counts): string
    {
        return collect($counts)->map(fn (int $count, string $kind) => "{$count} {$kind}")->implode(', ');
    }
}
