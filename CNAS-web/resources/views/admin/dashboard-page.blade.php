{{-- Admin overview, an analytics dashboard (DashboardController). The numbers come from App\Services\AdminAnalytics,
     the same as the app's dashboard (GET /api/admin/dashboard): headline tiles with their change, sign-ins per day
     (website and app), accounts, students by college, concerns, alerts and the places people go. Then the campus map,
     recent sign-ups and User activity. Chart colors: website #A8323F, app #A87A10 (validated together for color
     vision deficiency and contrast; every chart also has a table or its numbers in text). Days are campus days. --}}
@extends('layout.admin-layout')
@use('App\Enums\UserRole')
@use('App\Models\LoginActivity')
@use('App\Models\User')

@section('title', 'Overview')
@section('heading', 'Overview')

@php
    $web = '#A8323F';
    $app = '#A87A10';
    $accounts = $analytics['accounts'];
    $signInStats = $analytics['sign_ins'];
    $concerns = $analytics['concerns'];
    $alerts = $analytics['alerts'];
    $reach = $analytics['reach'];
    $byRole = $accounts['by_role'];
    $roleRows = [
        ['role' => UserRole::Student, 'label' => 'Students'],
        ['role' => UserRole::Faculty, 'label' => 'Faculty'],
        ['role' => UserRole::Staff, 'label' => 'Staff'],
        ['role' => UserRole::Admin, 'label' => 'Admins'],
    ];
    $totalStudents = $byRole[UserRole::Student->value];

    // A change against the period before: "+3" / "−2" / "same", and whether it went up.
    $delta = function (int $now, int $before): array {
        $diff = $now - $before;

        return ['text' => $diff === 0 ? 'Same as before' : ($diff > 0 ? '+'.number_format($diff) : '−'.number_format(abs($diff))), 'up' => $diff > 0, 'down' => $diff < 0];
    };
    $signInDelta = $delta($signInStats['last_7'], $signInStats['previous_7']);
    $accountDelta = $delta($accounts['this_week'], $accounts['last_week']);

    // Sign-ins chart: one stacked column per day (website below, app on top).
    $dayTotals = array_map(fn (array $day) => $day['web'] + $day['app'], $signInStats['days']);
    $chartMax = max(1, ...$dayTotals);
    // Clean gridline steps: 1, 2, 5, 10, 20, 50… so the top line is a round number at or above the busiest day.
    $step = collect([1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000, 2000, 5000])->first(fn ($s) => $chartMax / $s <= 4) ?? 10000;
    $chartTop = (int) (ceil($chartMax / $step) * $step);
    $gridlines = range(0, $chartTop, $step);
    $dayLabel = fn (string $date) => \Carbon\Carbon::parse($date)->format('D');
    $dateLabel = fn (string $date) => \Carbon\Carbon::parse($date)->format('M j');

    // Sparkline for the sign-ins tile: the 14 daily totals as a 2px line.
    $spark = collect($dayTotals)->values()->map(fn ($v, $i) => round($i / max(count($dayTotals) - 1, 1) * 100, 1).','.round(28 - $v / $chartMax * 26, 1))->join(' ');

    $maxTopic = max(1, ...array_column($concerns['by_topic'], 'count'));
    $lastAlert = $alerts['last'];
    $answeredShare = $lastAlert && $lastAlert['accounts'] > 0 ? min(100, round($lastAlert['answered'] / $lastAlert['accounts'] * 100)) : 0;
    $places = $analytics['places'];
    $maxStarted = max(1, ...array_column($places['started'], 'count') ?: [1]);

    // No date: an account inserted straight into the database skips Eloquent's timestamps.
    $joinedAt = function ($time): string {
        if ($time === null) {
            return 'Date unknown';
        }
        $time = $time->timezone('Asia/Manila');

        return match (true) {
            $time->isToday() => 'Today, '.$time->format('g:i A'),
            $time->isYesterday() => 'Yesterday',
            $time->isCurrentYear() => $time->format('M j'),
            default => $time->format('M j, Y'),
        };
    };
    $card = 'rounded-2xl border border-border bg-surface';
    $tileLabel = 'text-xs font-semibold text-text-muted';
    $big = 'text-3xl font-semibold tracking-tight tabular-nums text-text-primary';
