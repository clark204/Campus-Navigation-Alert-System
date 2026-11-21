<?php

namespace App\Enums;

/**
 * What a concern sent from Help & contact is about, so the admin can sort them at a glance.
 */
enum ConcernTopic: string
{
    case Account = 'account';
    case Schedule = 'schedule';
    case Map = 'map';
    case Alerts = 'alerts';
    case Suggestion = 'suggestion';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Account => 'My account or signing in',
            self::Schedule => 'My class schedule',
            self::Map => 'The map or directions',
            self::Alerts => 'Emergency alerts',
            self::Suggestion => 'A suggestion',
            self::Other => 'Something else',
        };
    }

    /**
     * Short name for the admin inbox.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Account => 'Account',
            self::Schedule => 'Schedule',
            self::Map => 'Map',
            self::Alerts => 'Alerts',
            self::Suggestion => 'Suggestion',
            self::Other => 'Other',
        };
    }
}
