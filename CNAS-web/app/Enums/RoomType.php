<?php

namespace App\Enums;

enum RoomType: string
{
    case Classroom = 'classroom';
    case Lab = 'lab';
    case Office = 'office';
    case Cr = 'cr';

    public function label(): string
    {
        return match ($this) {
            self::Classroom => 'Classroom',
            self::Lab => 'Laboratory',
            self::Office => 'Office',
            self::Cr => 'Comfort room',
        };
    }
}
