<?php

namespace App\Enums;

/**
 * A photo of a building or room: a normal picture, or a 360° photo to look around in.
 */
enum PhotoKind: string
{
    case Photo = 'photo';
    case Panorama = 'panorama';

    public function label(): string
    {
        return match ($this) {
            self::Photo => 'Normal photo',
            self::Panorama => '360 photo',
        };
    }
}
