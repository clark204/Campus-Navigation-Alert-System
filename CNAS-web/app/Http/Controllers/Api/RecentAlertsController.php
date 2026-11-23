<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's Alerts screen (public, guests too): the alerts of the last DAYS days, newest first, at most LIMIT. Alerts
 * were shown to everyone anyway, so nothing private: no admin names (AlertResource). A toast that closed by itself or
 * a missed notification is never lost.
 */
class RecentAlertsController extends Controller
{
    public const DAYS = 30;

    public const LIMIT = 20;

    public function __invoke(Request $request): JsonResponse
    {
        $alerts = Alert::query()
            ->where('sent_at', '>=', now()->subDays(self::DAYS))
            ->latest('sent_at')
            ->latest('id')
            ->limit(self::LIMIT)
            ->get();

        return response()
            ->json(['data' => AlertResource::collection($alerts)->resolve($request)])
            ->header('Cache-Control', 'no-store');
    }
}
