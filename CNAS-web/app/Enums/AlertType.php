<?php

namespace App\Enums;

/**
 * Kinds of emergency. Each has a ready-made title, message and instructions so an admin can send an alert fast.
 */
enum AlertType: string
{
    case Fire = 'fire';
    case Earthquake = 'earthquake';
    case Flood = 'flood';
    case Typhoon = 'typhoon';
    case Security = 'security';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Fire => 'Fire',
            self::Earthquake => 'Earthquake',
            self::Flood => 'Flood',
            self::Typhoon => 'Typhoon',
            self::Security => 'Security threat',
            self::Other => 'Other emergency',
        };
    }

    /**
     * 24×24 stroke icon (SVG path data) for the type picker, the takeover and the banner.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Fire => 'M12 3c.4 3.6 5 5.6 5 10.5a5 5 0 0 1-10 0c0-2.3 1.1-4 2.4-5.2.2 2 1.1 3.1 2.3 3.5.3-3.4-1.3-5.8.3-8.8z',
            self::Earthquake => 'M2 12h4l2-5 3 10 3-14 3 13 2-4h3',
            self::Flood => 'M3 9.5c1.5 0 2.3-1.5 4.5-1.5S9.8 9.5 12 9.5s2.3-1.5 4.5-1.5S18.8 9.5 21 9.5M3 14.5c1.5 0 2.3-1.5 4.5-1.5s2.3 1.5 4.5 1.5 2.3-1.5 4.5-1.5 2.3 1.5 4.5 1.5M3 19.5c1.5 0 2.3-1.5 4.5-1.5s2.3 1.5 4.5 1.5 2.3-1.5 4.5-1.5 2.3 1.5 4.5 1.5',
            self::Typhoon => 'M12 9.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM9.6 11.3C9 7 11.5 4 16.5 3M14.4 12.7C15 17 12.5 20 7.5 21',
            self::Security => 'M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6zM12 8v4.5m0 3.5h.01',
            self::Other => 'M12 3 2 20h20zM12 10v4m0 3h.01',
        };
    }

    /**
     * Storms and earthquakes hit the whole campus, so the send form starts without a pin for them.
     */
    public function isCampusWide(): bool
    {
        return in_array($this, [self::Typhoon, self::Earthquake], true);
    }

    /**
     * The level preselected when this type is chosen: fire, earthquake and security are emergencies; flood and typhoon
     * are warnings; other is an advisory. The admin can change it.
     */
    public function defaultSeverity(): AlertSeverity
    {
        return match ($this) {
            self::Fire, self::Earthquake, self::Security => AlertSeverity::Emergency,
            self::Flood, self::Typhoon => AlertSeverity::Warning,
            self::Other => AlertSeverity::Advisory,
        };
    }

    /**
     * Starting text for the send form; the admin edits it before sending.
     *
     * @return array{title: string, message: string, instructions: string}
     */
    public function template(): array
    {
        return match ($this) {
            self::Fire => [
                'title' => 'Fire on campus',
                'message' => 'A fire has been reported on campus.',
                'instructions' => 'Leave the building now by the nearest exit and go to the evacuation area. Stay low if there is smoke. Do not go back inside.',
            ],
            self::Earthquake => [
                'title' => 'Earthquake',
                'message' => 'An earthquake has hit the area.',
                'instructions' => 'Duck, cover and hold until the shaking stops. Then leave the building calmly, keep away from windows and walls, and go to the evacuation area. Expect aftershocks.',
            ],
            self::Flood => [
                'title' => 'Flooding on campus',
                'message' => 'Flooding has been reported on campus.',
                'instructions' => 'Move to an upper floor or higher ground. Do not walk or drive through floodwater. Keep away from electrical outlets and wires.',
            ],
            self::Typhoon => [
                'title' => 'Typhoon warning',
                'message' => 'A typhoon is affecting the area.',
                'instructions' => 'Stay indoors and away from windows. Watch for announcements about class suspension. Do not travel until it is safe.',
            ],
            self::Security => [
                'title' => 'Security threat on campus',
                'message' => 'A security threat has been reported on campus.',
                'instructions' => 'Stay where you are. Lock or block the door, turn off the lights, silence your phone and stay out of sight. Wait for instructions from security.',
            ],
            self::Other => [
                'title' => 'Emergency alert',
                'message' => '',
                'instructions' => '',
            ],
        };
    }
}