@endphp

@section('content.admin')
    <div class="flex flex-col gap-6">
        @if ($activeAlert)
            <div role="alert" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-alarm px-4 py-3 text-sm text-white">
                <p><strong>{{ $activeAlert->is_drill ? 'Drill alert' : 'Emergency alert' }} active:</strong> {{ $activeAlert->title }}</p>
                <a href="{{ route('admin.alerts.index') }}" class="rounded-lg bg-white px-3 py-1.5 font-semibold text-alarm transition hover:bg-red-50">Manage alert</a>
            </div>
        @endif
        {{-- Only when the admin should act (AcademicTerm::warning, the app's dashboard card says the same). --}}
        @if ($termWarning && $termWarning['kind'] === 'closed')
            <p role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $termWarning['message'] }}
                <a href="{{ route('admin.settings', ['tab' => 'academic-term']) }}" class="font-semibold underline underline-offset-4">Set the current term</a>
            </p>
        @elseif ($termWarning)
            <p role="status" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                {{ $termWarning['message'] }}
                <a href="{{ route('admin.settings', ['tab' => 'academic-term']) }}" class="font-semibold underline underline-offset-4">Review academic term</a>
            </p>
        @endif

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Campus overview</h1>
                <p class="mt-1 text-sm text-text-muted">Who's using TCC NAV, what they need, and how ready the campus is for the next alert.</p>
            </div>
            <div class="flex flex-wrap gap-3">
                <a href="{{ route('navigate') }}"
                    class="inline-flex items-center gap-2 rounded-lg border border-border bg-surface px-4 py-2.5 text-sm font-semibold text-primary transition hover:border-primary">
                    Open walking directions
                </a>
                @unless ($activeAlert)
                    <a href="{{ route('admin.alerts.create') }}"
                        class="inline-flex items-center gap-2 rounded-lg bg-alarm px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-alarm-deep">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20zM12 10v4m0 3h.01" /></svg>
                        Send emergency alert
                    </a>
                @endunless
            </div>
        </div>

        {{-- Headline tiles. Each change is against the period before, in words as well as the arrow. --}}
        <section aria-label="Headline numbers" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="{{ $card }} p-5">
                <h2 class="{{ $tileLabel }}">Accounts</h2>
                <p class="mt-2 flex items-baseline gap-2">
                    <span class="{{ $big }}">{{ number_format($accounts['total']) }}</span>
                    <span class="text-sm text-text-muted">registered, {{ number_format($accounts['this_week']) }} this week</span>
                </p>
                <p @class(['mt-1 text-xs font-semibold', 'text-safe' => $accountDelta['up'], 'text-text-muted' => ! $accountDelta['up']])>
                    {{ $accountDelta['text'] }} vs last week ({{ number_format($accounts['last_week']) }})
                </p>
                @if ($accounts['total'] > 0)
                    <div class="mt-4 flex h-2 gap-0.5 overflow-hidden rounded-full" role="img"
                        aria-label="Accounts by role: {{ collect($roleRows)->map(fn ($row) => $byRole[$row['role']->value].' '.strtolower($row['label']))->join(', ') }}">
                        @foreach ($roleRows as $index => $row)
                            @if ($byRole[$row['role']->value] > 0)
                                <span class="min-w-1" style="flex-grow: {{ $byRole[$row['role']->value] }}; background: {{ ['#A8323F', '#A87A10', '#C98A94', '#9CA3AF'][$index] }}"></span>
                            @endif
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="{{ $card }} p-5">
                <h2 class="{{ $tileLabel }}">Sign-ins, last 7 days</h2>
                <div class="mt-2 flex items-end justify-between gap-3">
                    <p class="flex items-baseline gap-2">
                        <span class="{{ $big }}">{{ number_format($signInStats['last_7']) }}</span>
                        <span class="text-sm text-text-muted">by {{ number_format($signInStats['people_7']) }} {{ Str::plural('person', $signInStats['people_7']) }}</span>
                    </p>
                    <svg viewBox="0 0 100 30" class="h-8 w-24 shrink-0" preserveAspectRatio="none" role="img" aria-label="Sign-ins per day, last 14 days">
                        <polyline points="{{ $spark }}" fill="none" stroke="{{ $web }}" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round" />
                    </svg>
                </div>
                <p @class(['mt-1 text-xs font-semibold', 'text-safe' => $signInDelta['up'], 'text-alarm' => $signInDelta['down'], 'text-text-muted' => ! $signInDelta['up'] && ! $signInDelta['down']])>
                    {{ $signInDelta['text'] }} vs the 7 days before ({{ number_format($signInStats['previous_7']) }})
                </p>
            </div>

            <a href="{{ route('admin.concerns.index') }}" class="{{ $card }} group p-5 transition hover:border-primary">
                <h2 class="{{ $tileLabel }}">Open concerns</h2>
                <p class="mt-2 flex items-baseline gap-2">
                    <span class="{{ $big }}">{{ number_format($concerns['open']) }}</span>
                    <span class="text-sm text-text-muted">{{ number_format($concerns['last_30']) }} in the last 30 days</span>
                </p>
                <p class="mt-1 text-xs text-text-muted">
                    @if ($concerns['median_reply_hours'] !== null)
                        First reply in about <span class="font-semibold text-text-primary">{{ $concerns['median_reply_hours'] < 1 ? 'under an hour' : $concerns['median_reply_hours'].' h' }}</span> (median)
                    @else
                        No replies in the last 30 days yet
                    @endif
                </p>
                <p class="mt-3 text-xs font-semibold text-primary underline decoration-secondary/60 underline-offset-4 group-hover:decoration-primary">Open the inbox</p>
            </a>

            <div class="{{ $card }} p-5">
                <h2 class="{{ $tileLabel }}">Alert reach</h2>
                <p class="mt-2 flex items-baseline gap-2">
                    <span class="{{ $big }}">{{ number_format($reach['phones'] + $reach['browsers']) }}</span>
                    <span class="text-sm text-text-muted">devices get alerts</span>
                </p>
                <p class="mt-1 text-xs text-text-muted">
                    <span class="font-semibold text-text-primary">{{ number_format($reach['phones']) }}</span> {{ Str::plural('phone', $reach['phones']) }} with the app,
                    <span class="font-semibold text-text-primary">{{ number_format($reach['browsers']) }}</span> {{ Str::plural('browser', $reach['browsers']) }}
                </p>
                <p class="mt-3 text-xs text-text-muted">{{ number_format($alerts['last_90']) }} {{ Str::plural('alert', $alerts['last_90']) }} in 90 days, {{ number_format($alerts['drills_90']) }} {{ Str::plural('drill', $alerts['drills_90']) }}</p>
            </div>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
            {{-- Sign-ins per day: stacked columns (website below, app on top), a 2px gap between the parts. Hover or
                 focus a day for its numbers; the table below has them all. --}}
            <section aria-labelledby="sign-ins-heading" class="{{ $card }} p-5" data-chart="sign-ins">
                <div class="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 id="sign-ins-heading" class="font-semibold">Sign-ins, last 14 days</h2>
                    <ul class="flex gap-4 text-xs text-text-muted" aria-label="Legend">
                        <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: {{ $web }}"></span>Website</li>
                        <li class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm" style="background: {{ $app }}"></span>App</li>
                    </ul>
                </div>

                <div class="relative mt-5 h-52 pl-8">
                    {{-- Gridlines and their values (hairline, recessive). --}}
                    @foreach ($gridlines as $line)
                        <div class="absolute right-0 left-8 border-t border-border" style="bottom: {{ $line / $chartTop * 100 }}%" aria-hidden="true">
                            <span class="absolute -left-8 -translate-y-1/2 text-[11px] text-text-muted tabular-nums">{{ number_format($line) }}</span>
                        </div>
                    @endforeach
                    <ol class="relative flex h-full items-end justify-between gap-1">
                        @foreach ($signInStats['days'] as $index => $day)
                            @php($total = $day['web'] + $day['app'])
                            <li tabindex="0" aria-label="{{ $dateLabel($day['date']) }}: {{ $total }} sign-ins, {{ $day['web'] }} on the website, {{ $day['app'] }} in the app"
                                class="group relative flex h-full flex-1 cursor-default flex-col items-center justify-end outline-none">
                                <div class="flex w-full max-w-6 flex-col-reverse gap-0.5" style="height: {{ $total / $chartTop * 100 }}%">
                                    @if ($day['web'] > 0)
                                        <span @class(['w-full', 'rounded-t' => $day['app'] === 0]) style="flex-grow: {{ $day['web'] }}; background: {{ $web }}"></span>
                                    @endif
                                    @if ($day['app'] > 0)
                                        <span class="w-full rounded-t" style="flex-grow: {{ $day['app'] }}; background: {{ $app }}"></span>
                                    @endif
                                </div>
                                <span class="absolute -bottom-5 text-[11px] text-text-muted">{{ $index % 2 === (count($signInStats['days']) - 1) % 2 ? $dayLabel($day['date']) : '' }}</span>
                                {{-- Tooltip: the day and its numbers, on hover or keyboard focus. --}}
                                <span class="pointer-events-none absolute bottom-full z-10 mb-2 hidden w-max rounded-lg border border-border bg-surface px-3 py-2 text-xs shadow-lg group-hover:block group-focus:block">
                                    <span class="block font-semibold">{{ $dateLabel($day['date']) }}</span>
                                    <span class="mt-1 flex items-center gap-1.5"><span class="size-2 rounded-sm" style="background: {{ $web }}"></span>Website <span class="ml-auto pl-3 font-semibold tabular-nums">{{ $day['web'] }}</span></span>
                                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm" style="background: {{ $app }}"></span>App <span class="ml-auto pl-3 font-semibold tabular-nums">{{ $day['app'] }}</span></span>
                                </span>
                            </li>
                        @endforeach
                    </ol>
                </div>

                <details class="mt-8 text-sm">
                    <summary class="cursor-pointer text-xs font-semibold text-primary">Show as a table</summary>
                    <table class="mt-2 w-full text-left text-xs tabular-nums">
                        <thead class="text-text-muted"><tr><th class="py-1 font-medium">Day</th><th class="py-1 font-medium">Website</th><th class="py-1 font-medium">App</th><th class="py-1 font-medium">Total</th></tr></thead>
                        <tbody class="divide-y divide-border">
                            @foreach (array_reverse($signInStats['days']) as $day)
                                <tr><td class="py-1">{{ $dateLabel($day['date']) }}</td><td>{{ $day['web'] }}</td><td>{{ $day['app'] }}</td><td class="font-semibold">{{ $day['web'] + $day['app'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            </section>

            {{-- Accounts by role and students by college: ranked bars, one hue, the value at the tip. --}}
            <section aria-labelledby="accounts-heading" class="{{ $card }} p-5">
                <h2 id="accounts-heading" class="font-semibold">Accounts</h2>
                <ul class="mt-4 flex flex-col gap-3">
                    @foreach ($roleRows as $row)
                        @php($count = $byRole[$row['role']->value])
                        <li class="grid grid-cols-[4.5rem_1fr_auto] items-center gap-3 text-sm">
                            <span>{{ $row['label'] }}</span>
                            <span class="h-2.5 rounded-r bg-bg-light"><span class="block h-full rounded-r" style="width: {{ $accounts['total'] > 0 ? round($count / $accounts['total'] * 100) : 0 }}%; background: {{ $web }}"></span></span>
                            <span class="w-10 text-right font-semibold tabular-nums">{{ number_format($count) }}</span>
                        </li>
                    @endforeach
                </ul>

                <h3 class="mt-6 border-t border-border pt-5 text-sm font-semibold">Most students by college</h3>
                @if ($analytics['colleges'] === [])
                    <p class="mt-3 text-sm text-text-muted">No students have registered yet.</p>
                @else
                    <ul class="mt-3 flex flex-col gap-3">
                        @foreach ($analytics['colleges'] as $college)
                            <li class="text-sm">
                                <div class="flex justify-between gap-3">
                                    <span class="truncate">{{ $college['name'] }}</span>
                                    <span class="font-semibold tabular-nums">{{ number_format($college['students']) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 rounded-full bg-bg-light">
                                    <div class="h-full rounded-full" style="width: {{ round($college['students'] / max($totalStudents, 1) * 100) }}%; background: {{ $web }}"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Concerns of the last 30 days by topic. --}}
            <section aria-labelledby="topics-heading" class="{{ $card }} p-5">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 id="topics-heading" class="font-semibold">Concerns by topic</h2>
                    <p class="text-xs text-text-muted">Last 30 days</p>
                </div>
                @if ($concerns['last_30'] === 0)
                    <p class="mt-4 text-sm text-text-muted">No concerns in the last 30 days.</p>
                @else
                    <ul class="mt-4 flex flex-col gap-2.5">
                        @foreach ($concerns['by_topic'] as $topic)
                            <li class="grid grid-cols-[5.5rem_1fr_auto] items-center gap-3 text-sm">
                                <span>{{ $topic['label'] }}</span>
                                <span class="h-2.5 rounded-r bg-bg-light"><span class="block h-full rounded-r" style="width: {{ round($topic['count'] / $maxTopic * 100) }}%; background: {{ $web }}"></span></span>
                                <span class="w-8 text-right font-semibold tabular-nums">{{ $topic['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="mt-5 border-t border-border pt-4 text-xs text-text-muted">
                    <span class="font-semibold text-text-primary">{{ number_format($concerns['open']) }}</span> open,
                    <span class="font-semibold text-text-primary">{{ number_format($concerns['resolved']) }}</span> resolved in all
                </p>
            </section>

            {{-- How many answered the last emergency (I'm safe or I need help), out of every account except admins. --}}
            <section aria-labelledby="readiness-heading" class="{{ $card }} p-5">
                <div class="flex items-baseline justify-between gap-3">
                    <h2 id="readiness-heading" class="font-semibold">Last emergency</h2>
                    <a href="{{ route('admin.alerts.index') }}" class="text-xs font-semibold text-primary underline decoration-secondary/60 underline-offset-4 hover:decoration-primary">Alerts</a>
                </div>
                @if ($lastAlert === null)
                    <p class="mt-4 text-sm text-text-muted">No emergency or drill has been sent yet. Run a drill to see how many answer.</p>
                @else
                    <p class="mt-3 text-sm">
                        {{ $lastAlert['title'] }}
                        @if ($lastAlert['is_drill'])
                            <span class="ml-1 rounded bg-hazard/20 px-1.5 py-0.5 text-[11px] font-bold tracking-wider text-stone-800">DRILL</span>
                        @endif
                    </p>
                    <p class="text-xs text-text-muted">{{ \Carbon\Carbon::parse($lastAlert['sent_at'])->timezone('Asia/Manila')->format('M j, g:i A') }}</p>
                    <p class="mt-4 flex items-baseline gap-2">
                        <span class="{{ $big }}">{{ $answeredShare }}%</span>
                        <span class="text-sm text-text-muted">answered ({{ number_format($lastAlert['answered']) }} of {{ number_format($lastAlert['accounts']) }})</span>
                    </p>
                    <div class="mt-3 h-2.5 rounded-full bg-bg-light" role="img" aria-label="{{ $answeredShare }}% answered">
                        <div class="h-full rounded-full bg-safe" style="width: {{ $answeredShare }}%"></div>
                    </div>
                    @if ($lastAlert['need_help'] > 0)
                        <p class="mt-3 text-xs font-semibold text-alarm">{{ $lastAlert['need_help'] }} asked for help</p>
                    @endif
                @endif
            </section>

            {{-- The rooms people start directions to, and save. --}}
            <section aria-labelledby="places-heading" class="{{ $card }} p-5">
                <h2 id="places-heading" class="font-semibold">Where people go</h2>
                @if ($places['started'] === [])
                    <p class="mt-4 text-sm text-text-muted">No one has started directions to a room yet.</p>
                @else
                    <p class="mt-1 text-xs text-text-muted">Rooms people start directions to</p>
                    <ul class="mt-3 flex flex-col gap-2.5">
                        @foreach ($places['started'] as $place)
                            <li class="grid grid-cols-[minmax(0,7rem)_1fr_auto] items-center gap-3 text-sm">
                                <span class="truncate">{{ $place['name'] }}</span>
                                <span class="h-2.5 rounded-r bg-bg-light"><span class="block h-full rounded-r" style="width: {{ round($place['count'] / $maxStarted * 100) }}%; background: {{ $web }}"></span></span>
                                <span class="w-8 text-right font-semibold tabular-nums">{{ $place['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($places['saved'] !== [])
                    <p class="mt-5 border-t border-border pt-4 text-xs text-text-muted">
                        Most saved: {{ collect($places['saved'])->map(fn ($place) => $place['name'].' ('.$place['count'].')')->join(', ') }}
                    </p>
                @endif
            </section>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
            {{-- The live campus map: the thing admins are actually maintaining. --}}
            <section aria-labelledby="map-heading" class="overflow-hidden {{ $card }}">
                <div class="flex items-baseline justify-between gap-4 px-5 pt-4 pb-3">
                    <h2 id="map-heading" class="font-semibold">Campus map</h2>
                    <a href="{{ route('admin.map.index') }}" class="text-xs font-semibold text-primary underline decoration-secondary/60 underline-offset-4 hover:decoration-primary">Map data</a>
                </div>

                <figure data-campus-map-card class="group relative border-y border-border bg-[#d5e8c3]">
                    <div data-campus-map data-map-url="{{ route('api.map') }}" class="aspect-4/3 w-full sm:aspect-16/9"
                        role="region" aria-label="Interactive 3D map of the Tagoloan Community College campus"></div>
                    <p data-campus-map-label hidden
                        class="pointer-events-none absolute bottom-4 left-4 rounded-lg bg-white/95 px-3 py-1.5 text-xs font-semibold text-primary shadow-md"></p>
                    <p class="absolute inset-0 hidden items-center justify-center text-sm text-text-muted group-data-failed:flex">
                        The campus map couldn't load.
                    </p>
                    <figcaption class="sr-only">Tap a building to see its name.</figcaption>
                </figure>

                <dl class="grid grid-cols-2 divide-border sm:grid-cols-4 sm:divide-x">
                    @foreach ($mapCounts as $label => $count)
                        <div class="px-5 py-4">
                            <dt class="text-xs text-text-muted">{{ $label }}</dt>
                            <dd class="mt-0.5 text-2xl font-semibold tabular-nums text-primary">{{ $count }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section aria-labelledby="signups-heading" class="{{ $card }} p-5">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 id="signups-heading" class="font-semibold">Recent sign-ups</h2>
                    <a href="{{ route('admin.accounts.index') }}" class="text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                        View all
                    </a>
                </div>

                @if ($recentSignUps->isEmpty())
                    <p class="mt-3 text-sm text-text-muted">No one has registered yet.</p>
                @else
                    <ul class="mt-2 divide-y divide-border">
                        @foreach ($recentSignUps as $newAccount)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-semibold">{{ $newAccount->full_name }}</p>
                                    <p class="truncate text-xs text-text-muted tabular-nums">
                                        {{ collect([$newAccount->school_id ?? $newAccount->email, $newAccount->college?->name])->filter()->join(' · ') }}
                                    </p>
                                </div>
                                <div class="flex shrink-0 items-center gap-3">
                                    <span @class([
                                        'rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap',
                                        'bg-primary/10 text-primary' => $newAccount->role === UserRole::Student,
                                        'bg-secondary/20 text-[#7a5e12]' => $newAccount->role !== UserRole::Student,
                                    ])>{{ $newAccount->role->label() }}</span>
                                    <span class="w-24 text-right text-xs whitespace-nowrap text-text-muted tabular-nums">{{ $joinedAt($newAccount->created_at) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        {{-- Real data: users.last_seen_at (TrackLastSeen) and login_activities (LoginActivityLogger). Times in campus time. --}}
        @php($campusTime = fn ($time) => $time?->timezone('Asia/Manila')->format('M j, g:i A'))
        <section id="user-activity" aria-labelledby="activity-heading" class="scroll-mt-20 overflow-hidden rounded-2xl border border-border bg-surface">
            <div class="flex flex-wrap items-end justify-between gap-4 px-5 pt-4 pb-4">
                <div>
                    <h2 id="activity-heading" class="font-semibold">User activity</h2>
                    <p class="mt-0.5 text-sm text-text-muted">Who's online and recent sign-ins. Sign-in records are kept {{ LoginActivity::RETENTION_DAYS }} days.</p>
                </div>

                <form method="GET" action="{{ route('admin.dashboard') }}#user-activity" role="search" class="flex flex-wrap items-center gap-2">
                    <label for="activity-role" class="sr-only">Role</label>
                    <select id="activity-role" name="role"
                        class="rounded-lg border border-border bg-bg-light py-2 pr-8 pl-3 text-sm focus:border-secondary focus:bg-white focus:ring-4 focus:ring-secondary/20 focus:outline-none">
                        <option value="">All roles</option>
                        @foreach (UserRole::cases() as $roleOption)
                            <option value="{{ $roleOption->value }}" @selected($activityFilters['role'] === $roleOption)>{{ $roleOption->label() }}</option>
                        @endforeach
                    </select>
                    <label for="activity-search" class="sr-only">Search by name, student ID or email</label>
                    <input id="activity-search" type="search" name="q" value="{{ $activityFilters['q'] }}" maxlength="100"
                        placeholder="Name, student ID or email"
                        class="w-56 rounded-lg border border-border bg-bg-light px-3 py-2 text-sm placeholder:text-text-muted/80 focus:border-secondary focus:bg-white focus:ring-4 focus:ring-secondary/20 focus:outline-none max-sm:flex-1">
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white transition hover:bg-primary-hover">Filter</button>
                    @if ($activityFilters['active'])
                        <a href="{{ route('admin.dashboard') }}#user-activity"
                            class="px-2 py-2 text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">Clear</a>
                    @endif
                </form>
            </div>

            <div class="flex flex-col divide-y divide-border border-t border-border">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-3 px-5 py-4">
                    <h3 class="text-sm font-semibold">Active now</h3>

                    @if ($onlineUsers->isEmpty())
                        <p class="text-sm text-text-muted">{{ $activityFilters['active'] ? 'No one matching these filters is online.' : 'No one is online right now.' }}</p>
                    @else
                        <p class="text-sm text-text-muted">
                            <span class="font-semibold tabular-nums text-primary">{{ number_format($onlineCount) }}</span>
                            online in the last {{ User::ONLINE_MINUTES }} minutes
                        </p>

                        <ul class="flex flex-wrap gap-2">
                            @foreach ($onlineUsers as $onlineUser)
                                <li class="flex items-center gap-2 rounded-full border border-border py-1 pr-3 pl-1 text-sm">
                                    <span class="relative flex size-7 shrink-0 items-center justify-center rounded-full bg-secondary/20 text-xs font-semibold text-primary" aria-hidden="true">
                                        {{ $onlineUser->initials }}
                                        <span class="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full border-2 border-surface bg-emerald-500"></span>
                                    </span>
                                    <span class="font-semibold">{{ $onlineUser->full_name }}</span>
                                    <span class="text-xs text-text-muted">
                                        {{ $onlineUser->role->label() }} · {{ $onlineUser->latestLoginActivity?->device ?? 'Unknown device' }} · seen {{ $onlineUser->last_seen_at->diffForHumans() }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        @if ($onlineCount > $onlineUsers->count())
                            <p class="text-xs text-text-muted">and {{ number_format($onlineCount - $onlineUsers->count()) }} more</p>
                        @endif
                    @endif
                </div>

                <div class="min-w-0">
                    <h3 class="px-5 pt-5 pb-3 text-sm font-semibold">Recent sign-ins</h3>
                    @if ($signIns->isEmpty())
                        <p class="px-5 pb-5 text-sm text-text-muted">{{ $activityFilters['active'] ? 'No sign-ins match these filters.' : 'No one has signed in yet.' }}</p>
                    @else
                        <div class="overflow-x-auto border-t border-border">
                            <table class="w-full min-w-3xl text-left text-sm">
                                <thead class="bg-bg-light text-xs text-text-muted">
                                    <tr>
                                        <th scope="col" class="px-5 py-2.5 font-medium">User</th>
                                        <th scope="col" class="px-5 py-2.5 font-medium">Role</th>
                                        <th scope="col" class="px-5 py-2.5 font-medium">Channel</th>
                                        <th scope="col" class="px-5 py-2.5 font-medium">Device</th>
                                        <th scope="col" class="px-5 py-2.5 font-medium">IP address</th>
                                        <th scope="col" class="px-5 py-2.5 font-medium">Signed in</th>
                                        <th scope="col" class="px-5 py-2.5 font-medium">Signed out</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    @foreach ($signIns as $signIn)
                                        <tr class="transition hover:bg-bg-light/60">
                                            <td class="px-5 py-3">
                                                <p class="font-semibold whitespace-nowrap">{{ $signIn->user->full_name }}</p>
                                                <p class="text-xs text-text-muted tabular-nums">{{ $signIn->user->school_id ?? $signIn->user->email }}</p>
                                            </td>
                                            <td class="px-5 py-3">
                                                <span @class([
                                                    'rounded-md px-2 py-0.5 text-xs font-semibold whitespace-nowrap',
                                                    'bg-primary/10 text-primary' => $signIn->user->role === UserRole::Student,
                                                    'bg-secondary/20 text-[#7a5e12]' => $signIn->user->role !== UserRole::Student,
                                                ])>{{ $signIn->user->role->label() }}</span>
                                            </td>
                                            <td class="px-5 py-3 whitespace-nowrap">{{ $signIn->channel->label() }}</td>
                                            <td class="px-5 py-3">
                                                <p class="whitespace-nowrap">{{ $signIn->device ?? 'Unknown device' }}</p>
                                                <p class="text-xs text-text-muted">{{ $signIn->device_type->label() }}</p>
                                            </td>
                                            <td class="px-5 py-3 text-text-muted tabular-nums">{{ $signIn->ip_address ?? '—' }}</td>
                                            <td class="px-5 py-3 whitespace-nowrap tabular-nums">{{ $campusTime($signIn->logged_in_at) }}</td>
                                            <td class="px-5 py-3 whitespace-nowrap text-text-muted tabular-nums">
                                                @if ($signIn->logged_out_at)
                                                    {{ $campusTime($signIn->logged_out_at) }}
                                                @else
                                                    <span aria-hidden="true">—</span><span class="sr-only">Not signed out</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <nav aria-label="Sign-in pages" class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-5 py-3 text-sm">
                            <p class="text-text-muted tabular-nums">Showing {{ $signIns->firstItem() }}–{{ $signIns->lastItem() }} of {{ number_format($signIns->total()) }}</p>
                            <div class="flex gap-2">
                                @foreach (['Previous' => $signIns->previousPageUrl(), 'Next' => $signIns->nextPageUrl()] as $label => $url)
                                    @if ($url)
                                        <a href="{{ $url }}" rel="{{ $label === 'Next' ? 'next' : 'prev' }}"
                                            class="rounded-lg border border-border px-3 py-1.5 font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">{{ $label }}</a>
                                    @else
                                        <span class="rounded-lg border border-border px-3 py-1.5 text-text-muted/60" aria-disabled="true">{{ $label }}</span>
                                    @endif
                                @endforeach
                            </div>
                        </nav>
                    @endif
                </div>
            </div>
        </section>
    </div>
@endsection
