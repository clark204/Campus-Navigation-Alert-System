<?php

namespace Database\Factories;

use App\Enums\AlertResolution;
use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\AlertType;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    /**
     * An active fire alert (not a drill), sent just now.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            ...AlertType::Fire->template(),
            'type' => AlertType::Fire,
            'severity' => AlertType::Fire->defaultSeverity(),
            'is_drill' => false,
            'status' => AlertStatus::Active,
            'created_by' => User::factory()->state(['role' => UserRole::Admin]),
            'sent_at' => now(),
        ];
    }

    /**
     * Pinned in the middle of the NEE Building (b1) with a danger zone, and that building affected.
     */
    public function pinned(int $radius = 50): static
    {
        return $this->state(fn (array $attributes) => [
            'latitude' => 8.5409326,
            'longitude' => 124.7483871,
            'danger_radius_m' => $radius,
            'building_codes' => ['b1'],
        ]);
    }

    public function advisory(): static
    {
        return $this->state(fn (array $attributes) => ['type' => AlertType::Other, 'severity' => AlertSeverity::Advisory]);
    }

    public function warning(): static
    {
        return $this->state(fn (array $attributes) => ['type' => AlertType::Flood, 'severity' => AlertSeverity::Warning]);
    }

    public function drill(): static
    {
        return $this->state(fn (array $attributes) => ['is_drill' => true]);
    }

    public function resolved(?string $message = 'All clear. It is safe to go back inside.', AlertResolution $resolution = AlertResolution::AllClear): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AlertStatus::Resolved,
            'resolution' => $resolution,
            'resolved_at' => now(),
            'resolved_by' => fn (array $attributes) => $attributes['created_by'],
            'resolved_message' => $message,
        ]);
    }
}
