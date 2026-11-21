<?php

namespace Database\Factories;

use App\Enums\ConcernTopic;
use App\Models\Concern;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open concern from a guest (no account). Use for() with a user for a signed-in sender.
 *
 * @extends Factory<Concern>
 */
class ConcernFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->safeEmail(),
            'topic' => fake()->randomElement(ConcernTopic::cases()),
            'message' => fake()->sentence(12),
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn () => ['resolved_at' => now()]);
    }
}
