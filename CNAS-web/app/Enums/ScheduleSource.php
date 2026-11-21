<?php

namespace App\Enums;

/**
 * Where a class schedule row came from. A COR import replaces only the Cor rows; a Cor row the student edits
 * becomes Manual, so a later import keeps their change.
 */
enum ScheduleSource: string
{
    case Cor = 'cor';
    case Manual = 'manual';
}
