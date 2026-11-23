{{-- The help requests beside the campus map (resources/js/alerts/help-map.js). $live: on Admin > Alerts during an
     emergency the queue (admin/alert-help-panel) refreshes every 5 s, with a sound toggle and "I'm responding" /
     "Mark helped"; on an alert's report page it is a still map of where each person last was. Needs $alert and
     $helpRequests. --}}
@php($live ??= false)
@php($waiting = $helpRequests->filter(fn ($check) => $check->helpStatus()->isOpen())->count())
<section id="help" aria-labelledby="help-heading" data-help-workspace
    data-map-url="{{ route('api.map') }}"
    @if ($alert->hasPin()) data-incident-lng="{{ $alert->longitude }}" data-incident-lat="{{ $alert->latitude }}" data-radius="{{ $alert->danger_radius_m }}" @endif
    data-affected="{{ json_encode(array_values($alert->building_codes ?? [])) }}"
    class="min-w-0 scroll-mt-24">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h2 id="help-heading" class="text-xl font-semibold tracking-tight text-primary">Help requests</h2>
            @if ($live)
                {{-- Kept up to date by help-map.js after each refresh. --}}
                <span data-help-waiting @if ($waiting === 0) hidden @endif class="rounded-full bg-alarm px-2.5 py-0.5 text-sm font-semibold text-white tabular-nums">{{ $waiting }} waiting</span>
            @endif
        </div>
        @if ($live)
            <button type="button" data-help-sound aria-pressed="false"
                class="group/sound inline-flex min-h-10 items-center gap-2 rounded-lg border border-border bg-surface px-3 text-sm font-semibold text-text-primary transition hover:bg-bg-light aria-pressed:border-primary/30 aria-pressed:text-primary">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M11 5 6 9H3v6h3l5 4z" />
                    <path class="hidden group-aria-pressed/sound:block" d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" />
                    <path class="group-aria-pressed/sound:hidden" d="m16 9 6 6m0-6-6 6" />
                </svg>
                <span data-sound-label>Sound off</span>
            </button>
        @endif
    </div>
    @if ($live)
        <p class="mb-3 text-sm leading-relaxed text-text-muted">The person’s page or app shares their location every 15 seconds until you mark them helped. When you tap “I’m responding”, they see that you are on the way.</p>
        <p data-help-status role="status" class="sr-only"></p>
    @endif

    <div class="grid min-w-0 gap-4 lg:grid-cols-[minmax(0,1.35fr)_minmax(20rem,1fr)]">
        <div data-help-map-frame class="group/map relative h-72 overflow-hidden rounded-2xl border border-border bg-[#d5e8c3] sm:h-96 lg:sticky lg:top-24 lg:h-[32rem]">
            {{-- Sized with h-full: MapLibre makes its container position: relative. --}}
            <div data-help-map class="h-full w-full"></div>
            <p class="absolute inset-0 hidden place-items-center p-6 text-center text-sm text-text-muted group-data-failed/map:grid">The map could not load. The list still shows each request.</p>
            <ul class="pointer-events-none absolute bottom-2 left-2 flex flex-wrap gap-1.5 text-[11px] font-semibold" aria-hidden="true">
                <li class="flex items-center gap-1 rounded-full bg-white/90 px-2 py-0.5 shadow-sm"><span class="size-2 rounded-full bg-alarm"></span>Waiting</li>
                <li class="flex items-center gap-1 rounded-full bg-white/90 px-2 py-0.5 shadow-sm"><span class="size-2 rounded-full bg-amber-500"></span>On the way</li>
                @unless ($live)
                    <li class="flex items-center gap-1 rounded-full bg-white/90 px-2 py-0.5 shadow-sm"><span class="size-2 rounded-full bg-safe"></span>Helped</li>
                @endunless
            </ul>
        </div>

        @if ($live)
            <div data-help-live="{{ route('admin.alerts.help', $alert) }}" aria-live="polite" class="min-w-0 lg:max-h-[32rem] lg:overflow-y-auto lg:pr-1">
                @include('admin.alert-help-panel')
            </div>
        @else
            <div class="min-w-0">
                @include('admin.alert-help-report-list')
            </div>
        @endif
    </div>
</section>
