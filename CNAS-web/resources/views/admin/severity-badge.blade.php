{{-- How serious an alert is, with its color ($level: an App\Enums\AlertSeverity). --}}
<span class="inline-flex items-center gap-1.5 rounded-full bg-bg-light px-2 py-0.5 text-xs font-semibold text-text-primary">
    <span class="size-2.5 rounded-full" style="background: {{ $level->color() }}" aria-hidden="true"></span>
    {{ $level->label() }}
</span>
