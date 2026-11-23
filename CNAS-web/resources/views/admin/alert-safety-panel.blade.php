{{-- Who answered during the active alert (SafetyCheckSummary): "I need help" in red first with the note, the counts,
     college chips (?college= lists that college) and the names. Admin-only. Rendered on Admin > Alerts and by
     admin.alerts.safety, which resources/js/alert-safety-live.js asks every 10 s to keep it live without a reload.
     Needs $alert, $safety, $safePeople, $safetyCollege. --}}
@php($campusTime ??= fn ($time) => $time?->timezone('Asia/Manila')->format('M j, Y, g:i A'))
<div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
    <h3 id="safety-heading" class="text-sm font-semibold text-safe">I'm safe</h3>
    @if ($safety['need_help'] > 0)
        <p class="text-sm font-semibold text-alarm">
            <span class="text-2xl tabular-nums">{{ $safety['need_help'] }}</span> need help
        </p>
    @endif
    <p class="text-sm text-safe">
        <span class="text-2xl font-semibold tabular-nums">{{ $safety['safe'] }}</span>
        {{ Str::plural('person', $safety['safe']) }} safe ·
        <span class="tabular-nums">{{ $safety['not_answered'] }}</span> not answered yet
    </p>
    <span class="text-xs text-safe/80">Updates by itself · {{ now()->timezone('Asia/Manila')->format('g:i:s A') }}</span>
</div>
@if ($safety['colleges'] !== [])
    <ul class="mt-3 flex flex-wrap gap-1.5 text-xs">
        <li>
            <a href="{{ route('admin.alerts.index') }}#safety" @class(['inline-flex min-h-8 items-center rounded-full border px-3 font-semibold', 'border-safe bg-safe text-white' => $safetyCollege === null, 'border-safe/30 bg-white text-safe' => $safetyCollege !== null])>Everyone</a>
        </li>
        @foreach ($safety['colleges'] as $college)
            <li>
                <a href="{{ route('admin.alerts.index', ['college' => $college['id']]) }}#safety"
                    @class(['inline-flex min-h-8 items-center gap-1 rounded-full border px-3 font-semibold', 'border-safe bg-safe text-white' => $safetyCollege === $college['id'], 'border-safe/30 bg-white text-safe' => $safetyCollege !== $college['id']])>
                    {{ $college['name'] }} <span class="tabular-nums font-normal">{{ $college['safe'] }}/{{ $college['total'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
@if ($safePeople->isEmpty())
    <p class="mt-3 text-xs text-text-muted">Nobody here has answered yet.</p>
@else
    <ul class="mt-3 max-h-64 divide-y divide-safe/10 overflow-y-auto text-sm">
        @foreach ($safePeople as $check)
            @php($needsHelp = $check->status === \App\Enums\SafetyStatus::NeedHelp)
            <li @class(['flex items-baseline justify-between gap-3 py-1.5', '-mx-2 rounded-lg bg-red-50 px-2' => $needsHelp])>
                <span>
                    @if ($needsHelp)
                        <span class="mr-1 rounded bg-alarm px-1.5 py-0.5 text-xs font-bold text-white">Needs help</span>
                    @endif
                    <span class="font-medium">{{ $check->user->full_name }}</span> <span class="text-xs text-text-muted">{{ $check->user->role->label() }}@if ($check->user->program?->college) · {{ $check->user->program->college->name }}@endif</span>
                    @if ($needsHelp && filled($check->note))
                        <span class="mt-0.5 block text-sm text-alarm">“{{ $check->note }}”</span>
                    @endif
                </span>
                <span class="shrink-0 text-xs text-text-muted tabular-nums">{{ $campusTime($check->responded_at) }}</span>
            </li>
        @endforeach
    </ul>
@endif
