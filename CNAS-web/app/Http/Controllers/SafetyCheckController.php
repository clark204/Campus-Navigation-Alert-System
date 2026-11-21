<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\SafetyCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "I'm safe" from the alarm takeover or the banner, for signed-in users during an active emergency. An advisory or
 * a warning doesn't ask, so it is refused here too.
 */
class SafetyCheckController extends Controller
{
    public function __invoke(Request $request, Alert $alert): JsonResponse|RedirectResponse
    {
        if (! $alert->isActive()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'This alert is over.'], 409)
                : back()->withErrors(['safety' => 'This alert is over.']);
        }

        if (! $alert->isEmergency()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Only an emergency asks whether you are safe.'], 409)
                : back()->withErrors(['safety' => 'Only an emergency asks whether you are safe.']);
        }

        SafetyCheck::markSafe($alert, $request->user());

        return $request->expectsJson()
            ? response()->json(['status' => 'safe'])
            : back()->with('status', 'Thanks. The administrators can see that you are safe.');
    }
}
