{{-- Full-screen alarm takeover (inside partials.alerts). resources/js/alerts shows it once per alert per device when it
     finds a new active alert, fills the data-field elements, sounds the alarm and vibrates until it's tapped.
     "Dismiss" (or Escape) collapses it to the banner. Drills keep the red so people practise the real thing, with
     hazard tape and a "DRILL" stamp. --}}
@use('App\Enums\AlertType')
<div data-alert-takeover hidden role="alertdialog" aria-modal="true" aria-labelledby="alert-takeover-title"
    aria-describedby="alert-takeover-instructions"
    class="group/takeover fixed inset-0 z-[100] flex flex-col overflow-y-auto bg-alarm text-white animate-beacon motion-reduce:animate-none">
    <div class="hazard-tape hidden h-4 shrink-0 group-data-drill/takeover:block" aria-hidden="true"></div>

    <div class="mx-auto flex w-full max-w-xl flex-1 flex-col px-6 pt-[max(2rem,env(safe-area-inset-top))] pb-[max(1.5rem,env(safe-area-inset-bottom))] sm:pt-14">
        <div class="flex items-center gap-4">
            <span class="grid size-16 shrink-0 place-items-center rounded-full bg-white text-alarm shadow-lg" aria-hidden="true">
                @foreach (AlertType::cases() as $option)
                    <svg data-type-icon="{{ $option->value }}" hidden class="size-9" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                        <path d="{{ $option->icon() }}" />
                    </svg>
                @endforeach
            </span>
            <p class="text-xl font-semibold" data-field="type_label"></p>
            <span class="ml-auto hidden -rotate-6 rounded-lg border-4 border-hazard px-3 py-0.5 text-3xl font-black tracking-[.2em] text-hazard group-data-drill/takeover:block">
                DRILL
            </span>
        </div>

        <h2 id="alert-takeover-title" class="mt-10 text-4xl leading-[1.05] font-semibold tracking-tight text-balance sm:text-5xl" data-field="title"></h2>

        <p class="mt-5 flex items-center gap-2 text-xl font-medium">
            <svg class="size-6 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true">
                <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
            </svg>
            <span data-field="location"></span>
        </p>

        <div id="alert-takeover-instructions" class="mt-6 max-w-prose space-y-3 text-lg leading-relaxed">
            <p class="text-white/85" data-field="message"></p>
            <p class="font-semibold" data-field="instructions"></p>
        </div>

        <p data-silence-hint hidden class="mt-6 inline-flex items-center gap-2 self-start rounded-full bg-black/20 px-3 py-1.5 text-sm">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true">
                <path d="M11 5 6 9H3v6h3l5 4zM16 9l5 6M21 9l-5 6" />
            </svg>
            Tap anywhere to silence the alarm
        </p>

        <div class="mt-auto grid gap-3 pt-10">
            <a href="{{ route('navigate', ['evacuate' => 1]) }}" data-route-to-safety
                class="flex min-h-14 items-center justify-center gap-2 rounded-2xl bg-white px-6 text-lg font-semibold text-alarm shadow-lg transition hover:bg-red-50">
                <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M5 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM19 9V3m0 0h-5m5 0-6 6M7 17h6a3 3 0 0 0 3-3v-1" />
                </svg>
                Show route to safety
            </a>
            @if ($canMarkSafe)
                @include('partials.alert-safe-button', ['size' => 'large'])
            @endif
            <button type="button" data-dismiss-takeover
                class="min-h-12 rounded-2xl text-base font-medium text-white/85 underline-offset-4 transition hover:text-white hover:underline">
                Dismiss
            </button>
        </div>
    </div>

    <div class="hazard-tape hidden h-4 shrink-0 group-data-drill/takeover:block" aria-hidden="true"></div>
</div>
