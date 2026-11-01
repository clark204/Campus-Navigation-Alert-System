{{-- Registration progress. $current is 1 or 2. --}}
<ol class="mt-4 grid grid-cols-2 gap-2 text-[11px] font-semibold" aria-label="Registration steps">
    @foreach (['Upload COR', 'Create account'] as $index => $label)
        @php($number = $index + 1)
        <li @if ($number === $current) aria-current="step" @endif
            @class([
                'flex items-center gap-2 border-t-2 pt-2',
                'border-primary text-primary' => $number <= $current,
                'border-border text-text-muted' => $number > $current,
            ])>
            <span @class([
                'flex size-5 shrink-0 items-center justify-center rounded-full text-[10px]',
                'bg-primary text-white' => $number <= $current,
                'bg-border text-text-muted' => $number > $current,
            ])>{{ $number }}</span>
            <span class="truncate">{{ $label }}</span>
        </li>
    @endforeach
</ol>
