{{-- A small phone showing what people get, updated as the admin types (resources/js/alert-wording.js): for an emergency
     the takeover (kind, title in two lines, location, the bold "What to do now", "More details", then the answer
     buttons pinned to the bottom); for a warning or advisory the toast. Decorative for screen readers: the fields
     themselves carry the text. Inputs: $type (AlertType), $severity (AlertSeverity), $drill (bool), $location. --}}
@use('App\Enums\AlertType')
@php
    $kind = ($drill ? 'DRILL' : mb_strtoupper($severity->label())).' · '.mb_strtoupper($type->label());
@endphp
<figure class="flex flex-col items-center gap-2" aria-hidden="true">
    <div data-phone-preview data-severity="{{ $severity->value }}" data-type="{{ $type->value }}" data-type-label="{{ $type->label() }}" @if ($drill) data-drill @endif
        class="group/phone relative flex h-[26rem] w-[13.5rem] flex-col overflow-hidden rounded-[2rem] border-[6px] border-stone-900 bg-bg-light shadow-xl">
        <span class="absolute top-1.5 left-1/2 z-10 h-1.5 w-14 -translate-x-1/2 rounded-full bg-stone-900"></span>

        {{-- Emergency: the takeover --}}
        <div class="hidden flex-1 flex-col bg-alarm text-white group-data-[severity=emergency]/phone:flex">
            <div class="hazard-tape hidden h-2 shrink-0 group-data-drill/phone:block"></div>
            <div class="flex min-h-0 flex-1 flex-col gap-1.5 px-3.5 pt-6">
                <p class="flex items-center gap-1.5 text-[10px] font-bold tracking-wide">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-white text-alarm">
                        @foreach (AlertType::cases() as $option)
                            <svg data-type-icon="{{ $option->value }}" @if ($option !== $type) hidden @endif class="size-3.5" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="{{ $option->icon() }}" />
                            </svg>
                        @endforeach
                    </span>
                    <span data-preview="kind">{{ $kind }}</span>
                </p>
                <p class="line-clamp-2 text-xl leading-tight font-semibold" data-preview="title"></p>
                <p class="flex items-center gap-1 truncate text-[11px] text-white/90">
                    <svg class="size-3 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
                    </svg>
                    <span class="truncate" data-preview="location">{{ $location }}</span>
                </p>
                <p class="mt-1 line-clamp-3 rounded-lg bg-black/20 px-2 py-1.5 text-xs leading-snug font-bold" data-preview="what_to_do"></p>
                <p class="text-[11px] font-semibold text-white/85 underline underline-offset-2">More details</p>
            </div>
            <div class="grid shrink-0 gap-1.5 px-3.5 pt-2 pb-4">
                <span class="flex h-8 items-center justify-center rounded-lg bg-white text-xs font-bold text-alarm">I'm safe</span>
                <span class="flex h-8 items-center justify-center rounded-lg border-2 border-white text-xs font-bold">I need help</span>
                <span class="text-center text-[10px] text-white/80">Dismiss</span>
            </div>
        </div>

        {{-- Warning or advisory: the toast over the page --}}
        <div class="flex flex-1 flex-col justify-end gap-2 p-2.5 group-data-[severity=emergency]/phone:hidden">
            <div class="space-y-2 opacity-40">
                <span class="block h-3 w-2/3 rounded bg-border"></span>
                <span class="block h-16 rounded-lg bg-border/70"></span>
                <span class="block h-16 rounded-lg bg-border/70"></span>
            </div>
            <div class="rounded-xl border border-border border-l-4 bg-surface p-2.5 shadow-lg group-data-[severity=warning]/phone:border-l-orange-600 group-data-[severity=advisory]/phone:border-l-yellow-400">
                <p class="text-[10px] font-bold tracking-wide text-orange-700 group-data-[severity=advisory]/phone:text-yellow-800" data-preview="kind">{{ $kind }}</p>
                <p class="mt-0.5 line-clamp-2 text-xs leading-snug font-semibold text-text-primary" data-preview="title"></p>
                <p class="mt-0.5 line-clamp-2 text-[11px] leading-snug text-text-muted" data-preview="what_to_do"></p>
            </div>
            <span class="mx-auto mt-1 h-7 w-full rounded-lg bg-surface/80"></span>
        </div>
    </div>
    <figcaption class="text-xs text-text-muted">What a phone shows</figcaption>
</figure>
