<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SystemStatus;
use Illuminate\Http\JsonResponse;

/**
 * Settings → System status → Push subscribers, polled by resources/js/push-subscribers-live.js while that tab is open,
 * so the count follows devices turning alerts on and off without a reload. The database is the source of truth.
 * Polling, not Server-Sent Events: `php artisan serve` on Windows handles one request at a time, so a held-open stream
 * would block the whole site.
 */
class SystemStatusController extends Controller
{
    public function pushSubscribers(SystemStatus $status): JsonResponse
    {
        $check = $status->pushSubscribers();

        return response()->json([
            'ok' => $check->ok,
            'summary' => $check->summary,
            'fix' => $check->fix,
            'checked_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store');
    }
}
