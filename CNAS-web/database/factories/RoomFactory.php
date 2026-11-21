<?php

namespace Database\Factories;

use App\Enums\RoomType;
use App\Models\Building;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * A ground-floor classroom with its door inside the campus.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'r'.fake()->unique()->numberBetween(100, 99999),
            'name' => 'Room '.fake()->unique()->numberBetween(100, 999),
            'building_id' => Building::factory(),
            'floor' => 1,
            'type' => RoomType::Classroom,
            'is_it' => false,
            'door' => null,
            'latitude' => 8.54095,
            'longitude' => 124.74805,
        ];
    }
}
