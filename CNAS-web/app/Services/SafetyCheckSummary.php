<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\College;
use App\Models\SafetyCheck;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who answered "I'm safe" during an alert, for the admin (docs/alerts.md): the web alerts page and the mobile app's
 * live view. Everyone with an account except admins is counted, so they are the total; "not answered" is the rest.
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
     * @return array{safe: int, total: int, not_answered: int, colleges: list<array{id: string, name: string, safe: int, total: int}>}
     */
    public function counts(Alert $alert): array
    {
        $totals = $this->byCollege(User::query()->where('role', '!=', UserRole::Admin)->leftJoin('programs', 'programs.code', '=', 'users.program_code'));
        $safe = $this->byCollege(SafetyCheck::query()->where('alert_id', $alert->id)
            ->join('users', 'users.id', '=', 'safety_checks.user_id')
            ->where('users.role', '!=', UserRole::Admin)
            ->leftJoin('programs', 'programs.code', '=', 'users.program_code'));

        $names = College::query()->pluck('name', 'id');
        $colleges = $totals->keys()->merge($safe->keys())->unique()
            ->map(fn (string $id) => [
                'id' => $id,
                'name' => $id === self::NO_COLLEGE ? 'No college' : (string) ($names[$id] ?? $id),
                'safe' => (int) ($safe[$id] ?? 0),
                'total' => (int) ($totals[$id] ?? 0),
            ])
            // Colleges by name, "No college" last.
            ->sortBy(fn (array $college) => [$college['id'] === self::NO_COLLEGE ? 1 : 0, $college['name']])
            ->values()
            ->all();

        $safeTotal = (int) $safe->sum();
        $total = (int) $totals->sum();

        return ['safe' => $safeTotal, 'total' => $total, 'not_answered' => max(0, $total - $safeTotal), 'colleges' => $colleges];
    }

    /**
     * The people who are safe, latest answer first, optionally in one college (or NO_COLLEGE).
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
            ->latest('responded_at')
            ->limit(self::PEOPLE_LIMIT)
            ->get();
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
