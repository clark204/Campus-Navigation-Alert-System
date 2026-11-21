<?php

namespace App\Services\Cor;

use App\Models\AcademicTerm;

/**
 * Checks a ParsedCor against the current academic term. Pure logic: no database, no files.
 * Every check is pass/fail; RegistrationService rejects the upload if any fails.
 */
class CorValidator
{
    /** @var list<string> */
    private const HEADER_LINES = [
        'Tagoloan Community College',
        'Baluarte, Tagoloan, Misamis Oriental',
        'Certificate of Registration',
    ];

    /** @var list<string> */
    private const LABELS = ['Student ID', 'Name', 'AY', 'Program', 'Total Units', 'Date Enrolled'];

    /**
     * @return list<CorCheck> The failed checks. An empty list means the COR passed everything.
     */
    public function validate(ParsedCor $cor, AcademicTerm $currentTerm): array
    {
        $results = [
            CorCheck::Header->value => $this->hasHeader($cor->text),
            CorCheck::Labels->value => $this->hasLabels($cor->text),
            CorCheck::StudentIdFormat->value => preg_match('/^\d{8}$/', (string) $cor->studentId) === 1,
            CorCheck::Semester->value => $cor->semester !== null,
            CorCheck::AcademicTerm->value => $cor->academicYear === $currentTerm->academic_year
                && $cor->semester === $currentTerm->semester,
            CorCheck::TotalUnits->value => $this->totalUnitsMatch($cor),
            CorCheck::DateEnrolled->value => $cor->dateEnrolled !== null,
            CorCheck::CopiesMatch->value => $this->copiesMatch($cor->copies),
            CorCheck::KnownProgram->value => $cor->programCode !== null,
        ];

        return array_values(array_map(
            fn (string $check) => CorCheck::from($check),
            array_keys(array_filter($results, fn (bool $passed) => ! $passed)),
        ));
    }

    private function hasHeader(string $text): bool
    {
        foreach (self::HEADER_LINES as $line) {
            if (! str_contains($text, $line)) {
                return false;
            }
        }

        return true;
    }

    private function hasLabels(string $text): bool
    {
        foreach (self::LABELS as $label) {
            if (preg_match('/\b'.preg_quote($label, '/').'\s*:/', $text) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function totalUnitsMatch(ParsedCor $cor): bool
    {
        if ($cor->totalUnits === null || $cor->subjects === []) {
            return false;
        }

        $sum = array_sum(array_column($cor->subjects, 'units'));

        return abs($sum - $cor->totalUnits) < 0.001;
    }

    /**
     * @param  list<array{student_id: ?string, name: ?string, academic_year: ?string, program: ?string}>  $copies
     */
    private function copiesMatch(array $copies): bool
    {
        if (count($copies) < 2) {
            return false;
        }

        foreach ($copies as $copy) {
            if ($copy !== $copies[0] || in_array(null, $copy, true)) {
                return false;
            }
        }

        return true;
    }
}
