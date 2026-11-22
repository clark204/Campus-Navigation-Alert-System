<?php

namespace Database\Factories;

use App\Models\DevicePushToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DevicePushToken>
 */
class DevicePushTokenFactory extends Factory
{
    /**
     * A guest phone's token.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expo_token' => 'ExponentPushToken['.Str::random(22).']',
            'platform' => 'android',
            'device_name' => 'Samsung Galaxy A15',
            'last_seen_at' => now(),
        ];
    }
}
