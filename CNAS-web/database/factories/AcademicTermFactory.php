<?php

namespace Database\Factories;

use App\Enums\Semester;
use App\Models\AcademicTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicTerm>
 */
class AcademicTermFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startYear = fake()->unique()->numberBetween(2000, 2099);

        return [
            'academic_year' => $startYear.'-'.($startYear + 1),
            'semester' => fake()->randomElement(Semester::cases()),
        ];
    }

    /**
     * A specific term, e.g. ->term('2026-2027', Semester::First).
     */
    public function term(string $academicYear, Semester $semester): static
    {
        return $this->state(fn (array $attributes) => [
            'academic_year' => $academicYear,
            'semester' => $semester,
        ]);
    }

    /**
     * Make the created term the (only) current one.
     */
    public function current(): static
    {
        return $this->afterCreating(fn (AcademicTerm $term) => $term->makeCurrent());
    }
}
