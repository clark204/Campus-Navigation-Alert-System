{{-- One saved or recent place: tapping it opens walking directions. Needs $room (Room with `building`) and $kind
     ('saved' or 'recent'; a recent room carries `pivot->used_at`). $removable: a Remove button (Saved places page). --}}
@php
    $saved = $kind === 'saved';
    $usedAt = $saved ? null : \Illuminate\Support\Carbon::parse($room->pivot->used_at);
@endphp
<li class="flex items-center gap-2">
    <a href="{{ route('navigate', ['to' => $room->code]) }}" aria-label="Directions to {{ $room->name }}, {{ $room->locationLabel() }}"
        class="-mx-2 flex min-w-0 flex-1 items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-bg-light focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none">
        <span @class([
            'flex size-9 shrink-0 items-center justify-center rounded-full',
            'bg-secondary/20 text-secondary-hover' => $saved,
            'bg-primary/10 text-primary' => ! $saved,
        ])>
            <svg class="size-4.5" viewBox="0 0 24 24" fill="{{ $saved ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8"
                stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                @if ($saved)
                    <path d="M6 4h12v17l-6-4-6 4z" />
                @else
                    <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm0-13v4l3 2" />
                @endif
            </svg>
        </span>
        <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-semibold">{{ $room->name }}</span>
            <span class="block truncate text-xs text-text-muted">{{ $room->locationLabel() }}</span>
        </span>
        @if ($usedAt)
            <time datetime="{{ $usedAt->toIso8601String() }}" class="shrink-0 text-xs text-text-muted">{{ $usedAt->diffForHumans(short: true) }}</time>
        @endif
    </a>
    @if ($removable ?? false)
        <form method="POST" action="{{ route('places.unsave', $room) }}" class="shrink-0">
            @csrf
            @method('DELETE')
            <button type="submit" aria-label="Remove {{ $room->name }} from saved places"
                class="grid size-11 place-items-center rounded-lg text-text-muted transition hover:bg-red-50 hover:text-red-700 focus-visible:ring-4 focus-visible:ring-secondary/40 focus-visible:outline-none">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                    aria-hidden="true">
                    <path d="M4 7h16M10 11v6m4-6v6M6 7l1 13h10l1-13M9 7V4h6v3" />
                </svg>
            </button>
        </form>
    @endif
</li>
