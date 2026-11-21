<?php

namespace Database\Seeders;

use App\Models\EmergencyContact;
use Illuminate\Database\Seeder;

/**
 * The emergency contacts the portal listed before they had numbers. Seeded without a number and hidden: the admin adds
 * the numbers on /admin/settings once the school confirms them (a wrong emergency number is worse than none).
 * Safe to run again: a contact that already exists, edited or not, is left as it is.
 */
class EmergencyContactSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Campus security', 'School clinic', 'Guidance office'] as $name) {
            EmergencyContact::firstOrCreate(['name' => $name], ['phone' => null, 'is_visible' => false]);
        }
    }
}
