<?php

namespace Database\Factories;

use App\Models\Panorama;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Panorama>
 */
class PanoramaFactory extends Factory
{
    /**
     * A hidden photo point on the main walkway, north not set yet. The image paths point nowhere; tests that need
     * files make them with Tests\Support\PanoramaFixture.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(100, 99999);

        return [
            'code' => Panorama::CODE_PREFIX.$number,
            'name' => "Photo point {$number}",
            'latitude' => 8.5412066,
            'longitude' => 124.7482424,
            'floor' => 1,
            'north_offset' => null,
            'is_visible' => false,
            'image_path' => "panoramas/pn{$number}.jpg",
            'thumb_path' => "panoramas/pn{$number}-thumb.jpg",
        ];
    }

    /**
     * North set, and shown to users.
     */
    public function visible(float $northOffset = 90): static
    {
        return $this->state(fn (array $attributes) => ['north_offset' => $northOffset, 'is_visible' => true]);
    }

    public function withNorth(float $northOffset = 90): static
    {
        return $this->state(fn (array $attributes) => ['north_offset' => $northOffset]);
    }
}
