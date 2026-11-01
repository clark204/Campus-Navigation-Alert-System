{{-- Portal home for students and faculty ($role comes from the route). Design only: the person, places and campus info
     below are placeholders until auth and the DB exist. Room links use real room ids from public/maps/entrances.geojson,
     so they open working directions. --}}
@extends('layout.portal-layout')
@use('App\Enums\UserRole')

@section('title', 'Home')

@php
    $isFaculty = $role === UserRole::Faculty;
    $college = 'College of Information Technology';

    // Also read by the portal header.
    $person = $isFaculty
        ? ['first_name' => 'Jonel', 'name' => 'Jonel Bacus', 'initials' => 'JB', 'detail' => 'Faculty', 'school_id' => 'F-0118']
        : ['first_name' => 'Angelica', 'name' => 'Angelica Dela Cruz', 'initials' => 'AD', 'detail' => 'BSIT', 'school_id' => '2026-00412'];

    // The app runs on UTC; greet by campus time.
    $hour = now('Asia/Manila')->hour;
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

    $directions = fn (string $roomId): string => route('navigate', ['to' => $roomId]);

    $collegeBuildings = [
        ['name' => 'Building 01', 'rooms' => [['id' => 'r6', 'name' => 'ComLab 1'], ['id' => 'r5', 'name' => 'ComLab 2'], ['id' => 'r4', 'name' => 'ComLab 3']]],
        ['name' => 'Building 04', 'rooms' => [['id' => 'r12', 'name' => 'ComLab 4'], ['id' => 'r13', 'name' => 'IT Faculty Office']]],
    ];

    // Faculty only.
    $teachingToday = [
        ['code' => 'IT 101', 'title' => 'Introduction to Computing', 'time' => '8:00 – 9:30 AM', 'room' => ['id' => 'r6', 'name' => 'ComLab 1', 'where' => 'Building 01']],
        ['code' => 'IT 213', 'title' => 'Data Structures', 'time' => '10:00 – 11:30 AM', 'room' => ['id' => 'r12', 'name' => 'ComLab 4', 'where' => 'Building 04']],
        ['code' => 'IT 324', 'title' => 'Web Development', 'time' => '1:00 – 2:30 PM', 'room' => ['id' => 'r4', 'name' => 'ComLab 3', 'where' => 'Building 01']],
    ];

    $recentPlaces = $isFaculty
        ? [
            ['id' => 'r12', 'name' => 'ComLab 4', 'where' => 'Building 04', 'when' => 'Today'],
            ['id' => 'r2', 'name' => 'QUAMC Office', 'where' => 'Building 01', 'when' => 'Yesterday'],
            ['id' => 'r20', 'name' => 'NEE 202', 'where' => 'NEE Building · 2nd floor', 'when' => '2 days ago'],
        ]
        : [
            ['id' => 'r17', 'name' => 'NEE 201', 'where' => 'NEE Building · 2nd floor', 'when' => 'Today'],
            ['id' => 'r4', 'name' => 'ComLab 3', 'where' => 'Building 01', 'when' => 'Yesterday'],
            ['id' => 'r3', 'name' => 'DSAS Office', 'where' => 'Building 01', 'when' => '3 days ago'],
        ];

    $savedPlaces = $isFaculty
        ? [
            ['id' => 'r13', 'name' => 'IT Faculty Office', 'where' => 'Building 04'],
            ['id' => 'r6', 'name' => 'ComLab 1', 'where' => 'Building 01'],
        ]
        : [
            ['id' => 'r13', 'name' => 'IT Faculty Office', 'where' => 'Building 04'],
            ['id' => 'r28', 'name' => 'NEE Bathroom', 'where' => 'NEE Building'],
        ];

    $announcements = [
        ['title' => 'Second-semester enrollment schedule', 'detail' => 'Check the registrar for your college\'s enrollment day.', 'date' => 'Oct 30'],
        ['title' => 'Computer labs closed for maintenance', 'detail' => 'ComLab 1 to 3 reopen after the weekend.', 'date' => 'Oct 27'],
    ];

    $offices = [
        ['id' => 'r3', 'name' => 'DSAS Office', 'where' => 'Building 01', 'hours' => 'Mon–Fri · 8:00 AM – 5:00 PM'],
        ['id' => 'r2', 'name' => 'QUAMC Office', 'where' => 'Building 01', 'hours' => 'Mon–Fri · 8:00 AM – 5:00 PM'],
        ['id' => 'r14', 'name' => 'Alumni Office', 'where' => 'Building 04', 'hours' => 'Mon–Fri · 8:00 AM – 5:00 PM'],
    ];

    // No numbers until the school confirms them: a wrong emergency number is worse than none.
    $emergencyContacts = ['Campus security', 'School clinic', 'Guidance office'];
