<?php

namespace App\Enums;

/**
 * The kind of device a user agent belongs to (see App\Services\DeviceDetector).
 */
enum DeviceType: string
{
    case Phone = 'phone';
    case Tablet = 'tablet';
    case Desktop = 'desktop';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Tablet => 'Tablet',
            self::Desktop => 'Desktop',
            self::Unknown => 'Unknown device',
        };
    }
}
