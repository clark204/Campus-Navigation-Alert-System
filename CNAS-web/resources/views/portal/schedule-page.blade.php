{{-- "My schedule" (students) or "My classes" (faculty): the current term's classes, one section per day. On a phone the
     days stack with today first; from md they sit in columns. Needs $days (day value => classes), $today (Weekday),
     $term, $hasClasses, and from Portal\ClassScheduleController::portal(): $scheduleRoute (student.schedule or
     faculty.classes), $scheduleName, $isStudent (only students can import a COR). --}}
@extends('layout.portal-layout')
@use('App\Enums\Weekday')
@use('Illuminate\Support\Str')

@section('title', $scheduleName)
@section('heading', $scheduleName)

@php
    $primary = 'inline-flex min-h-11 items-center rounded-lg bg-primary px-4 text-sm font-semibold text-white transition hover:bg-primary-hover';
    $secondary = 'inline-flex min-h-11 items-center rounded-lg border border-border bg-surface px-4 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10';
@endphp

@section('content.portal')
    <div class="mx-auto flex max-w-6xl flex-col gap-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">{{ $scheduleName }}</h1>
                <p class="mt-1 text-sm text-text-muted">{{ $term?->label() ?? 'No current term yet' }}</p>
            </div>

            @if ($hasClasses)
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route("{$scheduleRoute}.create") }}" class="{{ $primary }}">Add a class</a>
                    @if ($isStudent)
                        <a href="{{ route('student.schedule.import') }}" class="{{ $secondary }}">Import from COR</a>
                    @endif
                </div>
            @endif
        </div>

        @if (! $hasClasses)
            <section aria-labelledby="empty-heading" class="rounded-2xl border border-border bg-surface p-5 sm:p-8">
                <h2 id="empty-heading" class="text-lg font-semibold">No classes yet</h2>
                <p class="mt-1 max-w-prose text-sm text-text-muted">
                    @if ($isStudent)
                        Import your COR to fill in your whole week at once, or add your classes one at a time.
                    @else
                        Add the classes you teach this term, one meeting at a time (a class that meets twice a week is added twice).
                    @endif
                    Each class with a room on the campus map gets a Go button for walking directions.
                </p>
                <div class="mt-5 flex flex-wrap gap-2">
                    @if ($isStudent)
                        <a href="{{ route('student.schedule.import') }}" class="{{ $primary }}">Import from COR</a>
                        <a href="{{ route("{$scheduleRoute}.create") }}" class="{{ $secondary }}">Add a class</a>
                    @else
                        <a href="{{ route("{$scheduleRoute}.create") }}" class="{{ $primary }}">Add your first class</a>
                    @endif
                </div>
            </section>
        @else
            <div class="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($days as $dayValue => $classes)
                    @php
                        $day = Weekday::from($dayValue);
                        $isToday = $day === $today;
                    @endphp
                    <section aria-labelledby="day-{{ $dayValue }}"
                        @class([
                            'overflow-hidden rounded-2xl border bg-surface',
                            'border-secondary shadow-[inset_0_4px_0_var(--color-secondary)] max-md:order-first' => $isToday,
                            'border-border' => ! $isToday,
                        ])>
                        <div class="flex items-baseline justify-between gap-3 px-4 pt-4 pb-3 sm:px-5">
                            <h2 id="day-{{ $dayValue }}" class="font-semibold">
                                {{ $day->label() }}
                                @if ($isToday)
                                    <span class="ml-1.5 rounded-md bg-secondary/20 px-1.5 py-0.5 text-xs font-semibold text-primary">Today</span>
                                @endif
                            </h2>
                            <p class="text-xs text-text-muted">
                                {{ $classes->isEmpty() ? 'No classes' : $classes->count().' '.Str::plural('class', $classes->count()) }}
                            </p>
                        </div>

                        @if ($classes->isNotEmpty())
                            <ul class="divide-y divide-border border-t border-border">
                                @foreach ($classes as $class)
                                    @include('portal.class-row', ['class' => $class, 'editable' => true, 'scheduleRoute' => $scheduleRoute])
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            </div>
        @endif
    </div>
@endsection
