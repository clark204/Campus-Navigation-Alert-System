<?php

namespace App\Http\Controllers\Admin;

use App\Enums\HelpStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SendHelpUpdatePush;
use App\Models\Alert;
use App\Models\SafetyCheck;
use App\Services\SafetyCheckSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The admin's help queue during an emergency (docs/alerts.md): "I'm responding" (the person sees "<admin> is on the
 * way", and their phone gets a push) and "Mark helped" (their page or app stops sharing where they are). Admin only,
 * on the web (Admin > Emergency alerts) and the app (/api/alerts/{alert}/help/{safetyCheck}/...). The safety check is
 * scoped to the alert, so an id from another alert is a 404. Both are logged on the alert's activity.
 */
class HelpRequestController extends Controller
{
    public function respond(Request $request, Alert $alert, SafetyCheck $safetyCheck): JsonResponse|RedirectResponse
    {
        if ($refusal = $this->refusal($alert, $safetyCheck)) {
            return $this->answer($request, $safetyCheck, $refusal, 409);
        }

        $safetyCheck->respond($request->user());
        SendHelpUpdatePush::dispatch($safetyCheck);

        return $this->answer($request, $safetyCheck, "You are responding to {$safetyCheck->user->full_name}.");
    }

    public function helped(Request $request, Alert $alert, SafetyCheck $safetyCheck): JsonResponse|RedirectResponse
    {
        if ($refusal = $this->refusal($alert, $safetyCheck)) {
            return $this->answer($request, $safetyCheck, $refusal, 409);
        }

        $safetyCheck->markHelped($request->user());

        return $this->answer($request, $safetyCheck, "{$safetyCheck->user->full_name} is marked as helped.");
    }

    private function refusal(Alert $alert, SafetyCheck $check): ?string
    {
        abort_if($check->helpStatus() === null, 404);

        return match (true) {
            ! $alert->isActive() => 'This alert is over.',
            $check->helpStatus() === HelpStatus::Helped => 'This person is already marked as helped.',
            default => null,
        };
    }

    private function answer(Request $request, SafetyCheck $check, string $message, int $status = 200): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            $check->refresh()->load(['user.program.college', 'responder', 'helper']);

            return response()->json(['message' => $message, 'help_request' => SafetyCheckSummary::helpRequestData($check)], $status);
        }

        return $status === 200
            ? back()->with('status', $message)
            : back()->withErrors(['help' => $message]);
    }
}
