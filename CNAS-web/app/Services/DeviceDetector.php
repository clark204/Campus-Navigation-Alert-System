<?php

namespace App\Services;

use App\Enums\DeviceType;

/**
 * Reads the browser, operating system and kind of device from a User-Agent header, for the admin's
 * sign-in history ("Chrome on Windows", "Safari on iPhone"). Covers the browsers and systems students
 * and staff actually use; anything else reads as "Unknown". Order matters: many browsers also claim
 * to be Chrome or Safari, so the specific ones are checked first.
 */
class DeviceDetector
{
    /**
     * First match wins.
     *
     * @var array<string, string>
     */
    private const BROWSERS = [
        '/FBAN|FBAV|FB_IAB/' => 'Facebook',
        '/Instagram/' => 'Instagram',
        '/EdgA?\/|EdgiOS\//' => 'Edge',
        '/OPR\/|Opera|OPiOS\//' => 'Opera',
        '/SamsungBrowser\//' => 'Samsung Internet',
        '/UCBrowser\//' => 'UC Browser',
        '/Firefox\/|FxiOS\//' => 'Firefox',
        '/CriOS\/|Chrome\/|Chromium\//' => 'Chrome',
        '/Version\/[\d.]+.*Safari\//' => 'Safari',
    ];

    /**
     * First match wins. iPhone and iPad before Mac (their user agents say "like Mac OS X"), Android before Linux.
     *
     * @var array<string, string>
     */
    private const SYSTEMS = [
        '/iPhone|iPod/' => 'iPhone',
        '/iPad/' => 'iPad',
        '/Android/' => 'Android',
        '/Windows/' => 'Windows',
        '/CrOS/' => 'ChromeOS',
        '/Macintosh|Mac OS X/' => 'macOS',
        '/Linux/' => 'Linux',
    ];

    /**
     * @return array{browser: ?string, os: ?string, type: DeviceType}
     */
    public function detect(?string $userAgent): array
    {
        $userAgent = (string) $userAgent;

        return [
            'browser' => $this->firstMatch(self::BROWSERS, $userAgent),
            'os' => $this->firstMatch(self::SYSTEMS, $userAgent),
            'type' => $this->type($userAgent),
        ];
    }

    /**
     * "Chrome on Windows", "Safari on iPhone"; null when neither the browser nor the system is recognised.
     */
    public function describe(?string $userAgent): ?string
    {
        ['browser' => $browser, 'os' => $os] = $this->detect($userAgent);

        return match (true) {
            $browser !== null && $os !== null => "{$browser} on {$os}",
            $browser !== null => $browser,
            $os !== null => $os,
            default => null,
        };
    }

    public function type(?string $userAgent): DeviceType
    {
        $userAgent = (string) $userAgent;

        return match (true) {
            (bool) preg_match('/iPad|Tablet/', $userAgent) => DeviceType::Tablet,
            // Android phones send "Mobile"; Android tablets don't.
            (bool) preg_match('/Android/', $userAgent) => str_contains($userAgent, 'Mobile') ? DeviceType::Phone : DeviceType::Tablet,
            (bool) preg_match('/Mobi|iPhone|iPod/', $userAgent) => DeviceType::Phone,
            (bool) preg_match('/Windows|Macintosh|CrOS|X11|Linux/', $userAgent) => DeviceType::Desktop,
            default => DeviceType::Unknown,
        };
    }

    /**
     * @param  array<string, string>  $patterns
     */
    private function firstMatch(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $pattern => $name) {
            if (preg_match($pattern, $userAgent)) {
                return $name;
            }
        }

        return null;
    }
}
