{{-- Where a class meets, in one of four forms that differ by icon and wording, not only by colour:
     a room on the map (pin + the map's name), "Online" (screen), "TBA" (clock), or a room that is not on the map yet
     (dashed pin + the text as written + a note). Needs $class (ClassSchedule with `room` set by RoomMatcher::attach). --}}
@use('App\Services\RoomMatcher')
@php
    $isRoom = RoomMatcher::isRoom($class->room_text);
    $isOnline = ! $isRoom && RoomMatcher::normalize($class->room_text) === 'online';
@endphp
<p class="flex flex-wrap items-center gap-x-1.5 text-sm">
    <svg class="size-4 shrink-0 {{ $class->room ? 'text-primary' : 'text-text-muted' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
        @if ($isRoom && ! $class->room) stroke-dasharray="2.5 3" @endif>
        @if ($isOnline)
            <path d="M4 5h16v11H4zM9 20h6M12 16v4" />
        @elseif (! $isRoom)
            <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm0-13v4l3 2" />
        @else
            <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
        @endif
    </svg>
    @if ($class->room)
        <span class="font-medium">{{ $class->room->name }}</span>
    @elseif ($isRoom)
        <span class="text-text-muted">{{ $class->room_text }}</span>
        <span class="text-xs text-text-muted italic">(not on the map yet)</span>
    @else
        <span class="text-text-muted">{{ $class->room_text }}</span>
        <span class="sr-only">{{ $isOnline ? '(online class, no room)' : '(room to be announced)' }}</span>
    @endif
</p>
