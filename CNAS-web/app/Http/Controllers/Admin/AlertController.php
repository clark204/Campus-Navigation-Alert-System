<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SafetyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResolveAlertRequest;
use App\Http\Requests\Admin\StoreAlertRequest;
use App\Http\Requests\Admin\UpdateAlertRequest;
use App\Models\Alert;
use App\Models\SafetyCheck;
use App\Services\CampusMap;
use App\Services\SafetyCheckSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Emergency alerts: admins send one (with an optional incident pin, danger zone and affected buildings), edit its
 * wording while it's active, and resolve it as "All clear" or "False alarm". Every change is logged (alert_logs).
 */
class AlertController extends Controller
{
    private const HISTORY_PER_PAGE = 25;

    private const CAMPUS_TIMEZONE = 'Asia/Manila';

    /**
     * The active alert with who is safe (SafetyCheckSummary; ?college= lists one college's people) and its help
     * requests, and the History table: one line per alert, filtered by ?q= (title or message), ?type=, ?severity=,
     * ?kind=real|drill and ?month=YYYY-MM. Each line opens the alert's report (show).
     */
    public function index(Request $request, SafetyCheckSummary $safety): View
    {
        $activeAlert = Alert::active()->with('creator')->latest('sent_at')->first();
        $college = $request->filled('college') ? (string) $request->query('college') : null;
        $filters = $this->historyFilters($request);

        return view('admin.alerts-page', [
            'activeAlert' => $activeAlert,
            'safety' => $activeAlert ? $safety->counts($activeAlert) : null,
            'safePeople' => $activeAlert ? $safety->people($activeAlert, $college) : collect(),
            'safetyCollege' => $college,
            'helpRequests' => $activeAlert?->isEmergency() ? $safety->helpRequests($activeAlert) : collect(),
            'filters' => $filters,
            'filtering' => array_filter($filters) !== [],
            'types' => AlertType::cases(),
            'severities' => AlertSeverity::cases(),
            'history' => Alert::query()
                ->withCount(['safetyChecks as help_count' => fn ($checks) => $checks->where('status', SafetyStatus::NeedHelp)])
                ->when($filters['q'], fn ($alerts, $search) => $alerts->where(fn ($match) => $match
                    ->where('title', 'like', '%'.addcslashes($search, '%_\\').'%')
                    ->orWhere('message', 'like', '%'.addcslashes($search, '%_\\').'%')))
                ->when($filters['type'], fn ($alerts, $type) => $alerts->where('type', $type))
                ->when($filters['severity'], fn ($alerts, $severity) => $alerts->where('severity', $severity))
                ->when($filters['kind'], fn ($alerts, $kind) => $alerts->where('is_drill', $kind === 'drill'))
                ->when($filters['month'], function ($alerts, $month) {
                    // Months in campus time, compared in UTC (sent_at is stored in UTC).
                    $start = Carbon::createFromFormat('Y-m-d H:i', "{$month}-01 00:00", self::CAMPUS_TIMEZONE)->utc();

                    return $alerts->where('sent_at', '>=', $start)->where('sent_at', '<', $start->copy()->addMonthNoOverflow());
                })
                ->latest('sent_at')
                ->latest('id')
                ->paginate(self::HISTORY_PER_PAGE)
                ->withQueryString(),
        ]);
    }

    /**
     * One alert's report, from the History table: what was sent, who answered, every help request and how it was
     * handled, and the activity. Safety answers (and help locations) are deleted 30 days after the alert ends.
     */
    public function show(Alert $alert, SafetyCheckSummary $safety): View
    {
        $alert->load(['creator', 'resolver', 'logs' => fn ($logs) => $logs->with('user')->oldest('created_at')->oldest('id')]);
        $pruned = $alert->resolved_at !== null && $alert->resolved_at->lt(now()->subDays(SafetyCheck::RETENTION_DAYS));
        $asked = $alert->isEmergency() && ! $pruned;

        return view('admin.alert-report-page', [
            'alert' => $alert,
            'safety' => $asked ? $safety->counts($alert) : null,
            'helpRequests' => $asked ? $safety->helpRequests($alert) : collect(),
            'pruned' => $pruned,
        ]);
    }

    /**
     * The help requests alone (admin/alert-help-panel), for resources/js/alert-safety-live.js: asked every 5 s while
     * Admin > Alerts is open, so a new "I need help" and every moving position show without a reload.
     */
    public function help(Alert $alert, SafetyCheckSummary $safety): View
    {
        return view('admin.alert-help-panel', ['alert' => $alert, 'helpRequests' => $safety->helpRequests($alert)]);
    }

    /**
     * @return array{q: string|null, type: string|null, severity: string|null, kind: string|null, month: string|null}
     */
    private function historyFilters(Request $request): array
    {
        $month = (string) $request->query('month');
        $kind = (string) $request->query('kind');

        return [
            'q' => filled($request->query('q')) ? mb_substr(trim((string) $request->query('q')), 0, 100) : null,
            'type' => AlertType::tryFrom((string) $request->query('type'))?->value,
            'severity' => AlertSeverity::tryFrom((string) $request->query('severity'))?->value,
            'kind' => in_array($kind, ['real', 'drill'], true) ? $kind : null,
            'month' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) ? $month : null,
        ];
    }

    /**
     * The "I'm safe" panel alone (admin/alert-safety-panel), for resources/js/alert-safety-live.js: Admin > Alerts asks
     * for it every 10 s, so new answers show without a reload. ?college= keeps the chosen college.
     */
    public function safety(Request $request, Alert $alert, SafetyCheckSummary $safety): View
    {
        $college = $request->filled('college') ? (string) $request->query('college') : null;

        return view('admin.alert-safety-panel', [
            'alert' => $alert,
            'safety' => $safety->counts($alert),
            'safePeople' => $safety->people($alert, $college),
            'safetyCollege' => $college,
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
