{{-- Admin alerts workspace; shared resolution and live safety behavior remain intact. --}}
@extends('layout.admin-layout')

@php
    $campusTime = fn ($time) => $time?->timezone('Asia/Manila')->format('M j, Y, g:i A');
@endphp

@section('title', 'Emergency alerts')
@section('heading', 'Emergency alerts')

@section('content.admin')
    <div class="flex min-w-0 flex-col gap-7">
        <div class="flex flex-col justify-between gap-4 xl:flex-row xl:items-start">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Emergency alerts</h1>
                <p class="mt-2 max-w-2xl text-sm leading-relaxed text-text-muted">Alerts show on every page within 15 seconds and are pushed to subscribed devices.</p>
            </div>
            <div class="flex shrink-0 flex-col gap-2 xl:items-end">
                @if ($activeAlert)
                    <button type="button" disabled aria-describedby="send-unavailable" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-border bg-bg-light px-5 py-3 text-sm font-semibold text-text-muted disabled:cursor-not-allowed">Send emergency alert</button>
                    <p id="send-unavailable" class="text-xs leading-relaxed text-text-muted">End the active alert before sending another.</p>
                @else
                    <a href="{{ route('admin.alerts.create') }}" class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-primary px-5 py-3 text-sm font-semibold text-white transition hover:bg-primary-hover">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3 2 20h20zM12 10v4m0 3h.01" /></svg>
                        Send emergency alert
                    </a>
                @endif
            </div>
        </div>

        @error('form')
            <p role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</p>
        @enderror

        @if ($activeAlert)
            <section id="active-alert" aria-labelledby="active-heading" class="scroll-mt-24 overflow-hidden rounded-2xl border border-border border-t-4 bg-surface shadow-sm" style="border-top-color: {{ $activeAlert->severity->color() }}">
                @if ($activeAlert->is_drill)
                    <div class="hazard-tape h-1.5" aria-hidden="true"></div>
                @endif
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-4 sm:px-6">
                    <div class="flex flex-wrap items-center gap-3">
                        <h2 id="active-heading" class="font-semibold">Active now: {{ $activeAlert->type->label() }}</h2>
                        @include('admin.severity-badge', ['level' => $activeAlert->severity])
                        @if ($activeAlert->is_drill)
                            <span class="rounded-md bg-hazard/20 px-2 py-1 text-xs font-bold tracking-wider text-stone-800">DRILL</span>
                        @endif
                    </div>
                    <a href="{{ route('admin.alerts.edit', $activeAlert) }}" class="inline-flex min-h-11 items-center justify-center rounded-lg border border-border px-4 text-sm font-semibold text-primary transition hover:border-primary/30 hover:bg-bg-light">Edit wording</a>
                </div>
                <div class="grid min-w-0 gap-6 p-5 sm:p-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(18rem,1fr)]">
                    <div class="min-w-0">
                        <h3 class="text-xl font-semibold tracking-tight text-primary wrap-anywhere sm:text-2xl">{{ $activeAlert->title }}</h3>
                        <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm leading-relaxed">
                            <span class="font-medium wrap-anywhere">{{ $activeAlert->locationLabel() }}</span>
                            @if ($activeAlert->danger_radius_m)
                                <span class="text-text-muted">({{ $activeAlert->danger_radius_m }} m danger zone)</span>
                            @endif
                        </p>
                        <div class="mt-5 space-y-4 text-sm leading-relaxed">
                            <div>
                                <h4 class="mb-1 font-semibold">Message</h4>
                                <p class="whitespace-pre-line wrap-anywhere">{{ $activeAlert->message }}</p>
                            </div>
                            @if (filled($activeAlert->what_to_do))
                                <div><h4 class="mb-1 font-semibold">What to do now</h4><p class="whitespace-pre-line wrap-anywhere">{{ $activeAlert->what_to_do }}</p></div>
                            @endif
                            @if (filled($activeAlert->instructions))
                                <div><h4 class="mb-1 font-semibold">Instructions</h4><p class="whitespace-pre-line wrap-anywhere">{{ $activeAlert->instructions }}</p></div>
                            @endif
                        </div>
                        <p class="mt-5 border-t border-border pt-4 text-xs leading-relaxed text-text-muted wrap-anywhere">Sent {{ $campusTime($activeAlert->sent_at) }} by {{ $activeAlert->creator?->full_name ?? 'a deleted account' }}</p>
                    </div>

                    {{-- Preserve reading order; desktop spans the safety panel across both columns. --}}
                    <section id="safety" aria-labelledby="safety-heading" aria-live="polite" data-safety-live="{{ route('admin.alerts.safety', array_filter(['alert' => $activeAlert, 'college' => $safetyCollege])) }}" class="min-w-0 scroll-mt-24 rounded-xl border border-safe/20 bg-safe-tint/50 p-4 xl:col-span-2 xl:row-start-2 [&_.max-h-64>li]:flex-wrap [&_.max-h-64>li>span]:min-w-0 [&_.max-h-64>li>span]:max-w-full [&_.max-h-64>li>span]:wrap-anywhere">
                        @include('admin.alert-safety-panel', ['alert' => $activeAlert])
                    </section>

                    <div class="min-w-0 border-t border-border pt-5 xl:col-start-2 xl:row-start-1 xl:border-t-0 xl:border-l xl:pt-0 xl:pl-6">
                        <h3 class="mb-4 text-lg font-semibold text-primary">End alert</h3>
                        @include('partials.alert-resolve-form', ['alert' => $activeAlert, 'action' => route('admin.alerts.resolve', $activeAlert)])
                    </div>
                </div>
            </section>
        @else
            <section aria-labelledby="active-heading" class="flex items-start gap-4 rounded-xl border border-border bg-surface p-5 sm:p-6">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-bg-light text-primary" aria-hidden="true">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6" /></svg>
                </span>
                <div>
                    <h2 id="active-heading" class="font-semibold text-primary">No active alert</h2>
                    <p class="mt-1 text-sm leading-relaxed text-text-muted">Previously sent alerts are listed below. Use Send emergency alert to create a new alert.</p>
                </div>
            </section>
        @endif

        {{-- Only an emergency asks "I'm safe" / "I need help". --}}
        @if ($activeAlert?->isEmergency())
            @include('admin.alert-help-workspace', ['alert' => $activeAlert, 'helpRequests' => $helpRequests, 'live' => true])
        @endif

        @include('admin.alert-history-table')
    </div>
@endsection
