<?php

namespace App\Enums;

enum Semester: string
{
    case First = '1st';
    case Second = '2nd';
    case Summer = 'summer';

    /**
     * Spellings seen (or expected) on CORs, lower-cased with single spaces.
     */
    private const SPELLINGS = [
        '1st' => self::First,
        '1st term' => self::First,
        '1st sem' => self::First,
        '1st semester' => self::First,
        'first semester' => self::First,
        '2nd' => self::Second,
        '2nd term' => self::Second,
        '2nd sem' => self::Second,
        '2nd semester' => self::Second,
        'second semester' => self::Second,
        'summer' => self::Summer,
        'summer term' => self::Summer,
        'midyear' => self::Summer,
    ];

    /**
     * Normalise the semester text printed on a COR. Null when it isn't recognised.
     */
    public static function fromCorText(?string $text): ?self
    {
        if ($text === null) {
            return null;
        }

        $key = strtolower(trim(preg_replace('/\s+/', ' ', $text) ?? ''));

        return self::SPELLINGS[$key] ?? null;
    }

    public function label(): string
    {
        return match ($this) {
            self::First => '1st Semester',
            self::Second => '2nd Semester',
            self::Summer => 'Summer',
        };
    }
}
