<?php

namespace Tests\Unit;

use App\Enums\DeviceType;
use App\Services\DeviceDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class DeviceDetectorTest extends TestCase
{
    /**
     * Real user agents, mostly from phones common on campus.
     *
     * @return array<string, array{string, string, DeviceType}>
     */
    public static function userAgents(): array
    {
        return [
            'Chrome on Windows' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36',
                'Chrome on Windows', DeviceType::Desktop,
            ],
            'Edge on Windows (also claims Chrome)' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0',
                'Edge on Windows', DeviceType::Desktop,
            ],
            'Opera on Windows (also claims Chrome)' => [
                'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 OPR/115.0.0.0',
                'Opera on Windows', DeviceType::Desktop,
            ],
            'Safari on iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
                'Safari on iPhone', DeviceType::Phone,
            ],
            'Chrome on iPhone' => [
                'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0.6723.90 Mobile/15E148 Safari/604.1',
                'Chrome on iPhone', DeviceType::Phone,
            ],
            'Safari on iPad' => [
                'Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
                'Safari on iPad', DeviceType::Tablet,
            ],
            'Chrome on an Android phone' => [
                'Mozilla/5.0 (Linux; Android 14; SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36',
                'Chrome on Android', DeviceType::Phone,
            ],
            'Chrome on an Android tablet (no "Mobile")' => [
                'Mozilla/5.0 (Linux; Android 14; SM-X210) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36',
                'Chrome on Android', DeviceType::Tablet,
            ],
            'Samsung Internet on Android' => [
                'Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-A155F) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
                'Samsung Internet on Android', DeviceType::Phone,
            ],
            'Facebook in-app browser on Android' => [
                'Mozilla/5.0 (Linux; Android 13; RMX3630 Build/TP1A.220905.001; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/129.0.6668.100 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/485.0.0.40.62;]',
                'Facebook on Android', DeviceType::Phone,
            ],
            'Firefox on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:132.0) Gecko/20100101 Firefox/132.0',
                'Firefox on macOS', DeviceType::Desktop,
            ],
            'Safari on macOS' => [
                'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Safari/605.1.15',
                'Safari on macOS', DeviceType::Desktop,
            ],
            'Firefox on Linux' => [
                'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0',
                'Firefox on Linux', DeviceType::Desktop,
            ],
        ];
    }

    #[DataProvider('userAgents')]
    public function test_it_names_the_browser_system_and_kind_of_device(string $userAgent, string $description, DeviceType $type): void
    {
        $detector = new DeviceDetector;

        $this->assertSame($description, $detector->describe($userAgent));
        $this->assertSame($type, $detector->type($userAgent));
    }

    #[TestWith(['okhttp/4.12.0'])]
    #[TestWith(['Dart/3.5 (dart:io)'])]
    #[TestWith([''])]
    #[TestWith([null])]
    public function test_an_unrecognised_user_agent_has_no_description(?string $userAgent): void
    {
        $detector = new DeviceDetector;

        $this->assertNull($detector->describe($userAgent));
        $this->assertSame(DeviceType::Unknown, $detector->type($userAgent));
    }
}
