{{-- "I'm safe" for signed-in users (banner and takeover). resources/js/alerts posts it to alerts.safe and marks every
     copy of the button; without JavaScript it is a plain form. $size: 'small' (banner) or 'large' (takeover). --}}
<form method="POST" action="{{ $alert ? route('alerts.safe', $alert) : '#' }}" data-mark-safe-form
    @class(['flex', 'shrink-0' => $size === 'small'])>
    @csrf
    <button type="submit" data-mark-safe @if ($markedSafe) data-marked disabled @endif
        @class([
            'group/safe inline-flex w-full items-center justify-center gap-2 border-white/80 font-semibold text-white transition hover:bg-white/10 disabled:cursor-default disabled:border-transparent disabled:bg-safe disabled:hover:bg-safe',
            'min-h-11 rounded-xl border px-4 text-sm' => $size === 'small',
            'min-h-14 rounded-2xl border-2 px-6 text-lg' => $size === 'large',
        ])>
        <svg class="hidden size-5 group-data-marked/safe:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="m5 12 5 5 9-10" />
        </svg>
        <span class="group-data-marked/safe:hidden">I'm safe</span>
        <span class="hidden group-data-marked/safe:inline">Marked safe</span>
    </button>
</form>
