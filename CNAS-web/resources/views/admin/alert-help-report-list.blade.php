{{-- An alert's report (admin/alert-report-page): every help request and how it was handled, with the points for the
     still map (resources/js/alerts/help-map.js). Needs $helpRequests (SafetyCheckSummary::helpRequests). --}}
@use('App\Enums\HelpStatus')
@use('App\Services\SafetyCheckSummary')
@use('Carbon\CarbonInterface')
@php
    $span = fn ($from, $to) => $from->diffForHumans($to, ['parts' => 2, 'short' => true, 'syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    $campusClock = fn ($time) => $time?->timezone('Asia/Manila')->format('g:i A');
@endphp
<script type="application/json" data-help-points>@json(['open' => 0, 'requests' => $helpRequests->map(fn ($check) => [...SafetyCheckSummary::helpRequestData($check), 'stale' => false])->values()])</script>

@if ($helpRequests->isEmpty())
    <div class="rounded-xl border border-dashed border-border p-5">
        <p class="font-semibold text-primary">No one asked for help</p>
        <p class="mt-1 text-sm text-text-muted">Nobody tapped “I need help” during this alert.</p>
    </div>
@else
    <ol class="grid gap-3">
        @foreach ($helpRequests as $check)
            @php($status = $check->helpStatus())
            <li data-help-card="{{ $check->id }}" tabindex="-1" @class([
                'rounded-xl border border-l-4 bg-surface p-4 outline-none transition-shadow data-highlight:shadow-[0_0_0_3px_var(--color-secondary)]',
                'border-safe/30 border-l-safe' => $status === HelpStatus::Helped,
                'border-alarm/30 border-l-alarm' => $status !== HelpStatus::Helped,
            ])>
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <p class="font-semibold wrap-anywhere">{{ $check->user->full_name }}</p>
                        <p class="text-xs text-text-muted">{{ $check->user->role->label() }}@if ($check->user->program?->college), {{ $check->user->program->college->name }}@endif</p>
                    </div>
                    <span @class([
                        'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                        'bg-safe-tint text-safe' => $status === HelpStatus::Helped,
                        'bg-red-50 text-alarm' => $status !== HelpStatus::Helped,
                    ])>{{ $status === HelpStatus::Helped ? 'Helped in '.$span($check->responded_at, $check->helped_at) : 'Not marked helped' }}</span>
                </div>
                @if (filled($check->note))
                    <p class="mt-3 rounded-lg bg-bg-light px-3 py-2 text-sm leading-relaxed wrap-anywhere">“{{ $check->note }}”</p>
                @endif
                @if ($check->hasLocation())
                    <p class="mt-2 text-xs"><span class="font-medium" data-help-where="{{ $check->id }}">On the map</span></p>
                @endif
                <ol class="mt-3 space-y-1 border-l-2 border-border pl-3 text-xs leading-relaxed text-text-muted">
                    <li>{{ $campusClock($check->responded_at) }}: asked for help</li>
                    @if ($check->responding_at)
                        <li>{{ $campusClock($check->responding_at) }}: {{ $check->responder?->full_name ?? 'A deleted account' }} responded</li>
                    @endif
                    @if ($check->helped_at)
                        <li>{{ $campusClock($check->helped_at) }}: marked helped by {{ $check->helper?->full_name ?? 'a deleted account' }}</li>
                    @endif
                </ol>
                @if ($check->hasLocation())
                    <button type="button" data-help-focus="{{ $check->id }}" class="mt-3 inline-flex min-h-10 items-center rounded-lg border border-border px-3 text-sm font-semibold text-primary transition hover:bg-bg-light">Show on map</button>
                @endif
            </li>
        @endforeach
    </ol>
@endif
