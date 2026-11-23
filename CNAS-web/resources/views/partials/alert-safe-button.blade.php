{{-- "I'm safe" and "I need help" for signed-in users (banner and takeover). resources/js/alerts posts them to
     alerts.safe and alerts.help and marks every copy of the buttons; without JavaScript they are plain forms.
     "I need help" opens a short note ("2nd floor, NEE 201, hurt my ankle") for the administrators, who get a push.
     $size: 'small' (banner) or 'large' (takeover). $markedSafe / $askedForHelp: this user's answer for the alert.
     "I'm safe" is the white button and "I need help" the outlined one, the same as the app. --}}
@use('App\Http\Requests\NeedHelpRequest')
<div data-safety-answers @class(['flex flex-col gap-2', 'shrink-0' => $size === 'small'])>
    <form method="POST" action="{{ $alert ? route('alerts.safe', $alert) : '#' }}" data-mark-safe-form class="flex">
        @csrf
        <button type="submit" data-mark-safe @if ($markedSafe) data-marked disabled @endif
            @class([
                'group/safe inline-flex w-full items-center justify-center gap-2 bg-white font-bold text-alarm shadow-md transition hover:bg-red-50 disabled:cursor-default disabled:bg-safe disabled:text-white disabled:shadow-none disabled:hover:bg-safe',
                'min-h-11 rounded-xl px-4 text-sm' => $size === 'small',
                'min-h-14 rounded-2xl px-6 text-lg' => $size === 'large',
            ])>
            <svg class="hidden size-5 group-data-marked/safe:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="m5 12 5 5 9-10" />
            </svg>
            <span class="group-data-marked/safe:hidden">I'm safe</span>
            <span class="hidden group-data-marked/safe:inline">Marked safe</span>
        </button>
    </form>

    <details data-need-help @if ($askedForHelp ?? false) data-requested @endif class="group/help">
        <summary @class([
            'inline-flex w-full cursor-pointer list-none items-center justify-center gap-2 border-white font-bold text-white transition hover:bg-white/10 [&::-webkit-details-marker]:hidden',
            'min-h-11 rounded-xl border-2 px-4 text-sm' => $size === 'small',
            'min-h-14 rounded-2xl border-2 px-6 text-lg' => $size === 'large',
        ])>
            <span class="group-data-requested/help:hidden">I need help</span>
            <span class="hidden group-data-requested/help:inline">Help requested</span>
        </summary>
        <form method="POST" action="{{ $alert ? route('alerts.help', $alert) : '#' }}" data-need-help-form class="mt-2 grid gap-2 rounded-xl bg-white/10 p-3">
            @csrf
            <label class="text-sm font-semibold text-white" for="need-help-note-{{ $size }}">Where are you? What happened? (optional)</label>
            <textarea id="need-help-note-{{ $size }}" name="note" rows="2" maxlength="{{ NeedHelpRequest::NOTE_MAX }}"
                placeholder="2nd floor, NEE 201. I hurt my ankle."
                class="w-full rounded-lg border-0 px-3 py-2 text-sm text-text-primary"></textarea>
            {{-- resources/js/alerts/help-tracker.js fills these from the browser's location when the box is ticked. --}}
            <input type="hidden" name="latitude" data-help-latitude>
            <input type="hidden" name="longitude" data-help-longitude>
            <input type="hidden" name="accuracy" data-help-accuracy>
            <label class="flex items-start gap-2 text-sm text-white">
                <input type="checkbox" data-help-share checked class="mt-0.5 size-4 shrink-0 rounded accent-white">
                <span>Share my location with the responders <span class="block text-xs text-white/80">Only while this page is open, until you are helped.</span></span>
            </label>
            <button type="submit" class="min-h-11 rounded-lg bg-white px-4 text-sm font-bold text-alarm transition hover:bg-red-50 disabled:opacity-70">Send to the administrators</button>
            <p data-need-help-status role="status" class="text-xs text-white/90"></p>
        </form>
    </details>
    {{-- Where their request is ("<admin> is on the way"), kept up to date by resources/js/alerts/help-tracker.js. --}}
    <p data-help-state role="status" @unless ($askedForHelp ?? false) hidden @endunless
        {{-- w-0 min-w-full: as wide as the buttons, wrapping, so it never widens the banner (and the page) on a phone. --}}
        @class(['w-0 min-w-full rounded-lg bg-black/20 px-3 py-2 font-semibold text-white', 'text-xs' => $size === 'small', 'text-sm' => $size === 'large'])>
        @if ($askedForHelp ?? false) {{ \App\Http\Controllers\NeedHelpController::THANKS }} @endif
    </p>
</div>
