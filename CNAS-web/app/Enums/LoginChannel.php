<?php

namespace App\Enums;

/**
 * How a user signed in: the website (session) or the mobile app (Sanctum token).
 */
enum LoginChannel: string
{
    case Web = 'web';
    case Api = 'api';

    public function label(): string
    {
        return match ($this) {
            self::Web => 'Web',
            self::Api => 'Mobile app',
        };
    }
}
