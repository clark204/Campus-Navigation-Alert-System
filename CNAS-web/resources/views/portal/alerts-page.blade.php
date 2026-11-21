{{-- Faculty portal "Emergency alerts" (Portal\AlertController@index), only for faculty and staff the admin trusts:
     the active alert with "End alert" (partials/alert-resolve-form), or the button to send one; and the latest alerts
     with who sent them. Editing an alert's wording stays with the admin. Times are campus time. --}}
@extends('layout.portal-layout')

@section('title', 'Emergency alerts')
@section('heading', 'Emergency alerts')

@php
    $campusTime = fn ($time) => $time?->timezone('Asia/Manila')->format('M j, Y, g:i A');
@endphp

@section('content.portal')
    <div class="mx-auto flex max-w-4xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Emergency alerts</h1>
            <p class="mt-1 text-sm text-text-muted">The administrator trusts you to warn everyone on campus. Use it for real emergencies and planned drills.</p>
        </div>

        @error('form')
            <p role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</p>
        @enderror

        @if ($activeAlert)
            <section aria-labelledby="active-heading" class="overflow-hidden rounded-2xl border-2 border-alarm bg-surface">
                @if ($activeAlert->is_drill)
                    <div class="hazard-tape h-2" aria-hidden="true"></div>
                @endif
                <h2 id="active-heading" class="flex flex-wrap items-center gap-2 bg-alarm px-5 py-3 font-semibold text-white">
                    @if ($activeAlert->is_drill)
                        <span class="rounded bg-hazard px-1.5 text-xs font-black tracking-[.18em] text-stone-900">DRILL</span>
                    @endif
                    Active now: {{ $activeAlert->type->label() }}
                    @include('admin.severity-badge', ['level' => $activeAlert->severity])
                </h2>
                <div class="grid gap-6 p-5 md:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
                    <div>
                        <p class="text-lg font-semibold">{{ $activeAlert->title }}</p>
                        <p class="mt-1 text-sm font-medium text-alarm">{{ $activeAlert->locationLabel() }}</p>
                        <p class="mt-2 text-sm">{{ $activeAlert->message }}</p>
                        @if (filled($activeAlert->instructions))
                            <p class="mt-2 text-sm font-semibold">{{ $activeAlert->instructions }}</p>
                        @endif
                        <p class="mt-3 text-xs text-text-muted">Sent {{ $campusTime($activeAlert->sent_at) }} by {{ $activeAlert->creator?->full_name ?? 'a deleted account' }}</p>
                    </div>
                    @include('partials.alert-resolve-form', ['alert' => $activeAlert, 'action' => route('faculty.alerts.resolve', $activeAlert)])
                </div>
            </section>
        @else
            <section aria-labelledby="ready-heading" class="relative overflow-hidden rounded-2xl border border-border bg-surface p-6 sm:p-8">
                <div class="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-alarm/[.06]" aria-hidden="true"></div>
                <div class="pointer-events-none absolute -top-6 -right-6 size-32 rounded-full bg-alarm/[.07]" aria-hidden="true"></div>
                <div class="relative flex flex-wrap items-center gap-6">
                    <span class="grid size-16 shrink-0 place-items-center rounded-2xl bg-alarm text-white shadow-[0_12px_28px_-12px_rgba(179,18,27,.8)]" aria-hidden="true">
                        <svg class="size-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3 2 20h20zM12 10v4m0 3h.01" />
                        </svg>
                    </span>
                    <div class="min-w-0 flex-1 basis-64">
                        <h2 id="ready-heading" class="text-lg font-semibold">No alert is active</h2>
                        <p class="mt-1 text-sm text-text-muted">If something happens, send an alert here. Everyone sees it within 15 seconds and subscribed phones get a notification. The administrator sees that you sent it.</p>
                    </div>
                    <a href="{{ route('faculty.alerts.create') }}"
                        class="inline-flex min-h-12 items-center gap-2 rounded-xl bg-alarm px-5 text-sm font-bold text-white shadow-md transition hover:bg-alarm-deep focus-visible:ring-4 focus-visible:ring-alarm/30 focus-visible:outline-none max-sm:w-full max-sm:justify-center">
                        Send emergency alert
                    </a>
                </div>
            </section>
        @endif

        <section aria-labelledby="recent-heading" class="overflow-hidden rounded-2xl border border-border bg-surface">
            <h2 id="recent-heading" class="px-5 pt-4 pb-3 font-semibold">Latest alerts</h2>
            @if ($recentAlerts->isEmpty())
                <p class="border-t border-border px-5 py-4 text-sm text-text-muted">No alerts have been sent yet.</p>
            @else
                <ul class="divide-y divide-border border-t border-border">
                    @foreach ($recentAlerts as $alert)
                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 px-5 py-3.5">
                            <div class="min-w-0">
                                <p class="flex flex-wrap items-center gap-2 text-sm font-semibold">
                                    {{ $alert->title }}
                                    @include('admin.severity-badge', ['level' => $alert->severity])
                                    @if ($alert->is_drill)
                                        <span class="rounded bg-hazard/20 px-2 py-0.5 text-xs font-bold tracking-wider text-stone-800">DRILL</span>
                                    @endif
                                </p>
                                <p class="text-xs text-text-muted">{{ $campusTime($alert->sent_at) }} · by {{ $alert->creator?->full_name ?? 'a deleted account' }}</p>
                            </div>
                            @if ($alert->isActive())
                                <span class="rounded-full bg-alarm px-2.5 py-0.5 text-xs font-semibold text-white">Active</span>
                            @else
                                <span class="rounded-full bg-safe-tint px-2.5 py-0.5 text-xs font-semibold text-safe">{{ $alert->resolution?->label() ?? 'Resolved' }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
@endsection
