<?php

namespace App\Services;

use App\Enums\ConcernTopic;
use App\Enums\LoginChannel;
use App\Enums\UserRole;
use App\Models\Alert;
use App\Models\College;
use App\Models\Concern;
use App\Models\DevicePushToken;
use App\Models\LoginActivity;
use App\Models\SafetyCheck;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use NotificationChannels\WebPush\PushSubscription;

/**
 * The numbers on the admin dashboard, the same on the website (Admin\DashboardController) and in the app
 * (GET /api/admin/dashboard): headline tiles with their change, sign-ins per day, accounts, students by college,
 * concerns, alerts and the places people go. Days are campus days (Asia/Manila). Only counts: never anyone's details.
 */
class AdminAnalytics
{
    public const TIMEZONE = 'Asia/Manila';

    /** Days in the sign-ins chart. */
    public const DAYS = 14;

    /** Colleges listed by name; the rest are added up as "Other colleges". */
    public const TOP_COLLEGES = 5;

    public const TOP_PLACES = 5;

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        return [
            'generated_at' => now()->toIso8601String(),
            'accounts' => $this->accounts($today),
            'sign_ins' => $this->signIns($today),
            'concerns' => $this->concerns($today),
            'alerts' => $this->alerts($today),
            'reach' => ['phones' => DevicePushToken::count(), 'browsers' => PushSubscription::count()],
            'colleges' => $this->colleges(),
            'places' => $this->places(),
        ];
    }

    /**
     * Total, by role, and new this week against last week (weeks start on Monday, campus time).
     *
     * @return array{total: int, by_role: array<string, int>, this_week: int, last_week: int}
     */
    private function accounts(CarbonImmutable $today): array
    {
        $byRole = User::query()->toBase()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');
        $week = $today->startOfWeek();
        $created = fn (CarbonImmutable $from, CarbonImmutable $to) => User::where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())->count();

        return [
            'total' => (int) $byRole->sum(),
            'by_role' => collect(UserRole::cases())->mapWithKeys(fn (UserRole $role) => [$role->value => (int) ($byRole[$role->value] ?? 0)])->all(),
            'this_week' => $created($week, $today->addDay()),
            'last_week' => $created($week->subWeek(), $week),
        ];
    }

    /**
     * Sign-ins per campus day for the last DAYS days, website and app apart; the last 7 days against the 7 before;
     * and how many people signed in at least once in the last 7 days.
     *
     * @return array{days: list<array{date: string, web: int, app: int}>, last_7: int, previous_7: int, people_7: int}
     */
    private function signIns(CarbonImmutable $today): array
    {
        $from = $today->subDays(self::DAYS - 1);
        $rows = LoginActivity::query()
            ->where('logged_in_at', '>=', $from->utc())
            ->get(['user_id', 'channel', 'logged_in_at']);

        $days = [];
        for ($day = $from; $day <= $today; $day = $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'web' => 0, 'app' => 0];
        }
        foreach ($rows as $row) {
            $date = $row->logged_in_at->timezone(self::TIMEZONE)->toDateString();
            if (isset($days[$date])) {
                $days[$date][$row->channel === LoginChannel::Api ? 'app' : 'web']++;
            }
        }

        $weekStart = $today->subDays(6);
        $lastSeven = $rows->filter(fn ($row) => $row->logged_in_at->gte($weekStart->utc()));

        return [
            'days' => array_values($days),
            'last_7' => $lastSeven->count(),
            'previous_7' => $rows->count() - $lastSeven->count(),
            'people_7' => $lastSeven->pluck('user_id')->unique()->count(),
        ];
    }

    /**
     * Open and resolved, the topics of the last 30 days, and how long the first reply took (median hours, last 30
     * days, only concerns that got one).
     *
     * @return array{open: int, resolved: int, last_30: int, by_topic: list<array{topic: string, label: string, count: int}>, median_reply_hours: float|null}
     */
    private function concerns(CarbonImmutable $today): array
    {
        $since = $today->subDays(29)->utc();
        $recent = Concern::query()->where('created_at', '>=', $since)->withMin('replies', 'created_at')->get(['id', 'topic', 'created_at']);
        $byTopic = $recent->countBy(fn (Concern $concern) => $concern->topic->value);

        $replyHours = $recent
            ->filter(fn (Concern $concern) => $concern->replies_min_created_at !== null)
            ->map(fn (Concern $concern) => $concern->created_at->diffInMinutes($concern->replies_min_created_at) / 60)
            ->sort()
            ->values();

        return [
            'open' => Concern::open()->count(),
            'resolved' => Concern::resolved()->count(),
            'last_30' => $recent->count(),
            'by_topic' => collect(ConcernTopic::cases())
                ->map(fn (ConcernTopic $topic) => ['topic' => $topic->value, 'label' => $topic->shortLabel(), 'count' => (int) ($byTopic[$topic->value] ?? 0)])
                ->sortByDesc('count')
                ->values()
                ->all(),
            'median_reply_hours' => $replyHours->isEmpty() ? null : round((float) $replyHours->median(), 1),
        ];
    }

    /**
     * Alerts in the last 90 days (drills apart) and how many people answered the last emergency.
     *
     * @return array{last_90: int, drills_90: int, last: array{title: string, is_drill: bool, sent_at: string, answered: int, need_help: int, accounts: int}|null}
     */
    private function alerts(CarbonImmutable $today): array
    {
        $since = $today->subDays(89)->utc();
        $last = Alert::query()->where('severity', 'emergency')->latest('sent_at')->latest('id')->first();

        return [
            'last_90' => Alert::where('sent_at', '>=', $since)->count(),
            'drills_90' => Alert::where('sent_at', '>=', $since)->where('is_drill', true)->count(),
            'last' => $last === null ? null : [
                'title' => $last->title,
                'is_drill' => (bool) $last->is_drill,
                'sent_at' => $last->sent_at->toIso8601String(),
                'answered' => SafetyCheck::where('alert_id', $last->id)->count(),
                'need_help' => SafetyCheck::where('alert_id', $last->id)->where('status', 'need_help')->count(),
                'accounts' => User::where('role', '!=', UserRole::Admin)->count(),
            ],
        ];
    }

    /**
     * Students per college, largest first; the colleges past TOP_COLLEGES are added up into "Other colleges".
     *
     * @return list<array{name: string, students: int}>
     */
    private function colleges(): array
    {
        $colleges = College::query()
            ->withCount(['users as students' => fn ($users) => $users->where('role', UserRole::Student)])
            ->get()
            ->where('students', '>', 0)
            ->sortBy([['students', 'desc'], ['name', 'asc']])
            ->values();

        $rows = $colleges->take(self::TOP_COLLEGES)->map(fn (College $college) => ['name' => $college->name, 'students' => (int) $college->students]);
        $other = (int) $colleges->slice(self::TOP_COLLEGES)->sum('students');

        return $rows->when($other > 0, fn ($rows) => $rows->push(['name' => 'Other colleges', 'students' => $other]))->values()->all();
    }

    /**
     * The rooms people start directions to most (Recent) and save most.
     *
     * @return array{started: list<array{name: string, count: int}>, saved: list<array{name: string, count: int}>}
     */
    private function places(): array
    {
        $top = fn (string $table) => DB::table($table)
            ->join('rooms', 'rooms.id', '=', "{$table}.room_id")
            ->selectRaw('rooms.name as name, count(*) as total')
            ->groupBy('rooms.id', 'rooms.name')
            ->orderByDesc('total')
            ->orderBy('rooms.name')
            ->limit(self::TOP_PLACES)
            ->get()
            ->map(fn ($row) => ['name' => (string) $row->name, 'count' => (int) $row->total])
            ->all();

        return ['started' => $top('recent_places'), 'saved' => $top('saved_places')];
    }
}
