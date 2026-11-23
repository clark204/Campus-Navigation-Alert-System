@extends('layout.map-layout')
@use('App\Enums\AlertType')

@section('title', 'TCC NAV · Directions')

@push('scripts.map')
    @vite('resources/js/navigation/main.js')
@endpush

@section('content.map')
    <div data-nav data-map-url="{{ route('api.map') }}" class="group fixed inset-0 overflow-hidden bg-[#d5e8c3]">
        {{-- Sized with h/w-full: MapLibre's own CSS makes the container position: relative. --}}
        <div data-nav-map class="h-full w-full" role="region" aria-label="Campus map with your route"></div>

        <p class="absolute inset-0 hidden items-center justify-center p-8 text-center text-sm text-text-muted group-data-failed:flex">
            The campus map couldn't load. Check your connection and reload the page.
        </p>

        {{-- Top bar --}}
        <div class="pointer-events-none absolute inset-x-0 top-0 flex items-start gap-3 p-3 pt-[max(.75rem,env(safe-area-inset-top))] lg:pr-[404px]">
            <a href="{{ route('landing') }}" aria-label="Back to home"
                class="pointer-events-auto grid size-11 shrink-0 place-items-center rounded-full bg-white text-primary shadow-md transition hover:bg-bg-light">
                <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
            </a>

            <p data-insecure hidden role="status"
                class="pointer-events-auto flex-1 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900 shadow-md">
                <strong>This page isn't on HTTPS</strong>, so GPS, the compass and step counting are off. You can still
                preview routes and move through the directions with “Next”.
            </p>

            <div class="pointer-events-auto ml-auto flex flex-col gap-2">
                <button type="button" data-rotate aria-pressed="false" aria-label="Turn the map with your heading"
                    class="grid size-11 place-items-center rounded-full bg-white text-primary shadow-md transition aria-pressed:bg-primary aria-pressed:text-white">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 5l3 7h-6z" fill="currentColor"/><path d="M12 19l-3-7h6z"/></svg>
                </button>
                {{-- Street view mode: walkways with 360° photos light up; tap one to look around (street-view.js). --}}
                <button type="button" data-street-view hidden aria-pressed="false"
                    aria-label="Street view" title="Street view"
                    class="grid size-11 place-items-center rounded-full bg-white text-[#1a73e8] shadow-md transition hover:bg-bg-light aria-pressed:bg-[#1a73e8] aria-pressed:text-white">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="4.5" r="2"/><path d="M11 9.5 12 7.5l2 1.5M11 9.5 8 12.5M11 9.5l4 2M11 9.5 10.5 14M10.5 14 8.5 20.5M10.5 14l3.5 6.5"/></svg>
                </button>
                <button type="button" data-recenter hidden aria-label="Follow my position again"
                    class="grid size-11 place-items-center rounded-full bg-white text-[#1a73e8] shadow-md">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                </button>
            </div>
        </div>

        {{-- Shown after 4 backward steps in a row, until the person walks forward again. --}}
        {{-- Which upper floor's corridors are drawn (resources/js/navigation/main.js showFloor). --}}
        <p data-floor-label hidden role="status"
            class="pointer-events-none absolute top-5 left-1/2 -translate-x-1/2 lg:left-[calc((100%-404px)/2)] rounded-full bg-white/95 px-3 py-1.5 text-sm font-semibold text-primary shadow-md"></p>
        <p data-wrong-way hidden role="alert"
            class="absolute top-20 left-1/2 -translate-x-1/2 lg:left-[calc((100%-404px)/2)] flex items-center gap-2 rounded-full bg-red-600 px-4 py-2 text-sm font-semibold whitespace-nowrap text-white shadow-lg">
            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 20V9a4 4 0 0 0-8 0v6M4 11l4 4 4-4"/></svg>
            Wrong way, turn around
        </p>
        {{-- Filled in by the script when the person leaves the route. --}}
        <p data-rerouting hidden role="status"
            class="absolute top-32 left-1/2 -translate-x-1/2 lg:left-[calc((100%-404px)/2)] rounded-full bg-primary px-4 py-2 text-sm font-semibold whitespace-nowrap text-white shadow-lg"></p>

        {{-- Street view mode: what to do, and a short note when a tap finds no photo. --}}
        <p data-street-view-hint hidden role="status"
            class="pointer-events-none absolute top-[4.25rem] left-1/2 -translate-x-1/2 lg:left-[calc((100%-404px)/2)] rounded-full bg-[#1a73e8] px-3 py-1.5 text-xs sm:px-4 sm:py-2 sm:text-sm font-semibold whitespace-nowrap text-white shadow-lg">
            Tap a highlighted path to look around.
        </p>
        <p data-street-view-toast hidden role="status"
            class="pointer-events-none absolute top-[7.25rem] left-1/2 -translate-x-1/2 lg:left-[calc((100%-404px)/2)] rounded-full bg-neutral-900/85 px-3 py-1.5 text-xs sm:px-4 sm:py-2 sm:text-sm font-semibold whitespace-nowrap text-white shadow-lg">
            No photo here yet
        </p>

        {{-- Bottom sheet on phones and tablets; a card at the top right of the map from 1024 px (its top is set below an
             alert banner by the script). --}}
        <section data-sheet aria-label="Directions"
            class="absolute inset-x-0 bottom-0 mx-auto max-w-lg rounded-t-3xl bg-white pb-[max(1rem,env(safe-area-inset-bottom))] shadow-[0_-8px_30px_rgba(0,0,0,.15)] lg:inset-x-auto lg:top-3 lg:right-3 lg:bottom-auto lg:mx-0 lg:max-h-[calc(100dvh-1.5rem)] lg:w-[380px] lg:max-w-none lg:overflow-y-auto lg:rounded-3xl lg:pb-4 lg:shadow-xl">
            <div class="mx-auto mt-2.5 h-1 w-10 rounded-full bg-border lg:hidden" aria-hidden="true"></div>

            {{-- Emergency (while an alert is active): route to the evacuation area around the danger zone. Filled by
                 resources/js/navigation/main.js from the current alert. --}}
            <div data-panel="emergency" hidden class="space-y-3 px-4 pt-3">
                <div class="flex items-start gap-3">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-alarm text-white" aria-hidden="true">
                        @foreach (AlertType::cases() as $option)
                            <svg data-emergency-icon="{{ $option->value }}" hidden class="size-6" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                                <path d="{{ $option->icon() }}" />
                            </svg>
                        @endforeach
                    </span>
                    <div class="min-w-0">
                        <p class="flex flex-wrap items-center gap-x-2 text-sm">
                            <span data-emergency-drill hidden class="rounded bg-hazard px-1.5 text-xs font-black tracking-[.18em] text-stone-900">DRILL</span>
                            <span data-emergency-type class="font-semibold text-alarm"></span>
                            <span data-emergency-location class="text-text-muted"></span>
                        </p>
                        <h1 class="text-lg leading-snug font-semibold text-text-primary">Get to the evacuation area</h1>
                    </div>
                </div>

                <div class="grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                    <label class="block">
                        <span class="sr-only">Where are you now?</span>
                        <select data-evac-start class="login-input appearance-none truncate">
                            <option value="">Where are you now?</option>
                        </select>
                    </label>
                    <button type="button" data-evac-locate
                        class="flex items-center gap-2 rounded-xl border border-border px-3 text-sm font-semibold text-primary transition hover:bg-bg-light">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                        <span>My location</span>
                    </button>
                </div>

                <p data-evac-note aria-live="polite" class="text-sm text-text-muted empty:hidden data-error:font-semibold data-error:text-alarm"></p>

                <button type="button" data-evac-go
                    class="flex w-full items-center justify-center gap-2 rounded-xl bg-alarm py-3.5 text-base font-bold text-white shadow-md transition hover:bg-alarm-deep">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true">
                        <path d="M5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM19 9V3m0 0h-5m5 0-6 6M7 17h6a3 3 0 0 0 3-3v-1" />
                    </svg>
                    Show route to safety
                </button>

                <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 pb-1 text-xs text-text-muted">
                    <p class="flex items-center gap-3">
                        <span class="flex items-center gap-1.5"><span class="size-3 rounded-full border-2 border-alarm bg-alarm/25" aria-hidden="true"></span>Danger zone</span>
                        <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-safe" aria-hidden="true"></span>Evacuation area</span>
                    </p>
                    <button type="button" data-evac-plan class="py-1 font-semibold text-text-muted underline-offset-4 hover:text-primary hover:underline">
                        Directions somewhere else
                    </button>
                </div>
            </div>

            {{-- Planning --}}
            <div data-panel="plan" class="space-y-3 px-4 pt-3">
                <div class="flex items-center justify-between gap-3">
                    <h1 class="text-lg font-semibold text-primary">Where to?</h1>
                    {{-- Signed in: save the destination room (shown when the destination is a room). navigation/saved-places.js --}}
                    @auth
                        <button type="button" data-save-place hidden
                            data-saved='@json(auth()->user()->savedRooms()->pluck('code'))'
                            data-save-url="{{ route('places.save', ['room' => '__room__']) }}"
                            data-recent-url="{{ route('places.recent', ['room' => '__room__']) }}"
                            class="group/save ml-auto inline-flex min-h-10 items-center gap-1.5 rounded-full border border-border px-3.5 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10 focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none data-saved:border-secondary data-saved:bg-secondary/20">
                            <svg class="size-4 fill-transparent transition group-data-saved/save:fill-current" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M6 4h12v17l-6-4-6 4z" />
                            </svg>
                            <span data-save-label>Save</span>
                        </button>
                    @endauth
                    <button type="button" data-evac-back hidden
                        class="rounded-lg bg-alarm px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-alarm-deep">
                        Back to route to safety
                    </button>
                </div>

                <div class="relative grid gap-2 pr-12">
                    <label class="relative block">
                        <span class="sr-only">Starting point</span>
                        <span class="absolute top-1/2 left-4 size-3 -translate-y-1/2 rounded-full border-[3px] border-primary bg-white" aria-hidden="true"></span>
                        <select data-start class="login-input appearance-none truncate pl-10">
                            <option value="">Choose your starting point</option>
                        </select>
                    </label>
                    <label class="relative block">
                        <span class="sr-only">Destination</span>
                        <svg viewBox="0 0 24 24" class="absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-primary" fill="currentColor" aria-hidden="true"><path d="M12 22s-7-6.4-7-12a7 7 0 0 1 14 0c0 5.6-7 12-7 12z"/></svg>
                        <select data-destination class="login-input appearance-none truncate pl-10">
                            <option value="">Choose a destination</option>
                        </select>
                    </label>
                    <button type="button" data-swap aria-label="Swap start and destination"
                        class="absolute top-1/2 right-0 grid size-10 -translate-y-1/2 place-items-center rounded-full border border-border text-primary transition hover:bg-bg-light">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16M3 8l4-4 4 4M17 20V4M21 16l-4 4-4-4"/></svg>
                    </button>
                </div>

                <p data-summary class="text-sm text-text-muted" aria-live="polite">Pick where you are and where you want to go.</p>

                {{-- 360° photos on the route, in route order (filled in by the script; hidden when there are none). --}}
                <div data-route-photos hidden>
                    <p class="text-xs font-semibold text-text-muted">360° photos along the way</p>
                    <div data-route-photos-list class="-mx-1 mt-1.5 flex gap-2 overflow-x-auto px-1 pb-1"></div>
                </div>

                <details data-steps-preview hidden class="rounded-xl bg-bg-light px-4 py-2 text-sm">
                    <summary class="cursor-pointer py-1 font-semibold text-primary">Show steps</summary>
                    <ol data-steps-list class="max-h-40 list-decimal space-y-1 overflow-y-auto py-2 pl-5"></ol>
                </details>

                {{-- Guests and admins: "Voice directions" here. Students and faculty have it in Settings → Navigation. --}}
                @if (! auth()->user() || auth()->user()->isAdmin())
                    @include('partials.voice-directions', ['variant' => 'compact'])
                @endif

                <button type="button" data-start-nav disabled
                    class="w-full rounded-xl bg-primary py-3.5 text-sm font-bold text-white shadow-md transition hover:bg-primary-hover disabled:cursor-not-allowed disabled:opacity-40">
                    Start
                </button>
            </div>

            {{-- Navigating --}}
            <div data-panel="nav" hidden class="px-4 pt-3">
                <div class="rounded-2xl bg-primary p-4 text-white">
                    <div class="flex items-center gap-4" aria-live="polite">
                        <span data-icon class="grid size-14 shrink-0 place-items-center rounded-xl bg-white/15" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p data-next-distance class="text-2xl leading-tight font-semibold"></p>
                            <p data-instruction class="text-sm leading-snug text-white/90"></p>
                        </div>
                    </div>
                    {{-- A 360° photo of the spot just ahead on the route (shown from 30 m before it). --}}
                    <button type="button" data-see-spot hidden
                        class="mt-3 flex w-full items-center gap-3 rounded-xl bg-white/15 p-1.5 pr-3 text-left transition hover:bg-white/25 focus-visible:outline-2 focus-visible:outline-white">
                        <img data-see-spot-thumb alt="" class="h-10 w-20 shrink-0 rounded-lg bg-white/10 object-cover">
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold">See this spot</span>
                            <span data-see-spot-name class="block truncate text-xs text-white/75"></span>
                        </span>
                        <svg viewBox="0 0 24 24" class="size-5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8h3l2-3h6l2 3h3v11H4z"/><circle cx="12" cy="13" r="3.5"/></svg>
                    </button>
                </div>
                <p data-then class="mt-2 min-h-4 truncate text-xs text-text-muted"></p>

                <div data-stairs hidden class="mt-3">
                    <button type="button" data-confirm-floor
                        class="w-full rounded-xl bg-secondary py-4 text-base font-bold text-primary-hover shadow-md transition hover:bg-secondary-hover hover:text-white"></button>
                    <p class="mt-1.5 text-center text-xs text-text-muted">Tap when you've reached the floor. Steps on the stairs aren't counted.</p>
                </div>

                <div class="mt-3 flex items-baseline justify-between gap-3 text-sm">
                    <p class="shrink-0 whitespace-nowrap"><strong data-remaining class="text-text-primary"></strong> <span class="text-text-muted">· <span data-eta></span></span></p>
                    {{-- Where the current part of the route leads ("to the stairs near … · then …"); may wrap. --}}
                    <p data-dest class="min-w-0 text-right leading-snug break-words text-text-muted"></p>
                </div>

                <div class="mt-3 grid grid-cols-[1fr_auto_auto] gap-2">
                    <button type="button" data-im-here
                        class="rounded-xl border border-primary/25 bg-primary/5 py-3 text-sm font-semibold text-primary transition hover:bg-primary/10">
                        I'm here
                    </button>
                    <button type="button" data-next-step hidden
                        class="rounded-xl bg-primary py-3 text-sm font-semibold text-white shadow-md transition hover:bg-primary-hover">
                        Next
                    </button>
                    <button type="button" data-mute aria-pressed="false" aria-label="Mute voice"
                        class="grid size-12 place-items-center rounded-xl border border-border text-primary transition hover:bg-bg-light">
                        <svg data-icon-on viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/></svg>
                        <svg data-icon-off viewBox="0 0 24 24" class="hidden size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 5 6 9H3v6h3l5 4z"/><path d="m16 9 5 6M21 9l-5 6"/></svg>
                    </button>
                    <button type="button" data-end class="rounded-xl bg-red-600 px-5 text-sm font-semibold text-white transition hover:bg-red-700">
                        End
                    </button>
                </div>
                {{-- Filled in by the script with why manual mode is on. --}}
                <p data-sensor-note hidden role="status"
                    class="mt-2 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900"></p>
            </div>

            {{-- Arrived --}}
            <div data-panel="arrived" hidden class="px-4 pt-4 text-center">
                <span class="mx-auto grid size-14 place-items-center rounded-full bg-secondary/20 text-primary" aria-hidden="true">
                    <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 5 5 9-10"/></svg>
                </span>
                <h2 data-arrived-text class="mt-3 text-lg font-semibold text-primary" role="status"></h2>
                <button type="button" data-done
                    class="mt-4 w-full rounded-xl bg-primary py-3.5 text-sm font-bold text-white transition hover:bg-primary-hover">
                    Done
                </button>
            </div>
        </section>

        @if (request()->boolean('debug'))
            {{-- Admins also get every photo point, hidden ones too, to see why a photo is not on the route. --}}
            <details data-debug open aria-label="Debug panel"
                @if (auth()->user()?->isAdmin()) data-dbg-panoramas="{{ route('admin.panoramas.route-debug') }}" @endif
                class="absolute top-16 left-3 max-h-[40dvh] w-60 space-y-1 overflow-y-auto rounded-xl bg-black/75 px-3 py-2 font-mono text-[11px] leading-4 text-white">
                <summary class="cursor-pointer font-semibold">debug <span data-sim-flag hidden class="ml-1 rounded bg-amber-400 px-1 text-black">SIMULATED</span></summary>
                <p class="break-all">build: <span data-dbg="build"></span></p>
                <p class="break-words">gps: <span data-dbg="gps"></span></p>
                <p>speed: <span data-dbg="speed"></span></p>
                <p>heading: <span data-dbg="heading"></span></p>
                <p>steps: <span data-dbg="steps"></span></p>
                <p>motion events: <span data-dbg="motion"></span></p>
                <p>last peak: <span data-dbg="peak"></span></p>
                <p>position from: <span data-dbg="source"></span></p>
                <p class="break-words">off route: <span data-dbg="off-route"></span></p>
                <p>progress: <span data-dbg="progress"></span></p>
                <p>mode: <span data-dbg="mode"></span></p>
                <p class="break-words">sensors: <span data-dbg="sensors"></span></p>
                <p>graph: <span data-dbg="graph"></span></p>
                {{-- 360° photos on the current route (resources/js/route-panoramas.js), one per line. --}}
                <div class="break-words">photos: <span data-dbg="panoramas" class="whitespace-pre-line"></span></div>
                <label class="flex items-center justify-between gap-2 pt-1">stride (m)
                    <input data-dbg-stride type="number" step="0.01" min="0.3" max="1.4" class="w-16 rounded bg-white/15 px-1 py-0.5">
                </label>
                <label class="flex items-center justify-between gap-2">step threshold
                    <input data-dbg-threshold type="number" step="0.1" min="0.2" class="w-16 rounded bg-white/15 px-1 py-0.5">
                </label>
                <label class="flex items-center gap-2"><input data-dbg-graph type="checkbox" checked> show graph</label>
                <button type="button" data-dbg-step class="mt-1 w-full rounded bg-white/20 py-1 hover:bg-white/30">+1 step</button>
                {{-- Fake GPS for testing away from campus (resources/js/navigation/simulate.js). --}}
                <div class="mt-2 space-y-1 border-t border-white/20 pt-2">
                    <label class="flex items-center gap-2 font-semibold"><input data-sim-toggle type="checkbox"> Simulate GPS</label>
                    <p data-sim-badge hidden class="rounded bg-amber-400 py-0.5 text-center font-bold tracking-widest text-black">SIMULATED</p>
                    <div class="grid grid-cols-2 gap-1">
                        <button type="button" data-sim-walk disabled class="rounded bg-white/20 py-1 hover:bg-white/30 disabled:opacity-40">Walk route</button>
                        <button type="button" data-sim-walk-off disabled aria-pressed="false"
                            class="rounded bg-white/20 py-1 hover:bg-white/30 disabled:opacity-40 aria-pressed:bg-amber-400 aria-pressed:text-black">Walk off route</button>
                    </div>
                    <p data-sim-status class="text-white/80"></p>
                </div>
            </details>
        @endif
    </div>
@endsection
