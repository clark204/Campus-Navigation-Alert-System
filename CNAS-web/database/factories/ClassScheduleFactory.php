<?php

namespace Database\Factories;

use App\Enums\ScheduleSource;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\AcademicTerm;
use App\Models\ClassSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSchedule>
 */
class ClassScheduleFactory extends Factory
{
    /**
     * A manual Monday morning class of a new student. The term is the current one, or a new term when there is none.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => UserRole::Student]),
            'academic_term_id' => fn () => AcademicTerm::current()?->id ?? AcademicTerm::factory(),
            'subject' => 'IT '.fake()->numberBetween(100, 199),
            'section' => 'BSIT3B',
            'day' => Weekday::Mon,
            'starts_at' => '08:00',
            'ends_at' => '09:30',
            'room_text' => 'TBA',
            'source' => ScheduleSource::Manual,
        ];
    }

    /**
     * A row that came from the student's COR.
     */
    public function fromCor(): static
    {
        return $this->state(['source' => ScheduleSource::Cor]);
    }

    /**
     * e.g. ->at(Weekday::Thu, '16:00', '19:00').
     */
    public function at(Weekday $day, string $startsAt, string $endsAt): static
    {
        return $this->state(['day' => $day, 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }
}
