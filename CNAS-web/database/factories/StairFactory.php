<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Stair;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Stair>
 */
class StairFactory extends Factory
{
    /**
     * A stair reaching the second floor of its building.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 's'.fake()->unique()->numberBetween(100, 99999),
            'name' => 'Stair '.fake()->unique()->numberBetween(100, 999),
            'building_id' => Building::factory(),
            'floors' => 2,
            'center_latitude' => 8.54095,
            'center_longitude' => 124.74805,
            'outline' => [[
                [124.74804, 8.54094], [124.74806, 8.54094], [124.74806, 8.54096], [124.74804, 8.54096], [124.74804, 8.54094],
            ]],
        ];
    }
}
