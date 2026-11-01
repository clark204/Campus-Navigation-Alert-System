<?php

namespace Tests\Unit\Cor;

use App\Enums\Semester;
use App\Models\AcademicTerm;
use App\Services\Cor\CorCheck;
use App\Services\Cor\CorExtractor;
use App\Services\Cor\CorValidator;
use Database\Seeders\CollegeSeeder;
use Database\Seeders\ProgramSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CorFixture;
use Tests\TestCase;

/**
 * The validator itself is pure; the framework TestCase is only needed to parse the fixture
 * (program → college lookup). Terms are unsaved models.
 */
class CorValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([CollegeSeeder::class, ProgramSeeder::class]);
    }

    private function term(string $academicYear = '2026-2027', Semester $semester = Semester::First): AcademicTerm
    {
        return new AcademicTerm(['academic_year' => $academicYear, 'semester' => $semester]);
    }

    /**
     * @return list<CorCheck>
     */
    private function failedChecks(string $text, ?AcademicTerm $term = null): array
    {
        return (new CorValidator)->validate((new CorExtractor)->parseText($text), $term ?? $this->term());
    }

    public function test_current_layout_cors_pass_for_the_current_term(): void
    {
        $this->assertSame([], $this->failedChecks(CorFixture::text(CorFixture::CURRENT_LAYOUT)));
        $this->assertSame([], $this->failedChecks(CorFixture::text(CorFixture::CURRENT_SMALOT)));
    }

    public function test_the_older_layout_passes_for_its_own_term(): void
    {
        $this->assertSame([], $this->failedChecks(CorFixture::text(CorFixture::OLDER_SMALOT), $this->term('2025-2026', Semester::Second)));
    }

    public function test_a_well_formed_cor_for_another_term_fails_only_the_term_check(): void
    {
        $this->assertSame([CorCheck::AcademicTerm], $this->failedChecks(CorFixture::text(CorFixture::OLDER_SMALOT)));
        $this->assertFalse(CorCheck::AcademicTerm->isFormatCheck());
    }

    public function test_changing_the_current_term_rejects_the_current_layout_cor(): void
    {
        $this->assertSame(
            [CorCheck::AcademicTerm],
            $this->failedChecks(CorFixture::text(CorFixture::CURRENT_SMALOT), $this->term('2026-2027', Semester::Second)),
        );
    }

    public function test_an_unrecognised_semester_is_a_format_failure(): void
    {
        $failed = $this->failedChecks(CorFixture::tampered('AY : 2026-2027 1st', 'AY : 2026-2027 3rd'));

        $this->assertSame([CorCheck::Semester, CorCheck::AcademicTerm], $failed);
        $this->assertTrue(CorCheck::Semester->isFormatCheck());
    }

    public function test_wrong_total_units_is_rejected(): void
    {
        $this->assertSame([CorCheck::TotalUnits], $this->failedChecks(CorFixture::tampered('Total Units : 27', 'Total Units : 30')));
    }

    public function test_a_seven_digit_student_id_is_rejected(): void
    {
        $this->assertSame([CorCheck::StudentIdFormat], $this->failedChecks(CorFixture::tampered('20240001', '2024001')));
    }

    public function test_a_missing_header_is_rejected(): void
    {
        $text = CorFixture::tampered('Certificate of Registration', 'Enrollment Form', copy: 1);

        $this->assertSame([CorCheck::Header], $this->failedChecks($text));
    }

    public function test_mismatched_copies_are_rejected(): void
    {
        $text = CorFixture::tampered('Juan Miguel Dela Cruz S', 'Pedro Santos Reyes A', copy: 2);

        $this->assertSame([CorCheck::CopiesMatch], $this->failedChecks($text));
    }

    public function test_a_single_copy_is_rejected(): void
    {
        $text = CorFixture::text();
        $firstCopyOnly = substr($text, 0, strpos($text, 'Tagoloan Community College', 100));

        $this->assertSame([CorCheck::CopiesMatch], $this->failedChecks($firstCopyOnly));
    }

    public function test_an_unknown_program_is_rejected(): void
    {
        $this->assertSame([CorCheck::KnownProgram], $this->failedChecks(CorFixture::tampered('Program : BSIT', 'Program : BSXYZ')));
    }

    public function test_an_impossible_date_enrolled_is_rejected(): void
    {
        $this->assertSame([CorCheck::DateEnrolled], $this->failedChecks(CorFixture::tampered('July 08, 2026', 'February 30, 2026')));
    }

    public function test_a_missing_label_is_rejected(): void
    {
        $text = CorFixture::tampered('Date Enrolled :', 'Enrolled on', copy: 1);

        $this->assertContains(CorCheck::Labels, $this->failedChecks($text));
    }
}
