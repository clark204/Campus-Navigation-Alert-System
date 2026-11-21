<?php

namespace Database\Factories;

use App\Enums\PhotoKind;
use App\Models\PlacePhoto;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Photo rows for tests. The files are not written: fake the public disk when a test needs them.
 *
 * @extends Factory<PlacePhoto>
 */
class PlacePhotoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'place-photos/'.Str::lower(Str::random(12));

        return [
            'kind' => PhotoKind::Photo,
            'image_path' => "{$name}.jpg",
            'thumb_path' => "{$name}-thumb.jpg",
            'width' => 1600,
            'height' => 1200,
            'position' => 0,
        ];
    }

    /**
     * A 360° photo: twice as wide as it is tall.
     */
    public function panorama(): static
    {
        return $this->state(fn () => ['kind' => PhotoKind::Panorama, 'width' => 4096, 'height' => 2048]);
    }
}
