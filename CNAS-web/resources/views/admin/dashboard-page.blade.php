{{-- Admin overview. Real data (DashboardController): active alert, term banner, Campus map counts, Accounts with recent
     sign-ups, and User activity (who is online and recent sign-ins). --}}
@extends('layout.admin-layout')
@use('App\Enums\UserRole')
@use('App\Models\LoginActivity')
@use('App\Models\User')

@section('title', 'Overview')
@section('heading', 'Overview')

@section('content.admin')
    <div class="mx-auto flex max-w-7xl flex-col gap-6">
        {{-- Real data (DashboardController). --}}
        @if ($activeAlert)
            <div role="alert" class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-alarm px-4 py-3 text-sm text-white">
                <p><strong>{{ $activeAlert->is_drill ? 'Drill alert' : 'Emergency alert' }} active:</strong> {{ $activeAlert->title }}</p>
                <a href="{{ route('admin.alerts.index') }}" class="rounded-lg bg-white px-3 py-1.5 font-semibold text-alarm transition hover:bg-red-50">Manage alert</a>
            </div>
        @endif
        @if ($currentTerm === null)
            <p role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                Registration is closed: no academic term is set as current.
                <a href="{{ route('admin.academic-terms.index') }}" class="font-semibold underline underline-offset-4">Set the current term</a>
            </p>
        @elseif ($termMayBeOutdated)
            <p role="status" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                The current term ({{ $currentTerm->label() }}) was set {{ $currentTerm->set_current_at->diffForHumans() }}.
                Is it still right? Students can only register with a COR for this term.
                <a href="{{ route('admin.academic-terms.index') }}" class="font-semibold underline underline-offset-4">Review academic term</a>
            </p>
        @endif

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Campus overview</h1>
                <p class="mt-1 text-sm text-text-muted">What's on the map, what still needs work, and who's joining TCC NAV.</p>
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

        {{-- Real data from users and colleges (DashboardController). Dates in campus time. --}}
        @php
            $roleRows = [
                ['role' => UserRole::Student, 'label' => 'Students', 'bar' => 'bg-primary'],
                ['role' => UserRole::Faculty, 'label' => 'Faculty', 'bar' => 'bg-secondary'],
                ['role' => UserRole::Staff, 'label' => 'Staff', 'bar' => 'bg-primary/40'],
                ['role' => UserRole::Admin, 'label' => 'Admins', 'bar' => 'bg-text-muted'],
            ];
            $totalAccounts = $accountsByRole->sum();
            $totalStudents = $accountsByRole[UserRole::Student->value];
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
        @endphp
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
            {{-- The live campus map is the centrepiece: the thing admins are actually maintaining. --}}
            <section aria-labelledby="map-heading" class="overflow-hidden rounded-2xl border border-border bg-surface">
                <div class="flex items-baseline justify-between gap-4 px-5 pt-4 pb-3">
                    <h2 id="map-heading" class="font-semibold">Campus map</h2>
                    <p class="text-xs text-text-muted">Last edited Oct 28</p>
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

            <section aria-labelledby="accounts-heading" class="rounded-2xl border border-border bg-surface p-5">
                <h2 id="accounts-heading" class="font-semibold">Accounts</h2>
                <p class="mt-3 flex items-baseline gap-2">
                    <span class="text-4xl font-semibold tracking-tight tabular-nums text-primary">{{ number_format($totalAccounts) }}</span>
                    <span class="text-sm text-text-muted">registered, {{ number_format($newAccountsThisWeek) }} this week</span>
                </p>

                @if ($totalAccounts > 0)
                    <div class="mt-5 flex h-3 gap-0.5 overflow-hidden rounded-full" role="img"
                        aria-label="Accounts by role: {{ collect($roleRows)->map(fn ($row) => $accountsByRole[$row['role']->value].' '.strtolower($row['label']))->join(', ') }}">
                        @foreach ($roleRows as $row)
                            @if ($accountsByRole[$row['role']->value] > 0)
                                <span class="{{ $row['bar'] }} min-w-1" style="flex-grow: {{ $accountsByRole[$row['role']->value] }}"></span>
                            @endif
                        @endforeach
                    </div>
                @endif

                <ul class="mt-5 grid grid-cols-2 gap-x-6 gap-y-3">
                    @foreach ($roleRows as $row)
                        <li class="flex items-center justify-between gap-2 text-sm">
                            <span class="flex items-center gap-2">
                                <span class="size-2.5 rounded-sm {{ $row['bar'] }}"></span>{{ $row['label'] }}
                            </span>
                            <span class="font-semibold tabular-nums">{{ number_format($accountsByRole[$row['role']->value]) }}</span>
                        </li>
                    @endforeach
                </ul>

                <h3 class="mt-6 border-t border-border pt-5 text-sm font-semibold">Most students by college</h3>
                @if ($topColleges->isEmpty())
                    <p class="mt-3 text-sm text-text-muted">No students have registered yet.</p>
                @else
                    <ul class="mt-3 flex flex-col gap-3">
                        @foreach ($topColleges as $college)
                            <li class="text-sm">
                                <div class="flex justify-between gap-3">
                                    <span class="truncate">{{ $college->name }}</span>
                                    <span class="font-semibold tabular-nums">{{ number_format($college->students_count) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 rounded-full bg-bg-light">
                                    <div class="h-full rounded-full bg-primary/70" style="width: {{ round($college->students_count / max($totalStudents, 1) * 100) }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-6 flex items-baseline justify-between gap-4 border-t border-border pt-5">
                    <h3 id="signups-heading" class="text-sm font-semibold">Recent sign-ups</h3>
                    @if (Route::has('admin.accounts.index'))
                        <a href="{{ route('admin.accounts.index') }}" class="text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                            View all
                        </a>
                    @endif
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
