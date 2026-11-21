<?php

namespace Database\Factories;

use App\Enums\PathType;
use App\Models\Path;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Path>
 */
class PathFactory extends Factory
{
    /**
     * A short walkway inside the campus.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'p'.fake()->unique()->numberBetween(100, 99999),
            'name' => 'Walkway '.fake()->unique()->numberBetween(100, 999),
            'type' => PathType::Walkway,
            'floor' => 1,
            'landmark_name' => null,
            'line' => [[124.7479, 8.5408], [124.7482, 8.5408]],
        ];
    }

    public function gate(): static
    {
        return $this->state(fn (array $attributes) => ['type' => PathType::Gate]);
    }

    /**
     * A corridor on an upper floor.
     */
    public function onFloor(int $floor): static
    {
        return $this->state(fn (array $attributes) => ['floor' => $floor]);
    }
}
