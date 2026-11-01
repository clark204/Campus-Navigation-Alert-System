<?php

namespace App\Services\Cor;

use App\Enums\Semester;
use App\Models\Program;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Reads a TCC Certificate of Registration PDF into a ParsedCor.
 *
 * Handles both COR layouts seen so far (see tests/Fixtures/cor/README.md):
 * - current: "Course" header, section "BSIT3B", units sometimes glued to the day ("3Fri")
 * - older: "Subject" header, section "BSIT 2B", extra schedule rows repeat the course
 *   without units, program "BSIT 2nd", AY "2025-2026 2nd Term", date "01/07/2026"
 *
 * The COR is printed twice on one page; the first copy is parsed in full and the key
 * fields of every copy are kept so CorValidator can compare them. Labels are matched on
 * whitespace-collapsed text; subject rows are matched line by line. The QR code is ignored.
 */
class CorExtractor
{
    private const HEADER = 'Tagoloan Community College';

    /** Surname particles that belong to the last name ("Dela Cruz", "De los Santos", "San Juan"). */
    private const SURNAME_PARTICLES = ['de', 'del', 'dela', 'della', 'delos', 'la', 'las', 'los', 'san', 'santa', 'sta', 'sta.', 'sto', 'sto.', 'santo', 'da', 'di', 'du', 'van', 'von', 'mac', 'mc', 'y'];

    private const NAME_SUFFIXES = ['jr', 'jr.', 'sr', 'sr.', 'ii', 'iii', 'iv', 'v'];

    private const MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december'];

    /**
     * A subject row: course ("IT 119", "IT Elec 2", "PATH FIT 4"), section ("BSIT3B" or "BSIT 2B"),
     * then units, which may be glued to the day ("3Fri") and are absent on repeated schedule rows.
     */
    private const SUBJECT_ROW = '/^(?<course>[A-Z][A-Za-z]*(?: [A-Za-z]+){0,2} \d{1,3}[A-Z]?) (?<section>[A-Z]{2,} ?\d{1,2}[A-Z]{0,2})(?: ?(?<units>\d{1,2}(?:\.\d{1,2})?)(?![\d.:]))?/';

    /**
     * @throws CorParseException when the file is not a readable PDF or has no text.
     */
    public function extract(string $path): ParsedCor
    {
        $config = new Config;
        $config->setRetainImageContent(false);

        try {
            $text = (new Parser([], $config))->parseFile($path)->getText();
        } catch (Throwable $exception) {
            throw new CorParseException('The PDF could not be read.', previous: $exception);
        }

        if (trim($text) === '') {
            throw new CorParseException('The PDF has no text layer.');
        }

        return $this->parseText($text);
    }

    /**
     * Parse COR text that is already extracted. Also used directly by tests.
     */
    public function parseText(string $text): ParsedCor
    {
        $copies = $this->splitCopies($text);
        $first = $this->collapse($copies[0]);

        $fullName = $this->name($first);
        [$firstName, $middleName, $lastName] = $this->splitName($fullName);
        $program = $this->program($first);
        $semesterText = $this->match('/\bAY\s*:\s*\S+\s+(.{1,40}?)\s+Name\s*:/', $first);
        $totalUnits = $this->match('/\bTotal Units\s*:\s*(\d+(?:\.\d+)?)/', $first);

        return new ParsedCor(
            text: $first,
            studentId: $this->studentId($first),
            fullName: $fullName,
            firstName: $firstName,
            middleName: $middleName,
            lastName: $lastName,
            program: $program,
            collegeId: $program === null ? null : Program::find($program)?->college_id,
            academicYear: $this->match('/\bAY\s*:\s*(\S+)/', $first),
            semesterText: $semesterText,
            semester: Semester::fromCorText($semesterText),
            dateEnrolled: $this->dateEnrolled($first),
            subjects: $this->subjects($copies[0]),
            totalUnits: $totalUnits === null ? null : (float) $totalUnits,
            copies: array_map(function (string $copy) {
                $copy = $this->collapse($copy);

                return [
                    'student_id' => $this->studentId($copy),
                    'name' => $this->name($copy),
                    'academic_year' => $this->match('/\bAY\s*:\s*(\S+\s+.{1,40}?)\s+Name\s*:/', $copy),
                    'program' => $this->program($copy),
                ];
            }, $copies),
        );
    }

