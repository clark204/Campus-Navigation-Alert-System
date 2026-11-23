<?php

namespace App\Enums;

/**
 * Where an "I need help" request is (SafetyCheck::helpStatus): just asked, an admin is on the way, or helped.
 * Worked out from the safety check's responder_id and helped_at; not stored.
 */
enum HelpStatus: string
{
    case New = 'new';
    case Responding = 'responding';
    case Helped = 'helped';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Waiting for help',
            self::Responding => 'On the way',
            self::Helped => 'Helped',
        };
    }

    public function isOpen(): bool
    {
        return $this !== self::Helped;
    }
}
