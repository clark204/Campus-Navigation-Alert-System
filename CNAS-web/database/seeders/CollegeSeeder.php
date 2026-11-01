<?php

namespace Database\Seeders;

use App\Models\College;
use Illuminate\Database\Seeder;

/**
 * Only C01 and C02 are confirmed from tcc.edu.ph; C03 to C09 names are
 * placeholders until checked against the TCC Student Handbook.
 */
class CollegeSeeder extends Seeder
{
    /**
     * Seed the colleges. Safe to run more than once (upserts by id).
     */
    public function run(): void
    {
        foreach ([
            'C01' => 'College of Information Technology',
            'C02' => 'College of Midwifery',
            'C03' => 'College of Business Administration',
            'C04' => 'College of Hospitality Management',
            'C05' => 'College of Criminology',
            'C06' => 'College of Library and Information Science',
            'C07' => 'College of Engineering Technology',
            'C08' => 'College of Teacher Education',
            'C09' => 'College of Arts and Sciences',
        ] as $id => $name) {
            College::updateOrCreate(['id' => $id], ['name' => $name]);
        }
    }
}
