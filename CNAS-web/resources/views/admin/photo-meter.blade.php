{{-- How full a place's photo slots are: one tick per allowed photo. $count photos of $max. --}}
<span class="flex items-center gap-2">
    <span class="sr-only">{{ $count }} of {{ $max }} photos</span>
    <span class="flex gap-0.5" aria-hidden="true">
        @for ($slot = 1; $slot <= $max; $slot++)
            <span class="h-2.5 w-2 rounded-sm {{ $slot <= $count ? 'bg-primary' : 'bg-border' }}"></span>
        @endfor
    </span>
    <span class="text-xs tabular-nums text-text-muted" aria-hidden="true">{{ $count }}/{{ $max }}</span>
</span>
