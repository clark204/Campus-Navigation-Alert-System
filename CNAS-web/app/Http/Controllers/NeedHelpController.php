<?php

namespace App\Http\Controllers;

use App\Http\Requests\NeedHelpRequest;
use App\Jobs\SendAdminReportPush;
use App\Models\Alert;
use App\Models\SafetyCheck;
use App\Services\AdminReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * "I need help" during an active emergency (docs/alerts.md): from the takeover or the banner (web) and the mobile app
 * (POST /api/alerts/{alert}/help). Same rule as "I'm safe" (Alert::safetyCheckRefusal). It replaces an earlier
 * answer, shows first on the admins' live view, and pushes a report to every admin's phone.
 */
class NeedHelpController extends Controller
{
    public const THANKS = 'Help is on the way. The administrators can see your message.';

    public function __invoke(NeedHelpRequest $request, Alert $alert): JsonResponse|RedirectResponse
    {
        if ($refusal = $alert->safetyCheckRefusal()) {
            return $request->expectsJson()
                ? response()->json(['message' => $refusal], 409)
                : back()->withErrors(['safety' => $refusal]);
        }

        $check = SafetyCheck::markNeedHelp($alert, $request->user(), $request->validated('note'));
        SendAdminReportPush::dispatch(AdminReport::needsHelp($check));

        return $request->expectsJson()
            ? response()->json(['status' => 'need_help', 'message' => self::THANKS])
            : back()->with('status', self::THANKS);
    }
}
