{{-- Full-screen alarm takeover (inside partials.alerts). resources/js/alerts shows it once per alert per device when it
     finds a new active emergency, fills the data-field elements, sounds the alarm and vibrates until it's tapped.
     "Dismiss" (or Escape) collapses it to the banner.

     Built to be read in three seconds: the kind ("EMERGENCY · FIRE" or "DRILL · FIRE"), the title (two lines at most),
     where, and one bold "What to do now" line. The full message, longer instructions and the time sent are under
     "More details", which scrolls on its own. The answers stay pinned to the bottom, always visible without scrolling,
     even on a 360 px phone with large text: "I'm safe" (white), "I need help" (outlined), then "Show route to safety",
     then Dismiss. Guests get the same without the answers, plus a link to sign in. Drills keep the red so people
     practise the real thing, with hazard tape and a "DRILL" stamp. --}}
@use('App\Enums\AlertType')
<div data-alert-takeover hidden role="alertdialog" aria-modal="true" aria-labelledby="alert-takeover-title"
    aria-describedby="alert-takeover-action"
    class="group/takeover fixed inset-0 z-[100] flex flex-col bg-alarm text-white animate-beacon motion-reduce:animate-none">
    <div class="hazard-tape hidden h-3 shrink-0 group-data-drill/takeover:block" aria-hidden="true"></div>

    {{-- What, where, what to do: scrolls by itself if it ever has to, so the buttons below never move. --}}
    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
        <div class="mx-auto flex w-full max-w-xl flex-col gap-3 px-5 pt-[max(1.25rem,env(safe-area-inset-top))] pb-4 sm:px-6 sm:pt-12">
            <div class="flex items-center gap-3">
                <span class="grid size-11 shrink-0 place-items-center rounded-full bg-white text-alarm shadow-lg" aria-hidden="true">
                    @foreach (AlertType::cases() as $option)
                        <svg data-type-icon="{{ $option->value }}" hidden class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="{{ $option->icon() }}" />
                        </svg>
                    @endforeach
                </span>
                <p class="min-w-0 text-sm font-bold tracking-[.12em]" data-field="kind"></p>
                <span class="ml-auto hidden shrink-0 -rotate-6 rounded-md border-[3px] border-hazard px-2 text-lg font-black tracking-[.2em] text-hazard group-data-drill/takeover:block" aria-hidden="true">
                    DRILL
                </span>
            </div>

            <h2 id="alert-takeover-title" class="line-clamp-2 text-[2rem] leading-[1.1] font-semibold tracking-tight text-balance sm:text-5xl" data-field="title"></h2>

            <p class="flex min-w-0 items-center gap-1.5 text-lg font-medium text-white/95">
                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
                </svg>
                <span class="truncate" data-field="location"></span>
            </p>

            <p id="alert-takeover-action" class="rounded-2xl bg-black/20 px-4 py-3 text-xl leading-snug font-bold" data-field="what_to_do"></p>

            <details class="group/details rounded-2xl bg-black/10" data-takeover-details>
                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 px-4 font-semibold [&::-webkit-details-marker]:hidden">
                    More details
                    <svg class="size-5 transition group-open/details:rotate-180 motion-reduce:transition-none" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="m6 9 6 6 6-6" />
                    </svg>
                </summary>
                <div class="max-w-prose space-y-3 px-4 pb-4 text-base leading-relaxed">
                    <p class="text-white/90" data-field="message"></p>
                    <p class="whitespace-pre-line" data-field="instructions"></p>
                    <p class="text-sm text-white/75" data-field="sent_time"></p>
                </div>
            </details>

            <p data-silence-hint hidden class="inline-flex items-center gap-2 self-start rounded-full bg-black/20 px-3 py-1.5 text-sm">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M11 5 6 9H3v6h3l5 4zM16 9l5 6M21 9l-5 6" />
                </svg>
                Tap anywhere to silence the alarm
            </p>
        </div>
    </div>

    {{-- Pinned: never scrolls away. --}}
    <div class="shrink-0 border-t border-white/15 bg-alarm-deep/40">
        <div class="mx-auto grid w-full max-w-xl gap-2.5 px-5 pt-3 pb-[max(.75rem,env(safe-area-inset-bottom))] sm:px-6">
            @if ($canMarkSafe)
                @include('partials.alert-safe-button', ['size' => 'large'])
            @else
                <a href="{{ route('login') }}" class="text-center text-sm text-white/90 underline underline-offset-4 hover:text-white">
                    Sign in to tell the school you're safe
                </a>
            @endif
            <a href="{{ route('navigate', ['evacuate' => 1]) }}" data-route-to-safety
                class="flex min-h-12 items-center justify-center gap-2 rounded-2xl bg-white/15 px-5 font-semibold transition hover:bg-white/25">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM19 9V3m0 0h-5m5 0-6 6M7 17h6a3 3 0 0 0 3-3v-1" />
                </svg>
                Show route to safety
            </a>
            <button type="button" data-dismiss-takeover
                class="min-h-11 rounded-2xl text-base font-medium text-white/85 underline-offset-4 transition hover:text-white hover:underline">
                Dismiss
            </button>
        </div>
    </div>

    <div class="hazard-tape hidden h-3 shrink-0 group-data-drill/takeover:block" aria-hidden="true"></div>
</div>
