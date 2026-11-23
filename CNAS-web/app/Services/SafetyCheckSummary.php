<?php

namespace App\Services;

use App\Enums\SafetyStatus;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\College;
use App\Models\SafetyCheck;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who answered "I'm safe" or "I need help" during an alert, for the admin (docs/alerts.md): the web alerts page and
 * the mobile app's live view. Everyone with an account except admins is counted, so they are the total; "not answered"
 * is the rest.
 * By college through each account's program; accounts without a program count under "No college". Answers are
 * admin-only (RA 10173) and deleted 30 days after the alert ends (SafetyCheck::prunable).
 */
class SafetyCheckSummary
{
    /** The filter value for accounts without a college (staff, faculty without a program). */
    public const NO_COLLEGE = 'none';

    /** Names listed at most; the counts cover everyone. */
    public const PEOPLE_LIMIT = 200;

    /**
     * @return array{safe: int, need_help: int, total: int, not_answered: int, colleges: list<array{id: string, name: string, safe: int, need_help: int, total: int}>}
     */
    public function counts(Alert $alert): array
    {
        $totals = $this->byCollege(User::query()->where('role', '!=', UserRole::Admin)->leftJoin('programs', 'programs.code', '=', 'users.program_code'));
        $answers = fn (SafetyStatus $status) => $this->byCollege(SafetyCheck::query()->where('alert_id', $alert->id)
            ->where('safety_checks.status', $status)
            ->join('users', 'users.id', '=', 'safety_checks.user_id')
            ->where('users.role', '!=', UserRole::Admin)
            ->leftJoin('programs', 'programs.code', '=', 'users.program_code'));
        $safe = $answers(SafetyStatus::Safe);
        $needHelp = $answers(SafetyStatus::NeedHelp);

        $names = College::query()->pluck('name', 'id');
        $colleges = $totals->keys()->merge($safe->keys())->merge($needHelp->keys())->unique()
            ->map(fn (string $id) => [
                'id' => $id,
                'name' => $id === self::NO_COLLEGE ? 'No college' : (string) ($names[$id] ?? $id),
                'safe' => (int) ($safe[$id] ?? 0),
                'need_help' => (int) ($needHelp[$id] ?? 0),
                'total' => (int) ($totals[$id] ?? 0),
            ])
            // Colleges by name, "No college" last.
            ->sortBy(fn (array $college) => [$college['id'] === self::NO_COLLEGE ? 1 : 0, $college['name']])
            ->values()
            ->all();

        $safeTotal = (int) $safe->sum();
        $needHelpTotal = (int) $needHelp->sum();
        $total = (int) $totals->sum();

        return [
            'safe' => $safeTotal,
            'need_help' => $needHelpTotal,
            'total' => $total,
            'not_answered' => max(0, $total - $safeTotal - $needHelpTotal),
            'colleges' => $colleges,
        ];
    }

    /**
     * Who answered: "I need help" first (with their note), then "I'm safe", latest answer first within each,
     * optionally in one college (or NO_COLLEGE).
     *
     * @return Collection<int, SafetyCheck>
     */
    public function people(Alert $alert, ?string $college = null): Collection
    {
        return SafetyCheck::query()
            ->where('alert_id', $alert->id)
            ->whereHas('user', fn ($users) => $users->where('role', '!=', UserRole::Admin))
            ->with('user.program.college')
            ->when($college === self::NO_COLLEGE, fn ($query) => $query->whereHas('user', fn ($users) => $users->where(
                fn ($noCollege) => $noCollege->whereNull('program_code')->orWhereHas('program', fn ($programs) => $programs->whereNull('college_id')),
            )))
            ->when($college !== null && $college !== self::NO_COLLEGE, fn ($query) => $query->whereHas('user.program', fn ($programs) => $programs->where('college_id', $college)))
            ->orderByRaw('case when status = ? then 0 else 1 end', [SafetyStatus::NeedHelp->value])
            ->latest('responded_at')
            ->limit(self::PEOPLE_LIMIT)
            ->get();
    }

    /**
     * The help requests (every "I need help"): open ones first, waiting before "on the way", oldest first (the person
     * who has waited longest); then the helped ones, latest first.
     *
     * @return Collection<int, SafetyCheck>
     */
    public function helpRequests(Alert $alert): Collection
    {
        return SafetyCheck::query()
            ->where('alert_id', $alert->id)
            ->where('status', SafetyStatus::NeedHelp)
            ->with(['user.program.college', 'responder', 'helper'])
            ->get()
            ->sortBy(fn (SafetyCheck $check) => match (true) {
                $check->helped_at !== null => [2, -$check->helped_at->getTimestamp()],
                $check->responder_id !== null => [1, $check->responded_at->getTimestamp()],
                default => [0, $check->responded_at->getTimestamp()],
            })
            ->values();
    }

    /**
     * One help request for the admin's map and queue (web help panel and the app's GET /api/alerts/{alert}/safety).
     *
     * @return array{id: int, name: string, role: string, college: string|null, note: string|null, status: string, status_label: string, latitude: float|null, longitude: float|null, accuracy_m: int|null, located_at: string|null, asked_at: string, responder_id: int|null, responder_name: string|null, responding_at: string|null, helped_by_name: string|null, helped_at: string|null}
     */
    public static function helpRequestData(SafetyCheck $check): array
    {
        $status = $check->helpStatus();

        return [
            'id' => $check->id,
            'name' => $check->user->full_name,
            'role' => $check->user->role->label(),
            'college' => $check->user->program?->college?->name,
            'note' => $check->note,
            'status' => $status->value,
            'status_label' => $status->label(),
            'latitude' => $check->latitude,
            'longitude' => $check->longitude,
            'accuracy_m' => $check->accuracy_m,
            'located_at' => $check->located_at?->toIso8601String(),
            'asked_at' => $check->responded_at->toIso8601String(),
            'responder_id' => $check->responder_id,
            'responder_name' => $check->responder?->full_name,
            'responding_at' => $check->responding_at?->toIso8601String(),
            'helped_by_name' => $check->helper?->full_name,
            'helped_at' => $check->helped_at?->toIso8601String(),
        ];
    }

    /**
     * Counts grouped by the program's college (NO_COLLEGE when there is none).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<*>  $query
     * @return Collection<string, int>
     */
    private function byCollege($query): Collection
    {
        return $query->toBase()
            ->selectRaw('programs.college_id as college, count(*) as total')
            ->groupBy('programs.college_id')
            ->pluck('total', 'college')
            ->mapWithKeys(fn ($count, $college) => [($college === null || $college === '') ? self::NO_COLLEGE : (string) $college => (int) $count]);
    }
}
