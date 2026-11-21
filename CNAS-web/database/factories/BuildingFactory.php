<?php

namespace Database\Factories;

use App\Enums\BuildingType;
use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    /** The real campus outline (b0), so pins and doors placed in tests count as on campus. */
    public const CAMPUS_RING = [
        [124.74707114, 8.5406381], [124.74686801, 8.54005599], [124.74702203, 8.54000809],
        [124.74810683, 8.54069143], [124.74818544, 8.5405652], [124.74873581, 8.54088772],
        [124.74868, 8.54120581], [124.74804398, 8.54140931], [124.74707114, 8.5406381],
    ];

    /**
     * A two-floor building: a small square inside the campus.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'b'.fake()->unique()->numberBetween(100, 99999),
            'name' => 'Building '.fake()->unique()->numberBetween(100, 999),
            'type' => BuildingType::Building,
            'floors' => 2,
            'landmark_name' => null,
            'outline' => [[
                [124.7480, 8.5409], [124.7481, 8.5409], [124.7481, 8.5410], [124.7480, 8.5410], [124.7480, 8.5409],
            ]],
        ];
    }

    /**
     * The campus outline: no floors, and the area doors and alert pins must fall inside.
     */
    public function campus(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'TCC',
            'type' => BuildingType::Campus,
            'floors' => null,
            'outline' => [self::CAMPUS_RING],
        ]);
    }

    public function facility(): static
    {
        return $this->state(fn (array $attributes) => ['type' => BuildingType::Facility, 'floors' => 1]);
    }

    public function evacuation(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Evacuation area',
            'type' => BuildingType::Evacuation,
            'floors' => null,
        ]);
    }
}
