<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\SafetyCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "I'm safe" from the alarm takeover or the banner, for signed-in users during an active emergency. An advisory or
 * a warning doesn't ask, so it is refused here too (Alert::safetyCheckRefusal). Also the mobile app's
 * POST /api/alerts/{alert}/safe (Sanctum). "I need help" is NeedHelpController.
 */
class SafetyCheckController extends Controller
{
    public function __invoke(Request $request, Alert $alert): JsonResponse|RedirectResponse
    {
        if ($refusal = $alert->safetyCheckRefusal()) {
            return $request->expectsJson()
                ? response()->json(['message' => $refusal], 409)
                : back()->withErrors(['safety' => $refusal]);
        }

        SafetyCheck::markSafe($alert, $request->user());

        return $request->expectsJson()
            ? response()->json(['status' => 'safe'])
            : back()->with('status', 'Thanks. The administrators can see that you are safe.');
    }
}
