{{-- The one-time popup of a warning (inside partials.alerts, not on the admin console). A small card in the corner that
     doesn't block the page; resources/js/alerts/popup.js fills it, plays the chime and hides it on "Close" or Escape.
     The route to safety shows only when the alert has a location (JavaScript sets it). --}}
@use('App\Enums\AlertType')
<div data-alert-popup hidden role="dialog" aria-modal="false" aria-labelledby="alert-popup-title" aria-describedby="alert-popup-instructions"
    class="group/popup fixed inset-x-4 bottom-4 z-[90] mx-auto max-w-md rounded-2xl bg-orange-600 p-5 text-white shadow-2xl sm:inset-x-auto sm:right-6 sm:bottom-6">
    <div class="flex items-start gap-3">
        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-white/15" aria-hidden="true">
            @foreach (AlertType::cases() as $option)
                <svg data-type-icon="{{ $option->value }}" hidden class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                    stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                    <path d="{{ $option->icon() }}" />
                </svg>
            @endforeach
        </span>
        <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-white/90">
                <span class="hidden rounded bg-hazard px-1.5 text-xs font-black tracking-[.18em] text-stone-900 group-data-drill/popup:inline">DRILL</span>
                <span data-field="type_label"></span>
            </p>
            <h2 id="alert-popup-title" tabindex="-1" data-popup-title class="mt-0.5 text-lg leading-snug font-semibold focus:outline-none" data-field="title"></h2>
        </div>
        <button type="button" data-dismiss-popup aria-label="Close"
            class="-mt-1 -mr-1 grid size-10 shrink-0 place-items-center rounded-xl text-white/85 transition hover:bg-white/15 hover:text-white">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M6 6l12 12M18 6 6 18" />
            </svg>
        </button>
    </div>

    <div id="alert-popup-instructions" class="mt-3 space-y-2 text-sm leading-relaxed">
        <p class="flex items-center gap-1.5 text-white/90">
            <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
            </svg>
            <span data-field="location"></span>
        </p>
        <p data-field="message"></p>
        <p class="font-semibold" data-field="instructions"></p>
    </div>

    <a href="{{ route('navigate', ['evacuate' => 1]) }}" data-route-to-safety hidden
        class="mt-4 inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-semibold text-orange-700 transition hover:bg-orange-50">
        Show route to safety
    </a>
</div>
