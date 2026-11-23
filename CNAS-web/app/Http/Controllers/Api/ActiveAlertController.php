<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\SafetyCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Polled every 15 seconds by every page (guests included) and the mobile app: the active alert, a recent
 * "All clear", or null. The alert itself comes from the cache (Alert::current) for everyone.
 *
 * With a bearer token (the signed-in app) the alert also has `my_safety_status`: "safe" or "need_help" when this user
 * already answered (on any device), else null. The token is only checked during an active emergency, the only
 * time "I'm safe" is asked, so ordinary polls cost no extra queries.
 */
class ActiveAlertController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $alert = Alert::current();
        $data = $alert ? (new AlertResource($alert))->resolve($request) : null;

        if ($data !== null && $request->bearerToken() !== null) {
            $data['my_safety_status'] = $this->safetyStatus($request, $alert);
        }

        return response()
            ->json(['data' => $data])
            ->header('Cache-Control', 'no-store');
    }

    private function safetyStatus(Request $request, Alert $alert): ?string
    {
        if (! $alert->isActive() || ! $alert->isEmergency()) {
            return null;
        }

        $user = $request->user('sanctum');

        return $user === null ? null : SafetyCheck::where('alert_id', $alert->id)->where('user_id', $user->id)->value('status')?->value;
    }
}
