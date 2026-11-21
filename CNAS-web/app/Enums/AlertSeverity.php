<?php

namespace App\Enums;

/**
 * How serious an alert is. The admin forms say when to choose each level (description, example); studentsGet() says
 * what students receive, so the forms and any other page use the same wording.
 */
enum AlertSeverity: string
{
    case Advisory = 'advisory';
    case Warning = 'warning';
    case Emergency = 'emergency';

    public function label(): string
    {
        return match ($this) {
            self::Advisory => 'Advisory',
            self::Warning => 'Warning',
            self::Emergency => 'Emergency',
        };
    }

    /**
     * The color that marks this level in the admin console (the banner colors live in the banner partial).
     */
    public function color(): string
    {
        return match ($this) {
            self::Advisory => '#facc15',
            self::Warning => '#ea580c',
            self::Emergency => '#b3121b',
        };
    }

    /**
     * When to choose this level.
     */
    public function description(): string
    {
        return match ($this) {
            self::Advisory => 'Be aware. No danger right now.',
            self::Warning => 'Get ready. Danger may reach people soon.',
            self::Emergency => 'Act now. People are in danger.',
        };
    }

    /**
     * A typical case for this level, shown after "e.g.".
     */
    public function example(): string
    {
        return match ($this) {
            self::Advisory => 'Heavy rain, power cut, a gate is closed.',
            self::Warning => 'Rising flood water, strong typhoon winds.',
            self::Emergency => 'Fire, earthquake, security threat.',
        };
    }

    /**
     * What students receive for this level, in the words the admin forms show.
     */
    public function studentsGet(): string
    {
        return match ($this) {
            self::Advisory => 'Students get: a yellow banner and a notification.',
            self::Warning => 'Students get: an orange banner, a popup with a chime, and a notification.',
            self::Emergency => 'Students get: a full-screen alarm, the I\'m safe button, the route to safety, and a notification.',
        };
    }

    /**
     * Only an emergency takes over the screen, asks "I'm safe" and sounds the alarm.
     */
    public function isEmergency(): bool
    {
        return $this === self::Emergency;
    }
}
