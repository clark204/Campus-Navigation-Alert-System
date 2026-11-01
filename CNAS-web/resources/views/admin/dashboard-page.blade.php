{{-- Admin overview. Design only: every figure below is placeholder data until the admin queries exist. --}}
@extends('layout.admin-layout')

@section('title', 'Overview')
@section('heading', 'Overview')

@php
    $mapCounts = [
        ['label' => 'Buildings', 'value' => 16],
        ['label' => 'Entrances', 'value' => 29],
        ['label' => 'Walkways', 'value' => 13],
        ['label' => 'Stairs', 'value' => 2],
    ];

    $attention = [
        ['level' => 'missing', 'title' => '1 building has no floor count', 'detail' => 'Its 3D height falls back to one floor on the map.', 'action' => 'Edit building'],
        ['level' => 'check', 'title' => '7 college names are placeholders', 'detail' => 'C03 to C09 still need checking against the TCC Student Handbook.', 'action' => 'Review colleges'],
        ['level' => 'check', 'title' => 'Landmark names are placeholders', 'detail' => 'Students see these names in walking directions.', 'action' => 'Edit landmarks'],
    ];

    $roles = [
        ['label' => 'Students', 'count' => 1186, 'bar' => 'bg-primary'],
        ['label' => 'Faculty', 'count' => 42, 'bar' => 'bg-secondary'],
        ['label' => 'Staff', 'count' => 17, 'bar' => 'bg-primary/40'],
        ['label' => 'Admins', 'count' => 3, 'bar' => 'bg-text-muted'],
    ];
    $totalAccounts = array_sum(array_column($roles, 'count'));

    $topColleges = [
        ['name' => 'College of Information Technology', 'count' => 412],
        ['name' => 'College of Criminology', 'count' => 268],
        ['name' => 'College of Teacher Education', 'count' => 197],
    ];

    $signUps = [
        ['name' => 'Angelica Dela Cruz', 'school_id' => '2026-00412', 'college' => 'College of Information Technology', 'role' => 'Student', 'joined' => 'Today, 9:14 AM'],
        ['name' => 'Mark Anthony Sabado', 'school_id' => '2026-00409', 'college' => 'College of Criminology', 'role' => 'Student', 'joined' => 'Today, 8:02 AM'],
        ['name' => 'Rhea Mae Pacana', 'school_id' => '2026-00398', 'college' => 'College of Midwifery', 'role' => 'Student', 'joined' => 'Yesterday'],
        ['name' => 'Jonel Bacus', 'school_id' => 'F-0118', 'college' => 'College of Teacher Education', 'role' => 'Faculty', 'joined' => 'Oct 27'],
        ['name' => 'Kristine Lagrosas', 'school_id' => '2026-00377', 'college' => 'College of Business Administration', 'role' => 'Student', 'joined' => 'Oct 26'],
    ];
@endphp

