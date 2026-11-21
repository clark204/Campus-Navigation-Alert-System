<?php

namespace App\Enums;

/**
 * How an alert ended: the danger is over, or there never was one.
 */
enum AlertResolution: string
{
    case AllClear = 'all_clear';
    case FalseAlarm = 'false_alarm';

    public function label(): string
    {
        return match ($this) {
            self::AllClear => 'All clear',
            self::FalseAlarm => 'False alarm',
        };
    }

    /**
     * Starting text for the resolve form; the admin can change it.
     */
    public function defaultMessage(bool $isDrill): string
    {
        return match (true) {
            $isDrill => 'The drill is over. Thank you for taking part.',
            $this === self::FalseAlarm => 'False alarm. There is no emergency. You can go back to what you were doing.',
            default => 'All clear. It is safe to go back to your classes.',
        };
    }
}
