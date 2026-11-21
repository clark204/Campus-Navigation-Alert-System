<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;

/**
 * Polled every 15 seconds by every page (guests included) and the mobile app: the active alert, a recent
 * "All clear", or null.
 */
class ActiveAlertController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $alert = Alert::current();

        return response()
            ->json(['data' => $alert ? new AlertResource($alert) : null])
            ->header('Cache-Control', 'no-store');
    }
}
