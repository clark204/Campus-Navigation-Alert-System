{{-- Admin "Accounts" (Admin\AccountController@index). The composition bar is the role filter: each segment's width is
     that role's share of all accounts, and it links to the list filtered to that role. ?q= searches name, school ID
     and email on the server (people's details never go to the browser in bulk); ?trusted=1 lists who can send alerts.
     Newest accounts first, 10 per page. The search is live (resources/js/accounts.js swaps in admin/accounts-results
     as you type); without JavaScript, Enter submits it. --}}
@extends('layout.admin-layout')
@use('App\Enums\UserRole')

@section('title', 'Accounts')
@section('heading', 'Accounts')

@php
    $total = $countsByRole->sum();
    $segments = [
        ['role' => UserRole::Student, 'label' => 'Students', 'bar' => 'bg-primary', 'dot' => 'bg-primary'],
        ['role' => UserRole::Faculty, 'label' => 'Faculty', 'bar' => 'bg-secondary', 'dot' => 'bg-secondary'],
        ['role' => UserRole::Staff, 'label' => 'Staff', 'bar' => 'bg-[#a8877a]', 'dot' => 'bg-[#a8877a]'],
        ['role' => UserRole::Admin, 'label' => 'Admins', 'bar' => 'bg-text-primary', 'dot' => 'bg-text-primary'],
    ];
    $onlineCount = \App\Models\User::query()->online()->count();
    $filterUrl = fn (array $changes) => route('admin.accounts.index', array_filter([
        'role' => $role?->value, 'trusted' => $trustedOnly ? 1 : null, 'q' => $search ?: null, ...$changes,
    ], fn ($value) => $value !== null && $value !== ''));
@endphp

@section('content.admin')
    <div class="mx-auto flex max-w-6xl flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Accounts</h1>
                <p class="mt-1 text-sm text-text-muted">Everyone who signs in to TCC NAV. Students register with their COR; faculty and staff accounts are made here.</p>
            </div>
            <a href="{{ route('admin.accounts.create') }}"
                class="inline-flex min-h-11 items-center gap-2 rounded-lg bg-primary px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-hover focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                    aria-hidden="true">
                    <path d="M15 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM19 8v6m3-3h-6" />
                </svg>
                Create account
            </a>
        </div>

        {{-- Who is on TCC NAV: the bar and its legend filter the list. --}}
        <section aria-labelledby="people-heading" class="rounded-2xl border border-border bg-surface p-5">
            <div class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2">
                <h2 id="people-heading" class="flex items-baseline gap-2">
                    <span class="text-3xl font-semibold tracking-tight text-primary tabular-nums">{{ number_format($total) }}</span>
                    <span class="text-sm text-text-muted">{{ Str::plural('account', $total) }}</span>
                </h2>
                <p class="flex items-center gap-2 text-sm">
                    <span class="relative flex size-2.5" aria-hidden="true">
                        @if ($onlineCount > 0)
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500/60 motion-reduce:hidden"></span>
                        @endif
                        <span @class(['relative inline-flex size-2.5 rounded-full', 'bg-emerald-500' => $onlineCount > 0, 'bg-border' => $onlineCount === 0])></span>
                    </span>
                    <span><strong class="tabular-nums">{{ $onlineCount }}</strong> <span class="text-text-muted">online now</span></span>
                </p>
            </div>

            @if ($total > 0)
                <div class="mt-4 flex h-3 overflow-hidden rounded-full bg-bg-light" role="img"
                    aria-label="{{ collect($segments)->map(fn ($s) => ($countsByRole[$s['role']->value] ?? 0).' '.Str::lower($s['label']))->join(', ') }}">
                    @foreach ($segments as $segment)
                        @php($count = $countsByRole[$segment['role']->value] ?? 0)
                        @if ($count > 0)
                            <a data-keeps-search href="{{ $filterUrl(['role' => $segment['role']->value, 'trusted' => null]) }}" tabindex="-1" aria-hidden="true"
                                style="width: {{ round($count / $total * 100, 2) }}%"
                                @class([
                                    $segment['bar'], 'h-full transition-[filter,opacity] hover:brightness-110 not-last:border-r-2 not-last:border-surface',
                                    'opacity-30' => $role && $role !== $segment['role'],
                                ])></a>
                        @endif
                    @endforeach
                </div>
            @endif

            <nav aria-label="Filter accounts" class="mt-4 flex flex-wrap gap-2">
                <a data-keeps-search href="{{ $filterUrl(['role' => null, 'trusted' => null]) }}" @if (! $role && ! $trustedOnly) aria-current="page" @endif
                    class="inline-flex min-h-10 items-center gap-2 rounded-full border border-border px-3.5 text-sm font-semibold transition hover:border-primary aria-[current=page]:border-primary aria-[current=page]:bg-primary aria-[current=page]:text-white">
                    All <span class="tabular-nums opacity-70">{{ $total }}</span>
                </a>
                @foreach ($segments as $segment)
                    @php($current = $role === $segment['role'] && ! $trustedOnly)
                    <a data-keeps-search href="{{ $filterUrl(['role' => $segment['role']->value, 'trusted' => null]) }}" @if ($current) aria-current="page" @endif
                        class="inline-flex min-h-10 items-center gap-2 rounded-full border border-border px-3.5 text-sm font-semibold transition hover:border-primary aria-[current=page]:border-primary aria-[current=page]:bg-primary aria-[current=page]:text-white">
                        <span class="size-2.5 rounded-full {{ $segment['dot'] }} ring-2 ring-white/70" aria-hidden="true"></span>
                        {{ $segment['label'] }} <span class="tabular-nums opacity-70">{{ $countsByRole[$segment['role']->value] ?? 0 }}</span>
                    </a>
                @endforeach
                <a data-keeps-search href="{{ $filterUrl(['role' => null, 'trusted' => 1]) }}" @if ($trustedOnly) aria-current="page" @endif
                    class="inline-flex min-h-10 items-center gap-2 rounded-full border border-alarm/30 px-3.5 text-sm font-semibold text-alarm transition hover:border-alarm aria-[current=page]:border-alarm aria-[current=page]:bg-alarm aria-[current=page]:text-white">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                        aria-hidden="true">
                        <path d="M12 3 4 6v6c0 4.5 3.4 8.3 8 9 4.6-.7 8-4.5 8-9V6zm-3 9 2 2 4-4" />
                    </svg>
                    Can send alerts <span class="tabular-nums opacity-70">{{ $trustedCount }}</span>
                </a>
            </nav>
        </section>

        <section aria-labelledby="list-heading" class="overflow-hidden rounded-2xl border border-border bg-surface">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4">
                <h2 id="list-heading" class="font-semibold">
                    @if ($trustedOnly)
                        Can send emergency alerts
                    @elseif ($role)
                        {{ collect($segments)->firstWhere('role', $role)['label'] }}
                    @else
                        Everyone
                    @endif
                </h2>
                <form method="GET" action="{{ route('admin.accounts.index') }}" role="search" data-accounts-search
                    data-results-header="{{ \App\Http\Controllers\Admin\AccountController::RESULTS_HEADER }}" class="w-full sm:w-72">
                    @if ($role) <input type="hidden" name="role" value="{{ $role->value }}"> @endif
                    @if ($trustedOnly) <input type="hidden" name="trusted" value="1"> @endif
                    <label for="accounts-search" class="sr-only">Search accounts</label>
                    <div class="relative">
                        <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none"
                            stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zm9 2-4-4" />
                        </svg>
                        <input id="accounts-search" name="q" type="search" value="{{ $search }}" maxlength="100" autocomplete="off"
                            placeholder="Name, school ID or email" aria-controls="accounts-results"
                            class="w-full rounded-lg border border-border bg-bg-light py-2 pr-9 pl-9 text-sm placeholder:text-text-muted/80 focus:border-secondary focus:bg-white focus:ring-4 focus:ring-secondary/20 focus:outline-none">
                        {{-- Shown while the live search is waiting for the server. --}}
                        <span data-search-busy hidden class="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin rounded-full border-2 border-border border-t-primary motion-reduce:animate-none" aria-hidden="true"></span>
                    </div>
                </form>
            </div>

            <div id="accounts-results" data-accounts-results aria-live="polite" class="transition-opacity aria-busy:opacity-60">
                @include('admin.accounts-results')
            </div>
        </section>
    </div>
@endsection
