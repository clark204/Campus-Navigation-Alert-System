<?php

namespace App\Http\Controllers\Api;

use App\Enums\AlertResolution;
use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResolveAlertRequest;
use App\Http\Requests\Admin\StoreAlertRequest;
use App\Http\Requests\Admin\UpdateAlertRequest;
use App\Http\Resources\ManagedAlertResource;
use App\Models\Alert;
use App\Services\CampusMap;
use App\Services\SafetyCheckSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Sending and ending emergency alerts from the mobile app (docs/alerts.md), for the admin and the faculty and staff the
 * admin trusts (EnsureUserCanSendAlerts), like the web's Admin\AlertController and Portal\AlertController: the same
 * requests and Alert::send, revise, changeSeverity and resolve, so every change is logged in alert_logs and pushed
 * the same way. Editing the wording or level and the "I'm safe" live view stay with the admin, like on the web.
 */
class AlertManagementController extends Controller
{
    private const HISTORY_PER_PAGE = 15;

    /** Trusted senders see the last few, like their portal page. */
    private const RECENT = 5;

    /**
     * The active alert (with the safe count for the admin) and the history: the admin's with the log, a page at a
     * time; a trusted sender's the last few.
     */
    public function index(Request $request, SafetyCheckSummary $safety): JsonResponse
    {
        $isAdmin = $request->user()->isAdmin();
        $active = Alert::active()->with(['creator', 'resolver'])->latest('sent_at')->first();
        $history = Alert::query()->with(['creator', 'resolver'])->latest('sent_at')->latest('id');

        if ($isAdmin) {
            $page = $history->with(['logs' => fn ($logs) => $logs->with('user')->oldest('created_at')->oldest('id')])->paginate(self::HISTORY_PER_PAGE);
            $items = $page->getCollection();
            $meta = ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
        } else {
            $items = $history->limit(self::RECENT)->get();
            $meta = null;
        }

        return response()->json([
            'active' => $active ? new ManagedAlertResource($active) : null,
            'safety' => $active && $isAdmin ? $safety->counts($active) : null,
            'history' => ManagedAlertResource::collection($items),
            'meta' => $meta,
            'can_edit' => $isAdmin,
        ]);
    }

    /**
     * What the send form needs: the types with their level and starting wording, the levels with what students get, the
     * danger zones, the buildings an alert can name, and the endings with their starting text.
     */
    public function form(CampusMap $map): JsonResponse
    {
        return response()->json([
            'types' => array_map(fn (AlertType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'severity' => $type->defaultSeverity()->value,
                'campus_wide' => $type->isCampusWide(),
                'template' => $type->template(),
            ], AlertType::cases()),
            'severities' => array_map(fn (AlertSeverity $severity) => [
                'value' => $severity->value,
                'label' => $severity->label(),
                'color' => $severity->color(),
                'description' => $severity->description(),
                'example' => $severity->example(),
                'students_get' => $severity->studentsGet(),
            ], AlertSeverity::cases()),
            'radii' => Alert::DANGER_RADII,
            'default_radius' => Alert::DEFAULT_DANGER_RADIUS,
            'buildings' => collect($map->buildings())->map(fn (string $name, string $code) => ['code' => $code, 'name' => $name])->values(),
            'resolutions' => array_map(fn (AlertResolution $resolution) => [
                'value' => $resolution->value,
                'label' => $resolution->label(),
                'message' => $resolution->defaultMessage(false),
                'drill_message' => $resolution->defaultMessage(true),
            ], AlertResolution::cases()),
        ]);
    }

    /**
     * Send it now (422 on "form" when one is already active, from Alert::send).
     */
    public function store(StoreAlertRequest $request): JsonResponse
    {
        $alert = Alert::send($request->alertAttributes(), $request->user());
        $what = $alert->is_drill ? 'Drill alert' : 'Alert';

        return (new ManagedAlertResource($alert->load('creator')))
            ->additional(['message' => $request->user()->isAdmin()
                ? "{$what} sent. It shows on every page within 15 seconds and is pushed to subscribed devices."
                : "{$what} sent. Everyone sees it within 15 seconds, and the administrator can see you sent it."])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Edit the wording and the level of the active alert (admin only, UpdateAlertRequest). A new level is logged and
     * pushed again (Alert::changeSeverity); new wording only shows on the next poll.
     */
    public function update(UpdateAlertRequest $request, Alert $alert): JsonResponse
    {
        if (! $alert->isActive()) {
            return response()->json(['message' => 'Only an active alert can be edited.'], 409);
        }

        $alert->revise($request->validated(), $request->user());
        $alert->changeSeverity(AlertSeverity::from($request->validated('severity')), $request->user());

        return (new ManagedAlertResource($alert->fresh(['creator'])))
            ->additional(['message' => 'Alert updated. Everyone sees the new wording and level within 15 seconds.'])
            ->response();
    }

    public function resolve(ResolveAlertRequest $request, Alert $alert): JsonResponse
    {
        if (! $alert->isActive()) {
            return response()->json(['message' => 'That alert has already ended.'], 409);
        }

        $resolution = $request->resolution();
        $alert->resolve($request->validated('resolved_message'), $request->user(), $resolution);

        return (new ManagedAlertResource($alert->fresh(['creator', 'resolver'])))
            ->additional(['message' => "Marked as {$resolution->label()}. Everyone sees it for the next ".Alert::ALL_CLEAR_MINUTES.' minutes.'])
            ->response();
    }

    /**
     * The "I'm safe" live view (admin only): the counts, by college, and who answered, "I need help" first (?college= one
     * college, or "none"). The app asks again every few seconds while it is open.
     */
    public function safety(Request $request, Alert $alert, SafetyCheckSummary $safety): JsonResponse
    {
        $college = $request->filled('college') ? (string) $request->query('college') : null;

        return response()->json([
            ...$safety->counts($alert),
            'people' => $safety->people($alert, $college)->map(fn ($check) => [
                'name' => $check->user->full_name,
                'role' => $check->user->role->label(),
                'college' => $check->user->program?->college?->name,
                'status' => $check->status->value,
                'note' => $check->note,
                'responded_at' => $check->responded_at->toIso8601String(),
            ])->values(),
        ])->header('Cache-Control', 'no-store');
    }
}
