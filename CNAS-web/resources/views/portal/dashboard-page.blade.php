{{-- Portal home for students, faculty and staff ($role comes from the route: staff use the faculty portal). Everything
     here is real data:
     - Classes: students (StudentDashboardController, their COR or hand-added schedule) and faculty
       (FacultyDashboardController, "My classes" added by hand) get $nextClass, $todayClasses and $hasClasses. Staff
       don't teach ($teaches false): no class card at all.
     - From view composers in AppServiceProvider: $savedPlaces, $recentPlaces, $offices (office rooms on the map),
       $collegeRooms (the map's IT rooms, for IT college users) and $emergencyContacts. --}}
@extends('layout.portal-layout')
@use('App\Enums\UserRole')

@section('title', 'Home')
@section('heading', 'Home')

@php
    $user = request()->user();
    $college = $user->college?->name;
    // Students and faculty have classes; staff (faculty portal, Staff role) don't.
    $teaches ??= $role === UserRole::Student;
    $scheduleRoute = $role === UserRole::Student ? 'student.schedule' : 'faculty.classes';

    // The app runs on UTC; greet by campus time.
    $hour = now('Asia/Manila')->hour;
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

    $directions = fn (string $roomCode): string => route('navigate', ['to' => $roomCode]);
@endphp

@section('content.portal')
    <div class="mx-auto flex max-w-6xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">{{ $greeting }}, {{ $user->first_name }}</h1>
            <p class="mt-1 text-sm text-text-muted tabular-nums">{{ implode(' · ', array_filter([$user->role->label(), $college, $user->school_id])) }}</p>
        </div>

        {{-- Only while alerts are off on this device (turned on, blocked or unsupported: managed in Settings → Alerts). --}}
        @include('partials.push-alerts', ['variant' => 'card', 'hideStates' => 'on denied unsupported'])

        {{-- Faculty and staff the admin trusts to send emergency alerts (Admin > Accounts). --}}
        @if (! $user->isAdmin() && $user->canSendAlerts())
            <section aria-labelledby="send-alerts-heading" class="flex flex-wrap items-center gap-4 rounded-2xl border border-alarm/30 bg-surface p-5 shadow-[inset_4px_0_0_var(--color-alarm)]">
                <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-alarm text-white" aria-hidden="true">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3 2 20h20zM12 10v4m0 3h.01" />
                    </svg>
                </span>
                <div class="min-w-0 flex-1 basis-56">
                    <h2 id="send-alerts-heading" class="font-semibold">You can send emergency alerts</h2>
                    <p class="mt-0.5 text-sm text-text-muted">If something happens on campus, warn everyone from here. It goes out straight away.</p>
                </div>
                <a href="{{ route('faculty.alerts.index') }}"
                    class="inline-flex min-h-11 items-center rounded-xl bg-alarm px-4 text-sm font-semibold text-white transition hover:bg-alarm-deep max-sm:w-full max-sm:justify-center">
                    Emergency alerts
                </a>
            </section>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <div class="flex flex-col gap-6">
                @if ($teaches)
                    <section aria-labelledby="next-class-heading" class="overflow-hidden rounded-2xl border border-border bg-surface">
                        <div class="flex items-baseline justify-between gap-4 px-4 pt-4 pb-3 sm:px-5">
                            <h2 id="next-class-heading" class="font-semibold">Next class</h2>
                            <a href="{{ route("{$scheduleRoute}.index") }}"
                                class="text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                                {{ $role === UserRole::Student ? 'My schedule' : 'My classes' }}
                            </a>
                        </div>

                        @if ($nextClass)
                            <div class="border-t border-border shadow-[inset_4px_0_0_var(--color-secondary)]">
                                <p class="px-4 pt-3 text-xs font-semibold text-primary sm:px-5">{{ $nextClass['when'] }}</p>
                                <ul>
                                    @include('portal.class-row', ['class' => $nextClass['class']])
                                </ul>
                            </div>

                            <div class="border-t border-border">
                                <h3 class="px-4 pt-3 text-xs font-medium text-text-muted sm:px-5">Today</h3>
                                @if ($todayClasses->isEmpty())
                                    <p class="px-4 pt-1 pb-4 text-sm text-text-muted sm:px-5">Nothing scheduled today.</p>
                                @else
                                    <ul class="divide-y divide-border">
                                        @foreach ($todayClasses as $class)
                                            @include('portal.class-row', ['class' => $class])
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        @else
                            <div class="border-t border-border px-4 py-4 sm:px-5">
                                <p class="text-sm text-text-muted">
                                    @if ($hasClasses)
                                        Nothing coming up this week.
                                    @elseif ($role === UserRole::Student)
                                        Add your schedule to see your next class and get directions to its room.
                                    @else
                                        Add the classes you teach to see your next one and get directions to its room.
                                    @endif
                                </p>
                                @unless ($hasClasses)
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @if ($role === UserRole::Student)
                                            <a href="{{ route('student.schedule.import') }}"
                                                class="inline-flex min-h-11 items-center rounded-lg bg-primary px-4 text-sm font-semibold text-white transition hover:bg-primary-hover">
                                                Import from COR
                                            </a>
                                        @endif
                                        <a href="{{ route("{$scheduleRoute}.create") }}" @class([
                                            'inline-flex min-h-11 items-center rounded-lg px-4 text-sm font-semibold transition',
                                            'border border-border bg-surface text-primary hover:border-secondary hover:bg-secondary/10' => $role === UserRole::Student,
                                            'bg-primary text-white hover:bg-primary-hover' => $role !== UserRole::Student,
                                        ])>
                                            Add a class
                                        </a>
                                    </div>
                                @endunless
                            </div>
                        @endif
                    </section>
                @endif

                @if ($collegeRooms->isNotEmpty())
                    <section aria-labelledby="college-heading" class="rounded-2xl border border-border bg-surface p-5">
                        <h2 id="college-heading" class="font-semibold">My college</h2>
                        <p class="mt-0.5 text-sm text-text-muted">{{ $college }}</p>

                        <div class="mt-4 flex flex-col gap-4">
                            @foreach ($collegeRooms as $building => $rooms)
                                <div>
                                    <h3 class="text-xs font-medium text-text-muted">{{ $building }}</h3>
                                    <ul class="mt-2 flex flex-wrap gap-2">
                                        @foreach ($rooms as $room)
                                            <li>
                                                <a href="{{ $directions($room->code) }}"
                                                    class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-bg-light px-3 py-2 text-sm font-medium transition hover:border-secondary hover:bg-secondary/10">
                                                    <svg class="size-4 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                                        stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                        <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
                                                    </svg>
                                                    {{ $room->name }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endif

                @php($savedPlacesUrl = route($role->value.'.saved'))
                <section aria-label="Saved and recent places" class="grid rounded-2xl border border-border bg-surface md:grid-cols-2 md:divide-x divide-border max-md:divide-y">
                    <div class="p-5">
                        <div class="flex items-baseline justify-between gap-4">
                            <h2 class="font-semibold">Saved</h2>
                            @if ($savedPlaces->isNotEmpty())
                                <a href="{{ $savedPlacesUrl }}" class="text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">See all</a>
                            @endif
                        </div>
                        @if ($savedPlaces->isEmpty())
                            <p class="mt-2 text-sm text-text-muted">No saved places yet. Open a room on the campus map and tap Save to keep it here.</p>
                            <a href="{{ route('navigate') }}"
                                class="mt-3 inline-flex min-h-11 items-center gap-2 rounded-lg border border-border px-4 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">
                                <svg class="size-4.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                    stroke-linejoin="round" aria-hidden="true">
                                    <path d="M9 4 3 6.5V20l6-2.5 6 2.5 6-2.5V4l-6 2.5zm0 0v13.5m6-11V20" />
                                </svg>
                                Open the campus map
                            </a>
                        @else
                            <ul class="mt-3 flex flex-col gap-1">
                                @foreach ($savedPlaces as $room)
                                    @include('portal.place-row', ['room' => $room, 'kind' => 'saved'])
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    <div class="p-5">
                        <div class="flex items-baseline justify-between gap-4">
                            <h2 class="font-semibold">Recent</h2>
                            @if ($recentPlaces->isNotEmpty())
                                <a href="{{ $savedPlacesUrl }}#recent" class="text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">See all</a>
                            @endif
                        </div>
                        @if ($recentPlaces->isEmpty())
                            <p class="mt-2 text-sm text-text-muted">Places you start directions to show up here, so you can go back with one tap.</p>
                        @else
                            <ul class="mt-3 flex flex-col gap-1">
                                @foreach ($recentPlaces as $room)
                                    @include('portal.place-row', ['room' => $room, 'kind' => 'recent'])
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>
            </div>

            <section aria-labelledby="info-heading" class="flex flex-col rounded-2xl border border-border bg-surface">
                <h2 id="info-heading" class="px-5 pt-4 pb-3 font-semibold">Campus info</h2>

                {{-- $offices: the office rooms on the campus map (no opening hours: the map doesn't have them). --}}
                <div class="border-t border-border px-5 py-4">
                    <h3 class="text-xs font-medium text-text-muted">Offices</h3>
                    @if ($offices->isEmpty())
                        <p class="mt-2 text-sm text-text-muted">No offices are on the campus map yet.</p>
                    @else
                        <ul class="mt-2 divide-y divide-border">
                            @foreach ($offices as $office)
                                <li class="flex items-center justify-between gap-3 py-2.5">
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold">{{ $office->name }}</p>
                                        <p class="text-xs text-text-muted">{{ $office->locationLabel() }}</p>
                                    </div>
                                    <a href="{{ $directions($office->code) }}" aria-label="Directions to {{ $office->name }}"
                                        class="shrink-0 text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                                        Directions
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- $emergencyContacts: the visible ones with a number (AppServiceProvider composer). None: no block. --}}
                @if ($emergencyContacts->isNotEmpty())
                    <div class="border-t border-border px-5 py-4">
                        <h3 class="text-xs font-medium text-text-muted">Emergency contacts</h3>
                        <dl class="mt-2 divide-y divide-border">
                            @foreach ($emergencyContacts as $contact)
                                <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
                                    <dt class="min-w-0">
                                        <span class="block font-semibold">{{ $contact->name }}</span>
                                        @if ($contact->note)
                                            <span class="block text-xs text-text-muted">{{ $contact->note }}</span>
                                        @endif
                                    </dt>
                                    <dd class="shrink-0">
                                        <a href="{{ $contact->telHref() }}" aria-label="Call {{ $contact->name }}, {{ $contact->phone }}"
                                            class="inline-flex min-h-11 items-center font-semibold text-primary tabular-nums underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                                            {{ $contact->phone }}
                                        </a>
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection
