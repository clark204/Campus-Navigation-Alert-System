<?php

namespace App\Enums;

enum PathType: string
{
    case Walkway = 'walkway';
    case Gate = 'gate';

    public function label(): string
    {
        return match ($this) {
            self::Walkway => 'Walkway',
            self::Gate => 'Gate',
        };
    }
}
