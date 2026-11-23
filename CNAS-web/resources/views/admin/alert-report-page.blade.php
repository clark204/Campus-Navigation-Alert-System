{{-- One alert's report, opened from History on Admin > Alerts (AlertController::show): what was sent, how people answered,
     every help request with where the person was and who helped (a still map, resources/js/alerts/help-map.js), and
     the activity. Safety answers and help locations are deleted 30 days after the alert ends ($pruned). --}}
@extends('layout.admin-layout')
@use('App\Enums\AlertResolution')

@php
    $campusTime = fn ($time) => $time?->timezone('Asia/Manila')->format('M j, Y, g:i A');
    $lasted = $alert->lastedLabel();
@endphp

@php
    $breadcrumbs = [['label' => $alert->title]];
@endphp

@section('title', $alert->title.' · Alert report')
@section('heading', 'Emergency alerts')

@section('content.admin')
    <div class="flex min-w-0 flex-col gap-7">
        <div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-text-muted">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $alert->type->icon() }}" /></svg>
                    {{ $alert->type->label() }}
                </span>
                @include('admin.severity-badge', ['level' => $alert->severity])
                @if ($alert->is_drill)
                    <span class="rounded-md bg-hazard/25 px-2 py-0.5 text-xs font-bold tracking-wide text-stone-800">Drill</span>
                @endif
                @if ($alert->isActive())
                    <a href="{{ route('admin.alerts.index') }}#active-alert" class="inline-flex items-center gap-1.5 rounded-full bg-alarm px-2.5 py-0.5 text-xs font-semibold text-white hover:bg-alarm-deep">
                        <span class="size-1.5 rounded-full bg-white" aria-hidden="true"></span>Active now. Manage it
                    </a>
                @else
                    <span @class([
                        'inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        'bg-safe-tint text-safe' => $alert->resolution === AlertResolution::AllClear,
                        'bg-bg-light text-text-primary' => $alert->resolution !== AlertResolution::AllClear,
                    ])>{{ $alert->resolution?->label() ?? 'Ended' }}</span>
                @endif
            </div>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-primary wrap-anywhere sm:text-3xl">{{ $alert->title }}</h1>
            <p class="mt-1 text-sm text-text-muted wrap-anywhere">{{ $alert->locationLabel() }}@if ($alert->danger_radius_m), {{ $alert->danger_radius_m }} m danger zone @endif</p>
        </div>

        <dl class="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-border bg-border text-sm lg:grid-cols-4">
            <div class="bg-surface p-4">
                <dt class="text-xs text-text-muted">Sent</dt>
                <dd class="mt-1 font-semibold">{{ $campusTime($alert->sent_at) }}</dd>
                <dd class="text-xs text-text-muted wrap-anywhere">by {{ $alert->creator?->full_name ?? 'a deleted account' }}</dd>
            </div>
            <div class="bg-surface p-4">
                <dt class="text-xs text-text-muted">Ended</dt>
                @if ($alert->resolved_at)
                    <dd class="mt-1 font-semibold">{{ $campusTime($alert->resolved_at) }}</dd>
                    <dd class="text-xs text-text-muted wrap-anywhere">by {{ $alert->resolver?->full_name ?? 'a deleted account' }}</dd>
                @else
                    <dd class="mt-1 font-semibold text-alarm">Still active</dd>
                @endif
            </div>
            <div class="bg-surface p-4">
                <dt class="text-xs text-text-muted">Lasted</dt>
                <dd class="mt-1 font-semibold tabular-nums">{{ $lasted ?? 'Ongoing' }}</dd>
            </div>
            <div class="bg-surface p-4">
                <dt class="text-xs text-text-muted">Answers</dt>
                @if ($safety)
                    <dd class="mt-1 font-semibold tabular-nums"><span class="text-safe">{{ $safety['safe'] }} safe</span>@if ($safety['need_help'] > 0), <span class="text-alarm">{{ $safety['need_help'] }} needed help</span>@endif</dd>
                    <dd class="text-xs text-text-muted tabular-nums">{{ $safety['not_answered'] }} did not answer</dd>
                @elseif ($pruned)
                    <dd class="mt-1 text-xs leading-relaxed text-text-muted">Deleted. Answers are kept for {{ \App\Models\SafetyCheck::RETENTION_DAYS }} days after an alert ends.</dd>
                @else
                    <dd class="mt-1 text-xs leading-relaxed text-text-muted">Not asked. Only emergencies ask “I’m safe”.</dd>
                @endif
            </div>
        </dl>

        <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(18rem,1fr)]">
            <section aria-labelledby="wording-heading" class="min-w-0 rounded-2xl border border-border bg-surface p-5 sm:p-6">
                <h2 id="wording-heading" class="text-lg font-semibold text-primary">What people saw</h2>
                <div class="mt-4 space-y-4 text-sm leading-relaxed">
                    @if (filled($alert->whatToDo()))
                        <div><h3 class="mb-1 font-semibold">What to do now</h3><p class="font-semibold whitespace-pre-line wrap-anywhere">{{ $alert->whatToDo() }}</p></div>
                    @endif
                    <div><h3 class="mb-1 font-semibold">What is happening</h3><p class="whitespace-pre-line wrap-anywhere">{{ $alert->message }}</p></div>
                    @if (filled($alert->instructions))
                        <div><h3 class="mb-1 font-semibold">More instructions</h3><p class="whitespace-pre-line wrap-anywhere">{{ $alert->instructions }}</p></div>
                    @endif
                    @if ($alert->resolved_message)
                        <div class="rounded-xl bg-bg-light p-3"><h3 class="mb-1 font-semibold">{{ $alert->resolution?->label() ?? 'All clear' }} message</h3><p class="whitespace-pre-line wrap-anywhere">{{ $alert->resolved_message }}</p></div>
                    @endif
                </div>
            </section>

            <section aria-labelledby="activity-heading" class="min-w-0 rounded-2xl border border-border bg-surface p-5 sm:p-6">
                <h2 id="activity-heading" class="text-lg font-semibold text-primary">Activity</h2>
                <ol class="mt-4 space-y-3 border-l-2 border-border pl-4 text-sm">
                    @foreach ($alert->logs as $log)
                        <li class="relative">
                            <span class="absolute top-1.5 -left-[1.4rem] size-2.5 rounded-full border-2 border-surface bg-primary/60" aria-hidden="true"></span>
                            <p class="font-medium wrap-anywhere">{{ $log->action->describe($log->detail) }}</p>
                            <p class="text-xs text-text-muted wrap-anywhere">{{ $campusTime($log->created_at) }}, {{ $log->user?->full_name ?? 'a deleted account' }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        @if ($safety)
            @include('admin.alert-help-workspace', ['alert' => $alert, 'helpRequests' => $helpRequests, 'live' => false])
        @endif
    </div>
@endsection
