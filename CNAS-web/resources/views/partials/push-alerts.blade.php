{{-- "Turn on emergency alerts" (browser push) for this device. resources/js/alerts sets data-state:
     off | working | on | denied | unsupported | ios (iPhone/iPad Safari that hasn't added CNAS to the Home Screen).
     Hidden without JavaScript, since push needs it.
     $variant: 'card' (portal dashboard) or 'hero' (landing page, guests: a small dismissible prompt on the maroon hero
     that hides once alerts are on or can't be turned on).
     $hideStates: states in which the section hides itself (the portal home: "on denied unsupported", since turning
     alerts off and fixing a block live in Settings → Alerts). --}}
@php
    $hero = $variant === 'hero';
    $text = $hero ? 'text-white/75' : 'text-text-muted';
    $hideStates ??= $hero ? 'on denied unsupported' : null;
@endphp
<section data-push-alerts data-state="off" hidden
    @if ($hideStates) data-push-hide-states="{{ $hideStates }}" @endif
    @if ($hero) data-push-dismissible @endif
    aria-labelledby="push-alerts-title-{{ $variant }}"
    @class([
        'group/push',
        'rounded-2xl border border-border bg-surface p-5' => ! $hero,
        'rounded-2xl border border-white/20 bg-white/10 p-4 text-white backdrop-blur-sm' => $hero,
    ])>
    <div class="flex flex-wrap items-start gap-x-4 gap-y-3">
        <span @class([
            'grid size-11 shrink-0 place-items-center rounded-xl',
            'bg-alarm/10 text-alarm' => ! $hero,
            'bg-white/15 text-white' => $hero,
        ]) aria-hidden="true">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"
                stroke-linejoin="round">
                <path d="M6 9a6 6 0 1 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9zM10 20a2.2 2.2 0 0 0 4 0" />
            </svg>
        </span>

        <div class="min-w-0 flex-1 basis-56">
            <h2 id="push-alerts-title-{{ $variant }}" class="font-semibold">
                {{ $hero ? 'Get campus emergency alerts' : 'Emergency alerts on this device' }}
            </h2>

            <p class="mt-1 hidden text-sm group-data-[state=off]/push:block group-data-[state=working]/push:block {{ $text }}">
                A notification for fires, earthquakes and other emergencies at TCC, even when CNAS is closed.
            </p>
            <p class="mt-1 hidden text-sm group-data-[state=on]/push:block {{ $text }}">
                On. This device gets a notification as soon as an alert is sent.
            </p>
            <p class="mt-1 hidden text-sm group-data-[state=denied]/push:block {{ $text }}">
                Notifications are blocked for CNAS. Allow them in your browser's site settings, then reload this page.
            </p>
            <p class="mt-1 hidden text-sm group-data-[state=unsupported]/push:block {{ $text }}">
                This browser can't show notifications. CNAS still sounds the alarm while it's open.
            </p>
            <div class="mt-1 hidden text-sm group-data-[state=ios]/push:block {{ $text }}">
                <p>Add CNAS to your Home Screen to get emergency alerts.</p>
                <ol class="mt-2 flex list-decimal flex-col gap-1 pl-5">
                    <li>
                        Tap the Share button
                        <svg class="inline size-4 align-[-2px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round" aria-label="Share">
                            <path d="M12 15V3m0 0L8 7m4-4 4 4M7 11H5v10h14V11h-2" />
                        </svg>
                        in Safari.
                    </li>
                    <li>Choose “Add to Home Screen”.</li>
                    <li>Open CNAS from your Home Screen and turn on alerts.</li>
                </ol>
            </div>
            <p data-push-error role="alert" hidden @class(['mt-2 text-sm font-medium', 'text-red-700' => ! $hero, 'text-[#ffd6d6]' => $hero])></p>
        </div>

        <div class="flex shrink-0 flex-wrap items-center gap-2 max-sm:w-full">
            <button type="button" data-push-turn-on
                @class([
                    'hidden min-h-11 items-center justify-center rounded-xl px-4 text-sm font-semibold transition group-data-[state=off]/push:inline-flex group-data-[state=working]/push:inline-flex group-data-[state=working]/push:opacity-60 max-sm:flex-1',
                    'bg-alarm text-white hover:bg-alarm-deep' => ! $hero,
                    'bg-white text-alarm hover:bg-red-50' => $hero,
                ])>
                Turn on emergency alerts
            </button>
            <button type="button" data-push-turn-off
                class="hidden min-h-11 items-center justify-center rounded-xl border border-border px-4 text-sm font-semibold text-text-primary transition group-data-[state=on]/push:inline-flex hover:border-primary hover:text-primary">
                Turn off
            </button>
            @if ($hero)
                <button type="button" data-push-dismiss
                    class="min-h-11 rounded-xl px-3 text-sm font-semibold text-white/75 transition hover:text-white">
                    Not now
                </button>
            @endif
        </div>
    </div>
</section>