    private function collapse(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * Each printed copy starts with the school name. Text before the first header (e.g. a
     * printed date) is dropped. Line breaks are kept for the subject table.
     *
     * @return non-empty-list<string>
     */
    private function splitCopies(string $text): array
    {
        $copies = array_values(array_filter(
            preg_split('/(?='.preg_quote(self::HEADER, '/').')/', $text) ?: [],
            fn (string $copy) => str_starts_with($copy, self::HEADER),
        ));

        return $copies === [] ? [$text] : $copies;
    }

    private function match(string $pattern, string $text): ?string
    {
        return preg_match($pattern, $text, $matches) === 1 ? trim($matches[1]) : null;
    }

    private function studentId(string $copy): ?string
    {
        return $this->match('/\bStudent ID\s*:\s*(\S+)/', $copy);
    }

    private function name(string $copy): ?string
    {
        return $this->match('/\bName\s*:\s*(.+?)\s+Program\s*:/', $copy);
    }

    /**
     * The first word only: "BSIT 2nd" (older layout includes the year level) is BSIT.
     */
    private function program(string $copy): ?string
    {
        $program = $this->match('/\bProgram\s*:\s*([A-Za-z]+)/', $copy);

        return $program === null ? null : strtoupper($program);
    }

    /**
     * "July 08, 2026" or "01/07/2026" (MM/DD/YYYY), as Y-m-d. Null when missing or impossible.
     */
    private function dateEnrolled(string $copy): ?string
    {
        if (preg_match('/\bDate Enrolled\s*:\s*([A-Za-z]+)\.?\s+(\d{1,2}),\s*(\d{4})/', $copy, $parts) === 1) {
            $month = array_search(strtolower($parts[1]), self::MONTHS, true);
            [$month, $day, $year] = [$month === false ? 0 : $month + 1, (int) $parts[2], (int) $parts[3]];
        } elseif (preg_match('/\bDate Enrolled\s*:\s*(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $copy, $parts) === 1) {
            [$month, $day, $year] = [(int) $parts[1], (int) $parts[2], (int) $parts[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * Subject rows between the "Course/Subject Section" header and "Total Units", one entry
     * per course. Rows without units are extra schedule lines of the course above.
     *
     * @return list<array{course: string, section: string, units: float}>
     */
    private function subjects(string $copy): array
    {
        $subjects = [];
        $inTable = false;

        foreach (preg_split('/\R/', $copy) ?: [] as $line) {
            $line = $this->collapse($line);

            if (preg_match('/^(Course|Subject) Section\b/', $line) === 1) {
                $inTable = true;

                continue;
            }

            if (str_starts_with($line, 'Total Units')) {
                break;
            }

            if (! $inTable || preg_match(self::SUBJECT_ROW, $line, $row) !== 1) {
                continue;
            }

            if (($row['units'] ?? '') === '' || isset($subjects[$row['course']])) {
                continue;
            }

            $subjects[$row['course']] = [
                'course' => $row['course'],
                'section' => $row['section'],
                'units' => (float) $row['units'],
            ];
        }

        return array_values($subjects);
    }

    /**
     * Best guess at "First Names Last Name M" (the student confirms it): a trailing single
     * letter is the middle initial, the last word plus any surname particles before it is
     * the last name, and the rest are first names.
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} first, middle, last
     */
    private function splitName(?string $fullName): array
    {
        $words = $fullName === null ? [] : explode(' ', $fullName);

        $middle = null;
        if (count($words) > 1 && preg_match('/^\p{L}\.?$/u', end($words)) === 1) {
            $middle = rtrim(array_pop($words), '.');
        }

        $suffix = null;
        if (count($words) > 1 && in_array(mb_strtolower(end($words)), self::NAME_SUFFIXES, true)) {
            $suffix = array_pop($words);
        }

        if ($words === []) {
            return [null, $middle, null];
        }

        $last = [array_pop($words)];
        while (count($words) > 1 && in_array(mb_strtolower(end($words)), self::SURNAME_PARTICLES, true)) {
            array_unshift($last, array_pop($words));
        }

        if ($suffix !== null) {
            $last[] = $suffix;
        }

        return [$words === [] ? null : implode(' ', $words), $middle, implode(' ', $last)];
    }
}
