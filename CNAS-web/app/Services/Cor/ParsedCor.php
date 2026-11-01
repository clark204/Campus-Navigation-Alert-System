<?php

namespace App\Services\Cor;

use App\Enums\Semester;

/**
 * What CorExtractor read from a COR. Every field is nullable because a tampered or
 * unexpected COR may be missing it; CorValidator decides what that means.
 *
 * Academic year, semester, subjects, units and date enrolled are only used to validate
 * the COR. They are never stored.
 */
final readonly class ParsedCor
{
    /**
     * @param  string  $text  The first printed copy, with whitespace collapsed to single spaces.
     * @param  ?string  $semesterText  The semester exactly as printed, e.g. "2nd Term".
     * @param  ?Semester  $semester  $semesterText normalised; null when it isn't recognised.
     * @param  ?string  $dateEnrolled  Y-m-d, or null when missing or not a real date.
     * @param  list<array{course: string, section: string, units: float}>  $subjects  One entry per course.
     * @param  list<array{student_id: ?string, name: ?string, academic_year: ?string, program: ?string}>  $copies  Key fields of every printed copy, in order.
     */
    public function __construct(
        public string $text,
        public ?string $studentId,
        public ?string $fullName,
        public ?string $firstName,
        public ?string $middleName,
        public ?string $lastName,
        public ?string $program,
        public ?string $collegeId,
        public ?string $academicYear,
        public ?string $semesterText,
        public ?Semester $semester,
        public ?string $dateEnrolled,
        public array $subjects,
        public ?float $totalUnits,
        public array $copies,
    ) {}
}
