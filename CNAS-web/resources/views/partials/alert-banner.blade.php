{{-- Emergency banner (inside partials.alerts). Red while an alert is active, green for "All clear" or "False alarm".
     Server-rendered so it works without JavaScript; resources/js/alerts refills the data-field elements on each poll.
     $compactBanner (the emergency map): title and location only, since the map's own panel has the rest. --}}
@use('App\Enums\AlertType')
@php
    $state = $alert === null ? 'none' : ($alert->isActive() ? 'active' : 'clear');
    // "Show route to safety": an emergency always; a warning with a location; never an advisory (see banner-state.js).
    $showsRoute = $alert?->isActive() && ($alert->isEmergency() || ($alert->severity->value === 'warning' && $alert->hasLocation()));
@endphp
<div data-alert-banner data-state="{{ $state }}" data-version="{{ $alert?->version() }}" data-severity="{{ $alert?->severity->value }}"
    @if ($alert?->is_drill) data-drill @endif @if ($alert === null) hidden @endif
    class="group/alert relative z-50 bg-alarm text-white data-[state=clear]:bg-safe
        data-[severity=warning]:data-[state=active]:bg-orange-600
        data-[severity=advisory]:data-[state=active]:bg-yellow-300 data-[severity=advisory]:data-[state=active]:text-stone-900 data-[severity=advisory]:data-[state=active]:[&_.text-white\/85]:text-stone-800">
    <div class="hazard-tape hidden h-1.5 group-data-drill/alert:block" aria-hidden="true"></div>

    <div role="alert" class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-5 gap-y-3 px-4 py-3 sm:px-8">
        <span class="grid size-10 shrink-0 place-items-center self-start rounded-xl bg-white/15" aria-hidden="true">
            @foreach (AlertType::cases() as $option)
                <svg data-type-icon="{{ $option->value }}" @if ($alert?->type !== $option) hidden @endif
                    class="size-6 group-data-[state=clear]/alert:hidden" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                    <path d="{{ $option->icon() }}" />
                </svg>
            @endforeach
            <svg class="hidden size-6 group-data-[state=clear]/alert:block" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm-4-9 3 3 5-6" />
            </svg>
        </span>

        <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                <span class="hidden rounded bg-hazard px-1.5 text-xs font-black tracking-[.18em] text-stone-900 group-data-drill/alert:inline">DRILL</span>
                <span class="font-semibold group-data-[state=clear]/alert:hidden" data-field="type_label">{{ $alert?->type->label() }}</span>
                <span class="hidden font-semibold group-data-[state=clear]/alert:inline" data-field="resolution_label">{{ $alert?->resolution?->label() ?? 'All clear' }}</span>
                <span class="inline-flex items-center gap-1 text-white/85 group-data-[state=clear]/alert:hidden">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                        stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
                    </svg>
                    <span data-field="location">{{ $alert?->isActive() ? $alert->locationLabel() : '' }}</span>
                </span>
            </p>
            <p class="mt-0.5 text-lg leading-snug font-semibold" data-field="title">{{ $alert?->title }}</p>
            <div @class(['hidden' => $compactBanner ?? false])>
                <p class="mt-0.5 text-sm text-white/90 group-data-[state=clear]/alert:hidden" data-field="message">{{ $alert?->message }}</p>
                <p class="mt-0.5 text-sm font-medium group-data-[state=clear]/alert:hidden" data-field="instructions"
                    @if (blank($alert?->instructions)) hidden @endif>{{ $alert?->instructions }}</p>
            </div>
            <p class="mt-0.5 hidden text-sm group-data-[state=clear]/alert:block" data-field="resolved_message">{{ $alert?->resolved_message }}</p>
        </div>

        @if (! ($compactBanner ?? false) || $canMarkSafe)
            <div class="flex w-full gap-2 sm:w-auto group-data-[state=clear]/alert:hidden">
                @unless ($compactBanner ?? false)
                    <a href="{{ route('navigate', ['evacuate' => 1]) }}" data-route-to-safety @unless ($showsRoute) hidden @endunless
                        class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-semibold whitespace-nowrap text-alarm transition hover:bg-red-50 sm:flex-none">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                            stroke-linejoin="round" aria-hidden="true">
                            <path d="M5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM19 9V3m0 0h-5m5 0-6 6M7 17h6a3 3 0 0 0 3-3v-1" />
                        </svg>
                        Show route to safety
                    </a>
                @endunless
                @if ($canMarkSafe)
                    @include('partials.alert-safe-button', ['size' => 'small'])
                @endif
            </div>
        @endif

        {{-- Only the green banner can be closed; an active alert stays up. Remembered per alert on this device. --}}
        <button type="button" data-dismiss-banner aria-label="Close"
            class="-mr-2 hidden size-11 shrink-0 place-items-center self-start rounded-xl text-white/85 transition hover:bg-white/15 hover:text-white group-data-[state=clear]/alert:grid">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true">
                <path d="M6 6l12 12M18 6 6 18" />
            </svg>
        </button>
    </div>
</div>
