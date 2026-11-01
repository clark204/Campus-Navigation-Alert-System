<?php

namespace Tests\Unit;

use App\Enums\Semester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class SemesterTest extends TestCase
{
    #[DataProvider('spellings')]
    public function test_cor_spellings_normalise(string $printed, Semester $expected): void
    {
        $this->assertSame($expected, Semester::fromCorText($printed));
    }

    /**
     * @return array<string, array{string, Semester}>
     */
    public static function spellings(): array
    {
        return [
            '1st' => ['1st', Semester::First],
            '1st Term' => ['1st Term', Semester::First],
            '1st Sem' => ['1st Sem', Semester::First],
            'First Semester' => ['First Semester', Semester::First],
            '2nd' => ['2nd', Semester::Second],
            '2nd Term' => ['2nd Term', Semester::Second],
            '2nd Sem' => ['2nd Sem', Semester::Second],
            'Second Semester' => ['Second Semester', Semester::Second],
            'Summer' => ['Summer', Semester::Summer],
            'Summer Term' => ['Summer Term', Semester::Summer],
            'Midyear' => ['Midyear', Semester::Summer],
            'any case, extra spaces' => ['  sECOND   semester ', Semester::Second],
        ];
    }

    #[TestWith(['3rd'])]
    #[TestWith(['Trimester'])]
    #[TestWith(['1st Quarter'])]
    #[TestWith([''])]
    public function test_unknown_text_is_not_a_semester(string $printed): void
    {
        $this->assertNull(Semester::fromCorText($printed));
    }

    public function test_labels(): void
    {
        $this->assertSame(
            ['1st Semester', '2nd Semester', 'Summer'],
            array_map(fn (Semester $semester) => $semester->label(), Semester::cases()),
        );
    }
}
