<?php

namespace Database\Seeders;

use App\Services\CampusMapImporter;
use Illuminate\Database\Seeder;

/**
 * Fills the campus map (buildings, paths, stairs, rooms) from database/data/maps.
 */
class CampusMapSeeder extends Seeder
{
    /**
     * Safe to run more than once: it only adds what is missing and never undoes edits made in the admin pages.
     */
    public function run(CampusMapImporter $importer): void
    {
        $importer->import(database_path('data/maps'));
    }
}
