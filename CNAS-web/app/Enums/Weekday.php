<?php

namespace App\Enums;

use Carbon\CarbonInterface;

enum Weekday: string
{
    case Mon = 'mon';
    case Tue = 'tue';
    case Wed = 'wed';
    case Thu = 'thu';
    case Fri = 'fri';
    case Sat = 'sat';
    case Sun = 'sun';

    /** Single letters used in combined days on a COR ("MW", "TTH", "MWF"). */
    private const LETTERS = ['th' => self::Thu, 'su' => self::Sun, 'm' => self::Mon, 't' => self::Tue, 'w' => self::Wed, 'f' => self::Fri, 's' => self::Sat];

    public static function fromDate(CarbonInterface $date): self
    {
        return self::cases()[$date->dayOfWeekIso - 1];
    }

    /**
     * The days in a COR's Day column: "Thu", "THU", "Mon/Wed", "MW", "TTH". Empty when it isn't recognised.
     *
     * @return list<self>
     */
    public static function fromCorText(string $text): array
    {
        $days = [];

        foreach (explode('/', strtolower(trim($text))) as $part) {
            $day = self::tryFrom(substr($part, 0, 3));

            // A day name ("thu", "thurs"), or failing that single letters ("tth").
            if ($day !== null && str_starts_with(strtolower($day->label()), $part)) {
                $days[] = $day;

                continue;
            }

            if (preg_match('/^(?:th|su|[mtwfs])+$/', $part) !== 1) {
                return [];
            }

            preg_match_all('/th|su|[mtwfs]/', $part, $letters);

            foreach ($letters[0] as $letter) {
                $days[] = self::LETTERS[$letter];
            }
        }

        return array_values(array_unique($days, SORT_REGULAR));
    }

    public function label(): string
    {
        return match ($this) {
            self::Mon => 'Monday',
            self::Tue => 'Tuesday',
            self::Wed => 'Wednesday',
            self::Thu => 'Thursday',
            self::Fri => 'Friday',
            self::Sat => 'Saturday',
            self::Sun => 'Sunday',
        };
    }

    /**
     * 1 (Monday) to 7 (Sunday), for sorting.
     */
    public function number(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
