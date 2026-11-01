<?php

namespace Database\Seeders;

use App\Enums\Semester;
use App\Models\AcademicTerm;
use Illuminate\Database\Seeder;

/**
 * Seeds 2026-2027 1st Semester as the current term. Admins change it from the admin page.
 */
class AcademicTermSeeder extends Seeder
{
    /**
     * Safe to run more than once. Doesn't override a different current term an admin already chose.
     */
    public function run(): void
    {
        $term = AcademicTerm::firstOrCreate(['academic_year' => '2026-2027', 'semester' => Semester::First]);

        if (AcademicTerm::current() === null) {
            $term->makeCurrent();
        }
    }
}
