<?php

namespace Database\Factories;

use App\Models\Concern;
use App\Models\ConcernReply;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConcernReply>
 */
class ConcernReplyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'concern_id' => Concern::factory(),
            'message' => fake()->sentence(12),
        ];
    }
}
