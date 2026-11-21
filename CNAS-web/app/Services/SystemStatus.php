<?php

namespace App\Services;

use App\Enums\BuildingType;
use App\Models\AcademicTerm;
use App\Models\Building;
use App\Models\Path;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\PushSubscription;

/**
 * The read-only checks under "System status" on /admin/settings. Each one says OK or Needs attention, with one plain
 * sentence on how to fix it. Never shows a secret (the push keys are only checked for being set).
 */
class SystemStatus
{
    /** The push settings that must be set in .env, by config key. */
    private const PUSH_KEYS = [
        'webpush.vapid.public_key' => 'VAPID_PUBLIC_KEY',
        'webpush.vapid.private_key' => 'VAPID_PRIVATE_KEY',
        'webpush.vapid.subject' => 'VAPID_SUBJECT',
    ];

    /**
     * Keyed so the page can find a check (push-subscribers updates itself while the tab is open).
     *
     * @return array<string, StatusCheck>
     */
    public function checks(Request $request): array
    {
        return [
            'push-keys' => $this->pushKeys(),
            'https' => $this->https($request),
            'academic-term' => $this->academicTerm(),
            'map-data' => $this->mapData(),
            'push-subscribers' => $this->pushSubscribers(),
        ];
    }

    public function pushKeys(): StatusCheck
    {
        $name = 'Push keys';
        $missing = collect(self::PUSH_KEYS)->filter(fn (string $env, string $key) => blank(config($key)))->values();

        if ($missing->isEmpty()) {
            return new StatusCheck($name, true, 'VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY and VAPID_SUBJECT are set.');
        }

        return new StatusCheck(
            $name,
            false,
            'Not set in .env: '.$missing->join(', ', ' and ').'.',
            $missing->contains('VAPID_PUBLIC_KEY') || $missing->contains('VAPID_PRIVATE_KEY')
                ? 'Run “php artisan webpush:vapid” once to add the keys to .env (never regenerate them), then “php artisan config:clear”.'
                : 'Set VAPID_SUBJECT in .env to “mailto:” plus the admin email or to the https site address, then run “php artisan config:clear”.',
        );
    }

    public function https(Request $request): StatusCheck
    {
        $name = 'HTTPS';

        if ($request->isSecure()) {
            return new StatusCheck($name, true, 'This page was served over https, so push alerts and phone sensors can work.');
        }

        return new StatusCheck(
            $name,
            false,
            'This page was served over plain http, so browsers block push alerts and phone sensors.',
            'Open the site through its https address (for example the ngrok tunnel) and set APP_URL in .env to it.',
        );
    }

    public function academicTerm(): StatusCheck
    {
        $name = 'Academic term';
        $term = AcademicTerm::current();

        if ($term) {
            return new StatusCheck($name, true, "The current term is {$term->label()}.");
        }

        return new StatusCheck(
            $name,
            false,
            'No current term is set, so registration is closed.',
            'Add or choose the current term on the Academic term page.',
        );
    }

    public function mapData(): StatusCheck
    {
        $counts = [
            'building' => Building::where('type', BuildingType::Building)->count(),
            'path' => Path::count(),
            'room' => Room::count(),
        ];
        $summary = collect($counts)->map(fn (int $count, string $thing) => $count.' '.Str::plural($thing, $count))->join(', ', ' and ');

        if (min($counts) > 0) {
            return new StatusCheck('Map data', true, "The map has {$summary}.");
        }

        return new StatusCheck(
            'Map data',
            false,
            "The map has {$summary}, so directions can't work everywhere.",
            'Import campus.geojson on the Map data page.',
        );
    }

    public function pushSubscribers(): StatusCheck
    {
        $name = 'Push subscribers';
        $devices = PushSubscription::count();

        if ($devices > 0) {
            return new StatusCheck($name, true, $devices.' '.Str::plural('device', $devices).' '.($devices === 1 ? 'has' : 'have').' emergency alerts turned on.');
        }

        return new StatusCheck(
            $name,
            false,
            'No device has emergency alerts turned on yet.',
            'Ask students to tap “Turn on emergency alerts” on their portal home or the landing page.',
        );
    }
}
