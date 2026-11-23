{{-- Emergency alerts: the active alert (edit, resolve as all clear or false alarm, "I'm safe" count) and the history
     of every alert with who sent, edited and resolved it. Times are campus time. --}}
@extends('layout.admin-layout')

@php
    $campusTime = fn ($time) => $time?->timezone('Asia/Manila')->format('M j, Y, g:i A');
@endphp

@section('title', 'Emergency alerts')
@section('heading', 'Emergency alerts')

@section('content.admin')
    <div class="mx-auto flex max-w-5xl flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Emergency alerts</h1>
                <p class="mt-1 text-sm text-text-muted">Alerts show on every page within 15 seconds and are pushed to subscribed devices.</p>
            </div>
            @unless ($activeAlert)
                <a href="{{ route('admin.alerts.create') }}"
                    class="rounded-lg bg-alarm px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-alarm-deep">Send emergency alert</a>
            @endunless
        </div>

        @error('form')
            <p role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</p>
        @enderror

        @if ($activeAlert)
            <section aria-labelledby="active-heading" class="overflow-hidden rounded-2xl border-2 border-alarm bg-surface">
                @if ($activeAlert->is_drill)
                    <div class="hazard-tape h-2" aria-hidden="true"></div>
                @endif
                <div class="flex flex-wrap items-center justify-between gap-3 bg-alarm px-5 py-3 text-white">
                    <h2 id="active-heading" class="flex flex-wrap items-center gap-2 font-semibold">
                        @if ($activeAlert->is_drill)
                            <span class="rounded bg-hazard px-1.5 text-xs font-black tracking-[.18em] text-stone-900">DRILL</span>
                        @endif
                        Active now: {{ $activeAlert->type->label() }}
                        @include('admin.severity-badge', ['level' => $activeAlert->severity])
                    </h2>
                    <a href="{{ route('admin.alerts.edit', $activeAlert) }}"
                        class="rounded-lg bg-white px-3 py-1.5 text-sm font-semibold text-alarm transition hover:bg-red-50">Edit wording</a>
                </div>

                <div class="grid gap-6 p-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
                    <div>
                        <p class="text-lg font-semibold">{{ $activeAlert->title }}</p>
                        <p class="mt-1 flex items-center gap-1.5 text-sm font-medium text-alarm">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                stroke-linejoin="round" aria-hidden="true">
                                <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
                            </svg>
                            {{ $activeAlert->locationLabel() }}
                            @if ($activeAlert->danger_radius_m)
                                <span class="font-normal text-text-muted">({{ $activeAlert->danger_radius_m }} m danger zone)</span>
                            @endif
                        </p>
                        <p class="mt-2 text-sm">{{ $activeAlert->message }}</p>
                        @if (filled($activeAlert->instructions))
                            <p class="mt-2 text-sm font-semibold">{{ $activeAlert->instructions }}</p>
                        @endif
                        <p class="mt-3 text-xs text-text-muted">
                            Sent {{ $campusTime($activeAlert->sent_at) }} by {{ $activeAlert->creator?->full_name ?? 'a deleted account' }}
                        </p>

                        {{-- Who is safe (admin/alert-safety-panel), kept live by alert-safety-live.js: the counts, by college (a link lists that college), and the
                             names. Answers are admin-only. --}}
                        <section id="safety" aria-labelledby="safety-heading" aria-live="polite" data-safety-live="{{ route('admin.alerts.safety', array_filter(['alert' => $activeAlert, 'college' => $safetyCollege])) }}" class="mt-4 scroll-mt-24 rounded-xl border border-safe/20 bg-safe-tint/50 p-4">
                            @include('admin.alert-safety-panel', ['alert' => $activeAlert])
                        </section>
                    </div>

                    @include('partials.alert-resolve-form', ['alert' => $activeAlert, 'action' => route('admin.alerts.resolve', $activeAlert)])
                </div>
            </section>
        @endif

        <section aria-labelledby="history-heading" class="overflow-hidden rounded-2xl border border-border bg-surface">
            <h2 id="history-heading" class="px-5 pt-4 pb-3 font-semibold">History</h2>

            @if ($history->isEmpty())
                <p class="border-t border-border px-5 py-4 text-sm text-text-muted">No alerts have been sent yet.</p>
            @else
                <ul class="divide-y divide-border border-t border-border">
                    @foreach ($history as $alert)
                        <li class="px-5 py-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold">{{ $alert->title }}</span>
                                <span class="rounded-full bg-bg-light px-2 py-0.5 text-xs text-text-muted">{{ $alert->type->label() }}</span>
                                @include('admin.severity-badge', ['level' => $alert->severity])
                                @if ($alert->is_drill)
                                    <span class="rounded bg-hazard/20 px-2 py-0.5 text-xs font-bold tracking-wider text-stone-800">DRILL</span>
                                @endif
                                @if ($alert->isActive())
                                    <span class="rounded-full bg-alarm px-2 py-0.5 text-xs font-semibold text-white">Active</span>
                                @else
                                    <span class="rounded-full bg-safe-tint px-2 py-0.5 text-xs font-semibold text-safe">{{ $alert->resolution?->label() ?? 'Resolved' }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-text-muted">{{ $alert->locationLabel() }}</p>
                            @if ($alert->resolved_message)
                                <p class="mt-1 text-xs text-text-muted">{{ $alert->resolution?->label() ?? 'All clear' }}: {{ $alert->resolved_message }}</p>
                            @endif
                            <ol class="mt-2 flex flex-col gap-0.5 text-xs text-text-muted">
                                @foreach ($alert->logs as $log)
                                    <li>{{ $log->action->label() }}@if ($log->detail) ({{ $log->detail }})@endif {{ $campusTime($log->created_at) }} by {{ $log->user?->full_name ?? 'a deleted account' }}</li>
                                @endforeach
                            </ol>
                        </li>
                    @endforeach
                </ul>
                @if ($history->hasPages())
                    <div class="border-t border-border px-5 py-3">{{ $history->links() }}</div>
                @endif
            @endif
        </section>
    </div>
@endsection