@endphp

@section('content.portal')
    <div class="mx-auto flex max-w-6xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">{{ $greeting }}, {{ $person['first_name'] }}</h1>
            <p class="mt-1 text-sm text-text-muted">
                {{ $person['detail'] }} · {{ $college }} · <span class="tabular-nums">{{ $person['school_id'] }}</span>
            </p>
        </div>

        <section aria-labelledby="search-heading" class="rounded-2xl bg-primary p-5 text-white sm:p-6">
            <h2 id="search-heading" class="text-lg font-semibold">Where do you need to go?</h2>
            <p class="mt-0.5 text-sm text-white/70">Search a room, lab or office to get walking directions.</p>

            {{-- Without JS (or before the map data loads) submitting opens the full room picker on the map. --}}
            <form role="search" method="GET" action="{{ route('navigate') }}" data-place-search
                data-maps-url="{{ asset('maps') }}" data-navigate-url="{{ route('navigate') }}" class="mt-4">
                <label for="place-search" class="sr-only">Room, lab or office</label>
                <div class="relative">
                    <svg class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24"
                        fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zm9 2-4-4" />
                    </svg>
                    <input id="place-search" type="search" data-place-search-input autocomplete="off" enterkeyhint="go"
                        placeholder="Try ComLab 3 or NEE 201"
                        class="w-full rounded-xl border-0 bg-white py-3.5 pr-4 pl-12 text-base text-text-primary placeholder:text-text-muted/80 focus:ring-4 focus:ring-secondary/50 focus:outline-none">
                </div>
                <p data-place-search-status aria-live="polite" class="mt-3 text-sm text-white/80 empty:hidden"></p>
                <ul data-place-search-results class="mt-2 divide-y divide-border overflow-hidden rounded-xl bg-white text-text-primary empty:hidden"></ul>
            </form>
        </section>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
            <div class="flex flex-col gap-6">
                @if ($isFaculty)
                    <section aria-labelledby="teaching-heading" class="rounded-2xl border border-border bg-surface">
                        <div class="flex items-baseline justify-between gap-4 px-5 pt-4 pb-3">
                            <h2 id="teaching-heading" class="font-semibold">Teaching today</h2>
                            <p class="text-xs text-text-muted">{{ count($teachingToday) }} classes</p>
                        </div>
                        <ul class="divide-y divide-border border-t border-border">
                            @foreach ($teachingToday as $class)
                                <li class="flex items-center gap-4 px-5 py-3.5">
                                    <p class="w-24 shrink-0 text-xs font-medium text-text-muted tabular-nums max-sm:hidden">{{ $class['time'] }}</p>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold">{{ $class['code'] }} · {{ $class['title'] }}</p>
                                        <p class="truncate text-xs text-text-muted">
                                            <span class="tabular-nums sm:hidden">{{ $class['time'] }} · </span>{{ $class['room']['name'] }} · {{ $class['room']['where'] }}
                                        </p>
                                    </div>
                                    <a href="{{ $directions($class['room']['id']) }}" aria-label="Directions to {{ $class['room']['name'] }}"
                                        class="shrink-0 text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                                        Directions
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                <section aria-labelledby="college-heading" class="rounded-2xl border border-border bg-surface p-5">
                    <h2 id="college-heading" class="font-semibold">My college</h2>
                    <p class="mt-0.5 text-sm text-text-muted">{{ $college }}</p>

                    <div class="mt-4 flex flex-col gap-4">
                        @foreach ($collegeBuildings as $building)
                            <div>
                                <h3 class="text-xs font-medium text-text-muted">{{ $building['name'] }}</h3>
                                <ul class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($building['rooms'] as $room)
                                        <li>
                                            <a href="{{ $directions($room['id']) }}"
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-bg-light px-3 py-2 text-sm font-medium transition hover:border-secondary hover:bg-secondary/10">
                                                <svg class="size-4 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    <path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z" />
                                                </svg>
                                                {{ $room['name'] }}
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section aria-label="Recent and saved places" class="grid rounded-2xl border border-border bg-surface md:grid-cols-2 md:divide-x divide-border max-md:divide-y">
                    @foreach ([['heading' => 'Recent', 'places' => $recentPlaces], ['heading' => 'Saved', 'places' => $savedPlaces]] as $list)
                        <div class="p-5">
                            <h2 class="font-semibold">{{ $list['heading'] }}</h2>
                            <ul class="mt-3 flex flex-col gap-1">
                                @foreach ($list['places'] as $place)
                                    <li>
                                        <a href="{{ $directions($place['id']) }}"
                                            class="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2 transition hover:bg-bg-light">
                                            <span @class([
                                                'flex size-9 shrink-0 items-center justify-center rounded-full',
                                                'bg-primary/10 text-primary' => $list['heading'] === 'Recent',
                                                'bg-secondary/20 text-secondary-hover' => $list['heading'] === 'Saved',
                                            ])>
                                                <svg class="size-4.5" viewBox="0 0 24 24" fill="{{ $list['heading'] === 'Saved' ? 'currentColor' : 'none' }}"
                                                    stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                                    @if ($list['heading'] === 'Saved')
                                                        <path d="m12 4 2.4 5 5.4.7-4 3.7 1 5.4-4.8-2.6-4.8 2.6 1-5.4-4-3.7 5.4-.7z" />
                                                    @else
                                                        <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zm0-13v4l3 2" />
                                                    @endif
                                                </svg>
                                            </span>
                                            <span class="min-w-0 flex-1">
                                                <span class="block truncate text-sm font-semibold">{{ $place['name'] }}</span>
                                                <span class="block truncate text-xs text-text-muted">{{ $place['where'] }}</span>
                                            </span>
                                            @isset($place['when'])
                                                <span class="shrink-0 text-xs text-text-muted">{{ $place['when'] }}</span>
                                            @endisset
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </section>
            </div>

            <section aria-labelledby="info-heading" class="flex flex-col rounded-2xl border border-border bg-surface">
                <h2 id="info-heading" class="px-5 pt-4 pb-3 font-semibold">Campus info</h2>

                <div class="border-t border-border px-5 py-4">
                    <h3 class="text-xs font-medium text-text-muted">Announcements</h3>
                    <ul class="mt-3 flex flex-col gap-3">
                        @foreach ($announcements as $announcement)
                            <li class="flex gap-3">
                                <span class="mt-1.5 size-2 shrink-0 rounded-full bg-secondary" aria-hidden="true"></span>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold">{{ $announcement['title'] }}</p>
                                    <p class="mt-0.5 text-sm text-text-muted">{{ $announcement['detail'] }}</p>
                                    <p class="mt-1 text-xs text-text-muted">{{ $announcement['date'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="border-t border-border px-5 py-4">
                    <h3 class="text-xs font-medium text-text-muted">Offices</h3>
                    <ul class="mt-2 divide-y divide-border">
                        @foreach ($offices as $office)
                            <li class="flex items-center justify-between gap-3 py-2.5">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold">{{ $office['name'] }}</p>
                                    <p class="text-xs text-text-muted">{{ $office['where'] }} · {{ $office['hours'] }}</p>
                                </div>
                                <a href="{{ $directions($office['id']) }}" aria-label="Directions to {{ $office['name'] }}"
                                    class="shrink-0 text-sm font-semibold text-primary underline decoration-secondary/60 underline-offset-4 transition hover:decoration-primary">
                                    Directions
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="border-t border-border px-5 py-4">
                    <h3 class="text-xs font-medium text-text-muted">Emergency contacts</h3>
                    <dl class="mt-2 divide-y divide-border">
                        @foreach ($emergencyContacts as $contact)
                            <div class="flex items-center justify-between gap-3 py-2.5 text-sm">
                                <dt class="font-semibold">{{ $contact }}</dt>
                                <dd class="text-text-muted italic">Number to be added</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </section>
        </div>
    </div>
@endsection
