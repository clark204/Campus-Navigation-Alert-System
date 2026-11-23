{{-- The help queue during an emergency (docs/alerts.md): every "I need help", the longest waiting first, with the
     person's note, where they are and "I'm responding" / "Mark helped". Rendered on Admin > Alerts and by
     admin.alerts.help, which resources/js/alert-safety-live.js asks every 5 s; resources/js/alerts/help-map.js reads
     the points (data-help-points) after each refresh to move the dots, fill in the building and distance, and beep
     for a new request. Admin-only (locations: RA 10173). Needs $alert and $helpRequests (SafetyCheckSummary). --}}
@use('App\Enums\HelpStatus')
@use('App\Models\SafetyCheck')
@use('App\Services\SafetyCheckSummary')
@use('Carbon\CarbonInterface')
@php
    $ago = fn ($time) => $time->diffForHumans(['parts' => 1, 'short' => true, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    $open = $helpRequests->filter(fn ($check) => $check->helpStatus()->isOpen());
    $helped = $helpRequests->reject(fn ($check) => $check->helpStatus()->isOpen());
    $points = $helpRequests->map(fn ($check) => [
        ...SafetyCheckSummary::helpRequestData($check),
        'stale' => $check->located_at === null || $check->located_at->lt(now()->subSeconds(SafetyCheck::LOCATION_FRESH_SECONDS)),
    ])->values();
@endphp
<script type="application/json" data-help-points>@json(['open' => $open->count(), 'requests' => $points])</script>

@if ($helpRequests->isEmpty())
    <div class="flex h-full flex-col items-start justify-center gap-2 rounded-xl border border-dashed border-border p-5">
        <p class="font-semibold text-primary">No one has asked for help</p>
        <p class="max-w-sm text-sm leading-relaxed text-text-muted">When someone taps “I need help”, they appear here and as a red dot on the map, with their location if they share it.</p>
    </div>
@else
    @if ($open->isEmpty())
        <p class="flex items-center gap-2 rounded-xl bg-safe-tint px-4 py-3 text-sm font-semibold text-safe">
            <svg class="size-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m5 12 4 4L19 6" /></svg>
            Everyone who asked has been helped.
        </p>
    @endif

    <ol class="grid gap-3" aria-label="Open help requests">
        @foreach ($open as $check)
            @php
                $status = $check->helpStatus();
                $responding = $status === HelpStatus::Responding;
                $mine = $check->responder_id === auth()->id();
                $fresh = $check->located_at?->gte(now()->subSeconds(SafetyCheck::LOCATION_FRESH_SECONDS));
            @endphp
            <li data-help-card="{{ $check->id }}" tabindex="-1"
                @class([
                    'scroll-mt-24 rounded-xl border border-l-4 bg-surface p-4 outline-none transition-shadow data-highlight:shadow-[0_0_0_3px_var(--color-secondary)]',
                    'border-alarm/30 border-l-alarm' => ! $responding,
                    'border-amber-300 border-l-amber-500' => $responding,
                ])>
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-2">
                    <div class="min-w-0">
                        <p class="font-semibold leading-snug wrap-anywhere">{{ $check->user->full_name }}</p>
                        <p class="text-xs text-text-muted">{{ $check->user->role->label() }}@if ($check->user->program?->college), {{ $check->user->program->college->name }}@endif</p>
                    </div>
                    @if ($responding)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900">
                            <span class="size-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                            {{ $mine ? 'You are on the way' : ($check->responder?->full_name ?? 'An admin').' is on the way' }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-alarm px-2.5 py-1 text-xs font-semibold text-white">
                            <span class="relative flex size-1.5" aria-hidden="true"><span class="dot-pulse absolute inset-0 rounded-full bg-white"></span><span class="relative size-1.5 rounded-full bg-white"></span></span>
                            Waiting {{ $ago($check->responded_at) }}
                        </span>
                    @endif
                </div>

                @if (filled($check->note))
                    <p class="mt-3 rounded-lg bg-bg-light px-3 py-2 text-sm leading-relaxed wrap-anywhere">“{{ $check->note }}”</p>
                @endif

                <p class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    <svg class="size-3.5 shrink-0 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" /></svg>
                    @if ($check->hasLocation())
                        <span class="font-medium" data-help-where="{{ $check->id }}">On the map</span>
                        <span @class(['text-text-muted' => $fresh, 'font-semibold text-amber-800' => ! $fresh])>
                            {{ $fresh ? 'Live, updated '.$ago($check->located_at).' ago' : 'Last seen '.$ago($check->located_at).' ago' }}@if ($check->accuracy_m), within {{ $check->accuracy_m }} m @endif
                        </span>
                    @else
                        <span class="text-text-muted">No location shared. Check the note or call them.</span>
                    @endif
                </p>

                <div class="mt-4 flex flex-wrap gap-2">
                    @if ($check->hasLocation())
                        <button type="button" data-help-focus="{{ $check->id }}" class="inline-flex min-h-10 items-center rounded-lg border border-border px-3 text-sm font-semibold text-primary transition hover:bg-bg-light">Show on map</button>
                    @endif
                    @if (! $responding || ! $mine)
                        <form method="POST" action="{{ route('admin.alerts.help.respond', [$alert, $check]) }}" data-help-action>
                            @csrf
                            <button type="submit" @class([
                                'inline-flex min-h-10 items-center rounded-lg px-4 text-sm font-semibold transition disabled:opacity-60',
                                'bg-alarm text-white hover:bg-alarm-deep' => ! $responding,
                                'border border-border text-text-primary hover:bg-bg-light' => $responding,
                            ])>{{ $responding ? 'Take over' : 'I’m responding' }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.alerts.help.helped', [$alert, $check]) }}" data-help-action>
                        @csrf
                        <button type="submit" @class([
                            'inline-flex min-h-10 items-center rounded-lg px-4 text-sm font-semibold transition disabled:opacity-60',
                            'bg-safe text-white hover:bg-safe/90' => $responding,
                            'border border-safe/40 text-safe hover:bg-safe-tint' => ! $responding,
                        ])>Mark helped</button>
                    </form>
                </div>
            </li>
        @endforeach
    </ol>

    @if ($helped->isNotEmpty())
        <details class="group mt-4 rounded-xl border border-border bg-surface" @if ($open->isEmpty()) open @endif>
            <summary class="flex min-h-11 cursor-pointer list-none items-center justify-between gap-2 px-4 text-sm font-semibold text-safe [&::-webkit-details-marker]:hidden">
                Helped ({{ $helped->count() }})
                <svg class="size-4 shrink-0 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
            </summary>
            <ul class="divide-y divide-border border-t border-border text-sm">
                @foreach ($helped as $check)
                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 px-4 py-2.5">
                        <span class="font-medium wrap-anywhere">{{ $check->user->full_name }}</span>
                        <span class="text-xs text-text-muted">by {{ $check->helper?->full_name ?? 'a deleted account' }}, {{ $ago($check->helped_at) }} ago</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endif
