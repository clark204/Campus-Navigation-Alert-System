{{-- One class in a list: subject, time, room, and "Go" (directions) when the room is on the map.
     Needs $class (ClassSchedule with `room`). $editable adds the "Edit" link (My schedule / My classes, whose routes
     are $scheduleRoute: student.schedule by default, or faculty.classes). Both actions are at
     least 44px tall so they are easy to tap. --}}
<li class="flex items-center gap-2 px-4 py-3 sm:px-5">
    <div class="min-w-0 flex-1">
        <p class="text-sm font-semibold break-words">
            {{ $class->subject }}
            @if ($class->section)
                <span class="font-normal text-text-muted">{{ $class->section }}</span>
            @endif
        </p>
        <p class="text-sm text-text-muted tabular-nums">{{ $class->timeLabel() }}</p>
        <div class="mt-0.5">
            @include('portal.class-room', ['class' => $class])
        </div>
    </div>

    @if ($class->room)
        <a href="{{ route('navigate', ['to' => $class->room->code]) }}" aria-label="Go: directions to {{ $class->room->name }}"
            class="inline-flex min-h-11 min-w-14 shrink-0 items-center justify-center rounded-lg bg-primary px-4 text-sm font-semibold text-white transition hover:bg-primary-hover">
            Go
        </a>
    @endif

    @if ($editable ?? false)
        <a href="{{ route(($scheduleRoute ?? 'student.schedule').'.edit', $class) }}" aria-label="Edit {{ $class->subject }}, {{ $class->day->label() }} {{ $class->timeLabel() }}"
            class="inline-flex min-h-11 shrink-0 items-center rounded-lg px-2.5 text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
            Edit
        </a>
    @endif
</li>
