<?php

namespace Database\Seeders;

use App\Models\Program;
use Illuminate\Database\Seeder;

/**
 * Program codes exactly as printed after "Program :" on the COR.
 * Only BSIT is confirmed from a real COR; the others are placeholders to check
 * against real CORs. A code missing here doesn't block registration: the
 * student picks their college by hand and the account is flagged for review.
 */
class ProgramSeeder extends Seeder
{
    /**
     * Seed the programs. Safe to run more than once (upserts by code). Run CollegeSeeder first.
     */
    public function run(): void
    {
        foreach ([
            'BSIT' => ['Bachelor of Science in Information Technology', 'C01'],
            'BSM' => ['Bachelor of Science in Midwifery', 'C02'],
            'BSBA' => ['Bachelor of Science in Business Administration', 'C03'],
            'BSHM' => ['Bachelor of Science in Hospitality Management', 'C04'],
            'BSCRIM' => ['Bachelor of Science in Criminology', 'C05'],
            'BLIS' => ['Bachelor of Library and Information Science', 'C06'],
            'BSET' => ['Bachelor of Science in Engineering Technology', 'C07'],
            'BEED' => ['Bachelor of Elementary Education', 'C08'],
            'BSED' => ['Bachelor of Secondary Education', 'C08'],
            'ABENG' => ['Bachelor of Arts in English Language', 'C09'],
        ] as $code => [$name, $collegeId]) {
            Program::updateOrCreate(['code' => $code], ['name' => $name, 'college_id' => $collegeId]);
        }
    }
}
