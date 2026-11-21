<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Admin\AlertController as AdminAlertController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResolveAlertRequest;
use App\Http\Requests\Admin\StoreAlertRequest;
use App\Models\Alert;
use App\Services\CampusMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Emergency alerts" in the faculty portal, for faculty and staff the admin trusts (EnsureUserCanSendAlerts): send an
 * alert (it goes out at once, like the admin's) and end the active one. Same form, rules and logging as the admin's
 * (partials/alert-send-form, Alert::send); editing an alert's wording stays with the admin.
 */
class AlertController extends Controller
{
    private const RECENT = 5;

    public function index(Request $request): View
    {
        return view('portal.alerts-page', [
            'role' => $request->user()->role->portal(),
            'activeAlert' => Alert::active()->with('creator')->latest('sent_at')->first(),
            'recentAlerts' => Alert::query()->with('creator')->latest('sent_at')->latest('id')->limit(self::RECENT)->get(),
        ]);
    }

    public function create(Request $request, CampusMap $map): View|RedirectResponse
    {
        if (Alert::active()->exists()) {
            return to_route('faculty.alerts.index')->withErrors(['form' => 'An alert is already active. End it before sending another.']);
        }

        return view('portal.alert-send-page', [
            'role' => $request->user()->role->portal(),
            ...AdminAlertController::sendFormData($request, $map),
        ]);
    }

    public function store(StoreAlertRequest $request): RedirectResponse
    {
        $alert = Alert::send($request->alertAttributes(), $request->user());

        return to_route('faculty.alerts.index')
            ->with('status', ($alert->is_drill ? 'Drill alert' : 'Alert').' sent. Everyone sees it within 15 seconds, and the administrator can see you sent it.');
    }

    public function resolve(ResolveAlertRequest $request, Alert $alert): RedirectResponse
    {
        if (! $alert->isActive()) {
            return to_route('faculty.alerts.index')->withErrors(['form' => 'That alert has already ended.']);
        }

        $resolution = $request->resolution();
        $alert->resolve($request->validated('resolved_message'), $request->user(), $resolution);

        return to_route('faculty.alerts.index')->with('status', "Marked as {$resolution->label()}. Everyone gets a notification.");
    }
}
