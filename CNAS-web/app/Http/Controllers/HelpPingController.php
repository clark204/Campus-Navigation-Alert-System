<?php

namespace App\Http\Controllers;

use App\Enums\HelpStatus;
use App\Http\Requests\HelpPingRequest;
use App\Models\Alert;
use App\Models\SafetyCheck;
use Illuminate\Http\JsonResponse;

/**
 * The open help request's check-in, about every 15 s while the person's page (resources/js/alerts/help-tracker.js) or
 * app (src/lib/help-tracker.ts) is open: their latest position when they share it, and back comes where the request
 * is ("<admin> is on the way"). 409 once there is nothing to share any more (marked helped, answered "I'm safe", or
 * the alert is over), and the page or app stops. POST /alerts/{alert}/help/ping (web) and /api/alerts/{alert}/help/ping.
 */
class HelpPingController extends Controller
{
    public function __invoke(HelpPingRequest $request, Alert $alert): JsonResponse
    {
        if ($refusal = $alert->safetyCheckRefusal()) {
            return response()->json(['message' => $refusal, 'help' => null], 409);
        }

        $check = SafetyCheck::query()->where('alert_id', $alert->id)->where('user_id', $request->user()->id)->first();

        if ($check?->helpStatus()?->isOpen() !== true) {
            return response()->json(['message' => 'There is no open help request.', 'help' => $check ? self::helpState($check) : null], 409);
        }

        if ($location = $request->location()) {
            $check->updateLocation($location);
        }

        return response()->json(['help' => self::helpState($check)]);
    }

    /**
     * What the person sees about their own request: shared by the ping, "I need help" and the app's active-alert poll.
     *
     * @return array{status: string, label: string, responder_name: string|null}|null
     */
    public static function helpState(SafetyCheck $check): ?array
    {
        $status = $check->helpStatus();

        if ($status === null) {
            return null;
        }

        $check->loadMissing('responder');

        return [
            'status' => $status->value,
            'label' => match ($status) {
                HelpStatus::New => NeedHelpController::THANKS,
                HelpStatus::Responding => ($check->responder?->full_name ?? 'An administrator').' is on the way.',
                HelpStatus::Helped => 'Marked as helped. Stay safe.',
            },
            'responder_name' => $status === HelpStatus::Responding ? $check->responder?->full_name : null,
        ];
    }
}
