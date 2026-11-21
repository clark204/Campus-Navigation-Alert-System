<?php

namespace App\Services\Cor;

use App\Enums\Semester;

/**
 * What CorExtractor read from a COR. Every field is nullable because a tampered or
 * unexpected COR may be missing it; CorValidator decides what that means.
 *
 * Academic year, semester, units and date enrolled are only used to validate the COR. They
 * are never stored. The subjects' meetings become the student's class schedule when the
 * student asks for that (registration checkbox, or "Import from COR" in the portal).
 */
final readonly class ParsedCor
{
    /**
     * @param  string  $text  The first printed copy, with whitespace collapsed to single spaces.
     * @param  ?string  $semesterText  The semester exactly as printed, e.g. "2nd Term".
     * @param  ?Semester  $semester  $semesterText normalised; null when it isn't recognised.
     * @param  ?string  $dateEnrolled  Y-m-d, or null when missing or not a real date.
     * @param  list<array{course: string, section: string, units: float, meetings: list<array{day: string, starts_at: string, ends_at: string, room: string}>}>  $subjects  One entry per course. Meeting times are 24-hour "H:i"; day is an App\Enums\Weekday value.
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
        public ?string $programCode,
        public ?string $academicYear,
        public ?string $semesterText,
        public ?Semester $semester,
        public ?string $dateEnrolled,
        public array $subjects,
        public ?float $totalUnits,
        public array $copies,
    ) {}

    /**
     * Every class meeting on the COR, as class schedule rows (see App\Models\ClassSchedule).
     *
     * @return list<array{subject: string, section: string, day: string, starts_at: string, ends_at: string, room_text: string}>
     */
    public function meetings(): array
    {
        $meetings = [];

        foreach ($this->subjects as $subject) {
            foreach ($subject['meetings'] as $meeting) {
                $meetings[] = [
                    'subject' => $subject['course'],
                    'section' => $subject['section'],
                    'day' => $meeting['day'],
                    'starts_at' => $meeting['starts_at'],
                    'ends_at' => $meeting['ends_at'],
                    'room_text' => $meeting['room'],
                ];
            }
        }

        return $meetings;
    }
}
