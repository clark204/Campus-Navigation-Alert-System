<?php

namespace App\Services\Cor;

use App\Enums\Semester;
use App\Enums\Weekday;
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
     * A meeting anywhere on a line: days ("Thu", "Mon/Wed", "TTH", or glued to the units as in "3Fri"), start and
     * end time with one or two AM/PM ("04:00-07:00 PM", "04:00 PM 07:00 PM"), and the room up to the next column.
     */
    private const MEETING = '/(?<![A-Za-z])(?<days>[A-Za-z]{1,6}(?:\/[A-Za-z]{1,6})*)\s+(?<start>\d{1,2}:\d{2})\s*(?<startMeridiem>[AP]M)?\s*-?\s*(?<end>\d{1,2}:\d{2})\s*(?<endMeridiem>[AP]M)\s+(?<room>\S+(?: \S+)*)/i';

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
            programCode: $this->knownProgramCode($program),
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
    /**
     * The program code when it is one of the programs in the database, otherwise null.
     */
    private function knownProgramCode(?string $program): ?string
    {
        return $program !== null && Program::whereKey($program)->exists() ? $program : null;
    }

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
     * per course, each with its meetings (one per day: "Mon/Wed" is two).
     *
     * A meeting is on the subject's own row or on a line of its own. In the smalot text those
     * lines follow the subject row. In the pdftotext text the subject row sits in the middle of
     * its lines, so a line that has no subject to go to waits for the next subject row, and that
     * subject then takes as many lines after its row as it had before it.
     *
     * @return list<array{course: string, section: string, units: float, meetings: list<array{day: string, starts_at: string, ends_at: string, room: string}>}>
     */
    private function subjects(string $copy): array
    {
        $subjects = [];
        $inTable = false;
        $current = null;
        $waiting = [];
        $linesAfterRow = INF;

        foreach (preg_split('/\R/', $copy) ?: [] as $rawLine) {
            $line = $this->collapse($rawLine);

            if (preg_match('/^(Course|Subject) Section\b/', $line) === 1) {
                $inTable = true;

                continue;
            }

            if (str_starts_with($line, 'Total Units')) {
                break;
            }

            if (! $inTable) {
                continue;
            }

            $meetings = $this->meetings($rawLine);

            if (preg_match(self::SUBJECT_ROW, $line, $row) !== 1) {
                if ($current !== null && $linesAfterRow > 0) {
                    array_push($subjects[$current]['meetings'], ...$meetings);
                    $linesAfterRow--;
                } else {
                    array_push($waiting, ...$meetings);
                }

                continue;
            }

            $current = $row['course'];

            // A row without units is an extra schedule line of a course already listed (older layout).
            if (isset($subjects[$current])) {
                array_push($subjects[$current]['meetings'], ...$meetings);

                continue;
            }

            if (($row['units'] ?? '') === '') {
                $current = null;

                continue;
            }

            // A subject with its meeting on its own row has no other lines; one with lines before
            // its row gets as many after it; otherwise every following line is its own.
            $linesAfterRow = match (true) {
                $meetings !== [] => 0,
                $waiting !== [] => count($waiting),
                default => INF,
            };

            $subjects[$current] = [
                'course' => $row['course'],
                'section' => $row['section'],
                'units' => (float) $row['units'],
                'meetings' => [...$waiting, ...$meetings],
            ];
            $waiting = [];
        }

        return array_values($subjects);
    }

    /**
     * The meetings on one line of the subject table: day(s), time and room, e.g.
     * "Thu 04:00-07:00 PM CompLab1", "3Mon/Wed 02:30-04:00 PM IT 204" or "TTH 08:30 AM 10:00 AM NEE 204".
     * One per day. The room ends at a tab or a wider gap, where the next column starts.
     *
     * @return list<array{day: string, starts_at: string, ends_at: string, room: string}>
     */
    private function meetings(string $rawLine): array
    {
        $line = trim(str_replace("\t", '  ', $rawLine));

        if (preg_match(self::MEETING, $line, $parts) !== 1) {
            return [];
        }

        $end = $this->to24Hour($parts['end'], $parts['endMeridiem']);
        $start = $this->to24Hour($parts['start'], $parts['startMeridiem'] ?: $parts['endMeridiem']);

        // "10:00-01:00 PM" prints one AM/PM for both times: a start after the end is the morning.
        if ($parts['startMeridiem'] === '' && $start >= $end) {
            $start = $this->to24Hour($parts['start'], 'AM');
        }

        return array_map(fn (Weekday $day) => [
            'day' => $day->value,
            'starts_at' => $start,
            'ends_at' => $end,
            'room' => $parts['room'],
        ], Weekday::fromCorText($parts['days']));
    }

    /**
     * "04:00" + "PM" is "16:00".
     */
    private function to24Hour(string $time, string $meridiem): string
    {
        [$hour, $minute] = explode(':', $time);
        $hour = (int) $hour % 12 + (strtoupper($meridiem) === 'PM' ? 12 : 0);

        return sprintf('%02d:%s', $hour, $minute);
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
