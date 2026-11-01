<?php

namespace Tests\Unit\Cor;

use App\Enums\Semester;
use App\Services\Cor\CorExtractor;
use App\Services\Cor\CorParseException;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CorFixture;
use Tests\TestCase;

/**
 * Uses the framework TestCase because the program → college lookup reads the programs table.
 */
class CorExtractorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
    }

    /**
     * @param  array<string, mixed>  $expected
     */
    #[DataProvider('fixtures')]
    public function test_each_layout_parses(string $fixture, array $expected): void
    {
        $cor = (new CorExtractor)->parseText(CorFixture::text($fixture));

        $this->assertSame($expected, [
            'student_id' => $cor->studentId,
            'name' => $cor->fullName,
            'program' => $cor->program,
            'college_id' => $cor->collegeId,
            'academic_year' => $cor->academicYear,
            'semester' => $cor->semester,
            'date_enrolled' => $cor->dateEnrolled,
            'subjects' => count($cor->subjects),
            'total_units' => $cor->totalUnits,
        ]);
        $this->assertSame($cor->totalUnits, array_sum(array_column($cor->subjects, 'units')));
        $this->assertCount(2, $cor->copies);
        $this->assertSame($cor->copies[0], $cor->copies[1]);
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function fixtures(): array
    {
        $current = [
            'student_id' => '20240001',
            'name' => 'Juan Miguel Dela Cruz S',
            'program' => 'BSIT',
            'college_id' => 'C01',
            'academic_year' => '2026-2027',
            'semester' => Semester::First,
            'date_enrolled' => '2026-07-08',
            'subjects' => 9,
            'total_units' => 27.0,
        ];

        return [
            'current layout (pdftotext)' => [CorFixture::CURRENT_LAYOUT, $current],
            'current layout (smalot, units glued to the day)' => [CorFixture::CURRENT_SMALOT, $current],
            'older layout (smalot)' => [CorFixture::OLDER_SMALOT, [
                'student_id' => '20230002',
                'name' => 'Maria Clara Santos R',
                'program' => 'BSIT',
                'college_id' => 'C01',
                'academic_year' => '2025-2026',
                'semester' => Semester::Second,
                'date_enrolled' => '2026-01-07',
                'subjects' => 9,
                'total_units' => 26.0,
            ]],
        ];
    }

    public function test_older_layout_counts_each_course_once(): void
    {
        $cor = (new CorExtractor)->parseText(CorFixture::text(CorFixture::OLDER_SMALOT));

        $this->assertSame(
            ['IT 112', 'IT 113', 'IT 114', 'IT 115', 'IT 116', 'PATH FIT 4', 'GEC 4', 'GEE 6', 'GEE 8'],
            array_column($cor->subjects, 'course'),
        );
        $this->assertSame('BSIT 2B', $cor->subjects[0]['section']);
        $this->assertSame(2.0, $cor->subjects[5]['units']);
    }

    public function test_it_extracts_a_real_pdf(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cor');
        file_put_contents($path, CorFixture::pdf());

        try {
            $cor = (new CorExtractor)->extract($path);
        } finally {
            unlink($path);
        }

        $this->assertSame('20240001', $cor->studentId);
        $this->assertSame('Dela Cruz', $cor->lastName);
        $this->assertSame(Semester::First, $cor->semester);
        $this->assertSame(27.0, $cor->totalUnits);
        $this->assertCount(9, $cor->subjects);
    }

    public function test_a_file_that_is_not_a_pdf_cannot_be_parsed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cor');
        file_put_contents($path, '%PDF-1.7 this is not really a PDF');

        try {
            $this->expectException(CorParseException::class);
            (new CorExtractor)->extract($path);
        } finally {
            unlink($path);
        }
    }

    public function test_an_unknown_program_has_no_college(): void
    {
        $cor = (new CorExtractor)->parseText(CorFixture::tampered('Program : BSIT', 'Program : BSXYZ'));

        $this->assertSame('BSXYZ', $cor->program);
        $this->assertNull($cor->collegeId);
    }

    public function test_an_impossible_date_is_not_a_date(): void
    {
        $cor = (new CorExtractor)->parseText(CorFixture::tampered('July 08, 2026', 'February 30, 2026'));

        $this->assertNull($cor->dateEnrolled);
    }

    /**
     * @param  array{0: ?string, 1: ?string, 2: ?string}  $expected  first, middle, last
     */
    #[DataProvider('names')]
    public function test_it_guesses_the_name_split(string $printedName, array $expected): void
    {
        $cor = (new CorExtractor)->parseText(CorFixture::tampered('Juan Miguel Dela Cruz S', $printedName));

        $this->assertSame($expected, [$cor->firstName, $cor->middleName, $cor->lastName]);
    }

    /**
     * @return array<string, array{string, array{0: ?string, 1: ?string, 2: ?string}}>
     */
    public static function names(): array
    {
        return [
            'one given name' => ['Jose Rizal M', ['Jose', 'M', 'Rizal']],
            'multi-word particle' => ['Maria Clara De La Cruz A.', ['Maria Clara', 'A', 'De La Cruz']],
            'no middle initial' => ['Ana Santos', ['Ana', null, 'Santos']],
            'suffix stays with the last name' => ['Pedro Reyes Jr. B', ['Pedro', 'B', 'Reyes Jr.']],
        ];
    }
}