@section('content.admin')
    <div class="mx-auto flex max-w-7xl flex-col gap-6">
        {{-- Real data (DashboardController), unlike the placeholder figures below. --}}
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
            <a href="{{ route('navigate') }}"
                class="inline-flex items-center gap-2 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">
                Open walking directions
            </a>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(0,1fr)]">
            {{-- The live campus map is the centrepiece: the thing admins are actually maintaining. --}}
            <section aria-labelledby="map-heading" class="overflow-hidden rounded-2xl border border-border bg-surface">
                <div class="flex items-baseline justify-between gap-4 px-5 pt-4 pb-3">
                    <h2 id="map-heading" class="font-semibold">Campus map</h2>
                    <p class="text-xs text-text-muted">Last edited Oct 28</p>
                </div>

                <figure data-campus-map-card class="group relative border-y border-border bg-[#e9eee4]">
                    <div data-campus-map data-maps-url="{{ asset('maps') }}" class="aspect-4/3 w-full sm:aspect-16/9"
                        role="region" aria-label="Interactive 3D map of the Tagoloan Community College campus"></div>
                    <p data-campus-map-label hidden
                        class="pointer-events-none absolute bottom-4 left-4 rounded-lg bg-white/95 px-3 py-1.5 text-xs font-semibold text-primary shadow-md"></p>
                    <p class="absolute inset-0 hidden items-center justify-center text-sm text-text-muted group-data-failed:flex">
                        The campus map couldn't load.
                    </p>
                    <figcaption class="sr-only">Tap a building to see its name.</figcaption>
                </figure>

                <dl class="grid grid-cols-2 divide-border sm:grid-cols-4 sm:divide-x">
                    @foreach ($mapCounts as $count)
                        <div class="px-5 py-4">
                            <dt class="text-xs text-text-muted">{{ $count['label'] }}</dt>
                            <dd class="mt-0.5 text-2xl font-semibold tabular-nums text-primary">{{ $count['value'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </section>

            <section aria-labelledby="attention-heading" class="flex flex-col rounded-2xl border border-border bg-surface">
                <div class="flex items-baseline justify-between gap-4 px-5 pt-4 pb-3">
                    <h2 id="attention-heading" class="font-semibold">Needs attention</h2>
                    <p class="text-xs text-text-muted">{{ count($attention) }} open</p>
                </div>

                <ul class="flex-1 divide-y divide-border border-t border-border">
                    @foreach ($attention as $item)
                        <li class="flex gap-3 px-5 py-4">
                            <span @class([
                                'mt-1.5 size-2.5 shrink-0 rounded-full',
                                'bg-primary' => $item['level'] === 'missing',
                                'bg-secondary' => $item['level'] === 'check',
                            ])>
                                <span class="sr-only">{{ $item['level'] === 'missing' ? 'Missing data:' : 'To check:' }}</span>
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">{{ $item['title'] }}</p>
                                <p class="mt-0.5 text-sm text-text-muted">{{ $item['detail'] }}</p>
                                <a href="#" class="mt-2 inline-block text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                                    {{ $item['action'] }}
                                </a>
                            </div>
                        </li>
                    @endforeach
                </ul>

                <p class="flex items-center gap-4 border-t border-border px-5 py-3 text-xs text-text-muted">
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-primary"></span>Missing data</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-secondary"></span>To check</span>
                </p>
            </section>
        </div>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.65fr)]">
            <section aria-labelledby="accounts-heading" class="rounded-2xl border border-border bg-surface p-5">
                <h2 id="accounts-heading" class="font-semibold">Accounts</h2>
                <p class="mt-3 flex items-baseline gap-2">
                    <span class="text-4xl font-semibold tracking-tight tabular-nums text-primary">{{ number_format($totalAccounts) }}</span>
                    <span class="text-sm text-text-muted">registered, 38 this week</span>
                </p>

                <div class="mt-5 flex h-3 gap-0.5 overflow-hidden rounded-full" role="img"
                    aria-label="Accounts by role: {{ collect($roles)->map(fn ($r) => $r['count'].' '.strtolower($r['label']))->join(', ') }}">
                    @foreach ($roles as $role)
                        <span class="{{ $role['bar'] }} min-w-1" style="flex-grow: {{ $role['count'] }}"></span>
                    @endforeach
                </div>

                <ul class="mt-5 grid grid-cols-2 gap-x-6 gap-y-3">
                    @foreach ($roles as $role)
                        <li class="flex items-center justify-between gap-2 text-sm">
                            <span class="flex items-center gap-2">
                                <span class="size-2.5 rounded-sm {{ $role['bar'] }}"></span>{{ $role['label'] }}
                            </span>
                            <span class="font-semibold tabular-nums">{{ number_format($role['count']) }}</span>
                        </li>
                    @endforeach
                </ul>

                <h3 class="mt-6 border-t border-border pt-5 text-sm font-semibold">Most students by college</h3>
                <ul class="mt-3 flex flex-col gap-3">
                    @foreach ($topColleges as $college)
                        <li class="text-sm">
                            <div class="flex justify-between gap-3">
                                <span class="truncate">{{ $college['name'] }}</span>
                                <span class="font-semibold tabular-nums">{{ number_format($college['count']) }}</span>
                            </div>
                            <div class="mt-1.5 h-1.5 rounded-full bg-bg-light">
                                <div class="h-full rounded-full bg-primary/70" style="width: {{ round($college['count'] / $roles[0]['count'] * 100) }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section aria-labelledby="signups-heading" class="overflow-hidden rounded-2xl border border-border bg-surface">
                <div class="flex items-baseline justify-between gap-4 px-5 pt-4 pb-3">
                    <h2 id="signups-heading" class="font-semibold">Recent sign-ups</h2>
                    <a href="#" class="text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                        View all accounts
                    </a>
                </div>

                <div class="overflow-x-auto border-t border-border">
                    <table class="w-full min-w-xl text-left text-sm">
                        <thead class="bg-bg-light text-xs text-text-muted">
                            <tr>
                                <th scope="col" class="px-5 py-2.5 font-medium">Name</th>
                                <th scope="col" class="px-5 py-2.5 font-medium">College</th>
                                <th scope="col" class="px-5 py-2.5 font-medium">Role</th>
                                <th scope="col" class="px-5 py-2.5 text-right font-medium">Joined</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach ($signUps as $user)
                                <tr class="transition hover:bg-bg-light/60">
                                    <td class="px-5 py-3">
                                        <p class="font-semibold whitespace-nowrap">{{ $user['name'] }}</p>
                                        <p class="text-xs text-text-muted tabular-nums">{{ $user['school_id'] }}</p>
                                    </td>
                                    <td class="px-5 py-3 text-text-muted">{{ $user['college'] }}</td>
                                    <td class="px-5 py-3">
                                        <span @class([
                                            'rounded-md px-2 py-0.5 text-xs font-semibold',
                                            'bg-primary/10 text-primary' => $user['role'] === 'Student',
                                            'bg-secondary/20 text-[#7a5e12]' => $user['role'] !== 'Student',
                                        ])>{{ $user['role'] }}</span>
                                    </td>
                                    <td class="px-5 py-3 text-right whitespace-nowrap text-text-muted">{{ $user['joined'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
@endsection
