<?php

namespace App\Enums;

/**
 * What a shape on the campus map is. Only Building rows can hold rooms; Building and Facility can be named in an alert.
 */
enum BuildingType: string
{
    case Campus = 'campus';
    case Building = 'building';
    case Facility = 'facility';
    case Evacuation = 'evacuation';

    public function label(): string
    {
        return match ($this) {
            self::Campus => 'Campus outline',
            self::Building => 'Building',
            self::Facility => 'Facility',
            self::Evacuation => 'Evacuation area',
        };
    }

    /**
     * Whether this kind of shape has floors (the campus outline and the evacuation area do not).
     */
    public function hasFloors(): bool
    {
        return $this === self::Building || $this === self::Facility;
    }
}
