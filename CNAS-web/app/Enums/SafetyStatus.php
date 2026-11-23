<?php

namespace App\Enums;

/**
 * A user's answer to the safety check during an emergency: "I'm safe", or "I need help" (with an optional note for the
 * administrators, who get a push for it). Answering again replaces the earlier answer.
 */
enum SafetyStatus: string
{
    case Safe = 'safe';
    case NeedHelp = 'need_help';

    public function label(): string
    {
        return match ($this) {
            self::Safe => 'Safe',
            self::NeedHelp => 'Needs help',
        };
    }
}
