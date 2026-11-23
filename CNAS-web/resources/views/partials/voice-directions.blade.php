{{-- "Voice directions" switch for this browser: spoken turn-by-turn directions on /navigate. Off by default
     (navigation/constants.js VOICE_DIRECTIONS_DEFAULT), saved in localStorage (navigation/storage.js), wired by
     resources/js/voice-directions.js. Hidden until the script shows it, since it needs JavaScript.
     $variant: 'card' (portal Settings → Navigation) or 'compact' (the /navigate planning panel, for guests and admins).
     The mute button while walking only changes that trip. The route to safety always speaks. --}}
@php($compact = ($variant ?? 'card') === 'compact')
<div data-voice-directions hidden
    @class([
        'flex items-center gap-4',
        'rounded-2xl border border-border bg-surface p-5' => ! $compact,
        'rounded-xl bg-bg-light px-4 py-2.5' => $compact,
    ])>
    @unless ($compact)
        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-primary/10 text-primary" aria-hidden="true">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">
                <path d="M11 5 6 9H3v6h3l5 4z" /><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" />
            </svg>
        </span>
    @endunless
    <div class="min-w-0 flex-1">
        <label for="voice-directions-{{ $variant ?? 'card' }}" @class(['font-semibold', 'text-sm' => $compact])>Voice directions</label>
        <p id="voice-directions-{{ $variant ?? 'card' }}-hint" @class(['text-text-muted', 'mt-1 text-sm' => ! $compact, 'text-xs' => $compact])>
            @if ($compact)
                Speak each turn while you walk.
            @else
                Speak each turn out loud while you walk with Directions, on this browser. You can still mute it during a walk.
            @endif
        </p>
    </div>
    <input id="voice-directions-{{ $variant ?? 'card' }}" type="checkbox" role="switch" data-voice-directions-input
        aria-describedby="voice-directions-{{ $variant ?? 'card' }}-hint"
        class="relative h-8 w-14 shrink-0 cursor-pointer appearance-none rounded-full bg-border transition before:absolute before:top-1 before:left-1 before:size-6 before:rounded-full before:bg-white before:shadow before:transition-transform checked:bg-primary checked:before:translate-x-6 focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none motion-reduce:transition-none motion-reduce:before:transition-none">
</div>
