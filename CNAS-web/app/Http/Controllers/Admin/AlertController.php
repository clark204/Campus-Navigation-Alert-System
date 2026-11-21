<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResolveAlertRequest;
use App\Http\Requests\Admin\StoreAlertRequest;
use App\Http\Requests\Admin\UpdateAlertRequest;
use App\Models\Alert;
use App\Services\CampusMap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Emergency alerts: admins send one (with an optional incident pin, danger zone and affected buildings), edit its
 * wording while it's active, and resolve it as "All clear" or "False alarm". Every change is logged (alert_logs).
 */
class AlertController extends Controller
{
    private const HISTORY_PER_PAGE = 15;

    public function index(): View
    {
        return view('admin.alerts-page', [
            'activeAlert' => Alert::active()->with('creator')->withCount('safetyChecks')->latest('sent_at')->first(),
            'history' => Alert::query()
                ->with(['logs' => fn ($logs) => $logs->with('user')->oldest('created_at')->oldest('id')])
                ->latest('sent_at')
                ->latest('id')
                ->paginate(self::HISTORY_PER_PAGE),
        ]);
    }

    /**
     * The map-first send form. ?type=fire preselects a type (and its wording) for links from elsewhere.
     */
    public function create(Request $request, CampusMap $map): View|RedirectResponse
    {
        if (Alert::active()->exists()) {
            return redirect()->route('admin.alerts.index')->withErrors(['form' => 'An alert is already active. Edit or resolve it first.']);
        }

        return view('admin.alert-send-page', self::sendFormData($request, $map));
    }

    /**
     * What partials/alert-send-form needs; also used by the trusted users' portal page (Portal\AlertController).
     *
     * @return array<string, mixed>
     */
    public static function sendFormData(Request $request, CampusMap $map): array
    {
        $type = AlertType::tryFrom((string) old('type', $request->query('type'))) ?? AlertType::Fire;

        return [
            'types' => AlertType::cases(),
            'type' => $type,
            'severities' => AlertSeverity::cases(),
            // The level of the chosen type, unless the sender already picked one (old input after a failed send).
            'severity' => AlertSeverity::tryFrom((string) old('severity')) ?? $type->defaultSeverity(),
            'buildings' => $map->buildings(),
            'radii' => Alert::DANGER_RADII,
            'defaultRadius' => Alert::DEFAULT_DANGER_RADIUS,
        ];
    }

    public function store(StoreAlertRequest $request): RedirectResponse
    {
        $alert = Alert::send($request->alertAttributes(), $request->user());

        return redirect()->route('admin.alerts.index')
            ->with('status', ($alert->is_drill ? 'Drill alert' : 'Alert').' sent. It shows on every page within 15 seconds and is pushed to subscribed devices.');
    }

    public function edit(Alert $alert): View|RedirectResponse
    {
        if (! $alert->isActive()) {
            return redirect()->route('admin.alerts.index')->withErrors(['form' => 'Only an active alert can be edited.']);
        }

        return view('admin.alert-edit-page', ['alert' => $alert, 'severities' => AlertSeverity::cases()]);
    }

    public function update(UpdateAlertRequest $request, Alert $alert): RedirectResponse
    {
        if (! $alert->isActive()) {
            return redirect()->route('admin.alerts.index')->withErrors(['form' => 'Only an active alert can be edited.']);
        }

        $alert->revise($request->validated(), $request->user());
        // A change of level is logged and pushed again (Alert::changeSeverity).
        $alert->changeSeverity(AlertSeverity::from($request->validated('severity')), $request->user());

        return redirect()->route('admin.alerts.index')->with('status', 'Alert updated. Everyone sees the new wording and level within 15 seconds.');
    }

    public function resolve(ResolveAlertRequest $request, Alert $alert): RedirectResponse
    {
        if (! $alert->isActive()) {
            return redirect()->route('admin.alerts.index')->withErrors(['form' => 'That alert is already resolved.']);
        }

        $resolution = $request->resolution();
        $alert->resolve($request->validated('resolved_message'), $request->user(), $resolution);

        return redirect()->route('admin.alerts.index')
            ->with('status', "Marked as {$resolution->label()}. Everyone sees it for the next ".Alert::ALL_CLEAR_MINUTES.' minutes.');
    }
}
