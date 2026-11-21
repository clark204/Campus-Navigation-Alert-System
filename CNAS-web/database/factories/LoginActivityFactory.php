<?php

namespace Database\Factories;

use App\Enums\LoginChannel;
use App\Models\LoginActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LoginActivity>
 */
class LoginActivityFactory extends Factory
{
    /**
     * A web sign-in from Chrome on Windows that is still signed in.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'channel' => LoginChannel::Web,
            'ip_address' => fake()->ipv4(),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36',
            'device' => 'Chrome on Windows',
            'logged_in_at' => now(),
            'logged_out_at' => null,
        ];
    }

    /**
     * A mobile app sign-in from an Android phone.
     */
    public function fromMobileApp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => LoginChannel::Api,
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
            'device' => 'Chrome on Android',
        ]);
    }

    public function signedOut(): static
    {
        return $this->state(fn (array $attributes) => [
            'logged_out_at' => now(),
        ]);
    }
}
