<?php

namespace Database\Factories;

use App\Models\EmergencyContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A visible contact with a number. Its position is the end of the list (EmergencyContact::booted).
 *
 * @extends Factory<EmergencyContact>
 */
class EmergencyContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'phone' => '+63 9'.fake()->numerify('##-###-####'),
            'note' => null,
            'is_visible' => true,
        ];
    }

    /**
     * Hidden from students.
     */
    public function hidden(): static
    {
        return $this->state(fn () => ['is_visible' => false]);
    }

    /**
     * No number yet (always hidden).
     */
    public function withoutPhone(): static
    {
        return $this->state(fn () => ['phone' => null, 'is_visible' => false]);
    }
}
