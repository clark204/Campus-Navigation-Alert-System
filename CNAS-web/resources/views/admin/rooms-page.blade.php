@extends('layout.admin-layout')
@use('Illuminate\Support\Number')

@section('title', 'Rooms')
@section('heading', 'Rooms')

@section('content.admin')
    <div class="mx-auto flex max-w-5xl flex-col gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Rooms</h1>
                <p class="mt-1 text-sm text-text-muted">Room names and photos. Codes, buildings, floors, types and positions come from the map file.</p>
            </div>
            <p class="text-sm tabular-nums text-text-muted">{{ $total }} rooms in {{ $buildings->count() }} buildings</p>
        </header>

        <section aria-labelledby="rooms-heading" class="overflow-hidden rounded-2xl border border-border bg-surface"
            data-table-search-root data-page-size="10">
            <h2 id="rooms-heading" class="sr-only">All rooms</h2>

            <div class="flex flex-col gap-3 border-b border-border px-5 py-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label for="room-search" class="sr-only">Search rooms</label>
                    <input id="room-search" type="search" data-table-search placeholder="Search by room, code or building"
                        aria-controls="rooms-list" autocomplete="off" class="login-input w-full sm:w-80">
                    <p data-summary role="status" aria-live="polite" class="text-sm tabular-nums text-text-muted"></p>
                </div>
            </div>

            <ul id="rooms-list" role="list" class="divide-y divide-border">
                <li class="hidden items-center gap-x-5 bg-bg-light px-5 py-2.5 text-xs font-medium text-text-muted sm:grid sm:grid-cols-[4.5rem_minmax(0,1.3fr)_minmax(0,1fr)_4.5rem_8.5rem_auto]" aria-hidden="true">
                    <span>Code</span>
                    <span>Room</span>
                    <span>Building</span>
                    <span>Floor</span>
                    <span>Photos</span>
                    <span></span>
                </li>

                @forelse ($rooms as $room)
                    <li data-row data-search="{{ strtolower($room->name.' '.$room->code.' '.$room->building->name.' '.$room->type->label()) }}"
                        class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-5 py-3 transition-colors hover:bg-bg-light/60 sm:grid-cols-[4.5rem_minmax(0,1.3fr)_minmax(0,1fr)_4.5rem_8.5rem_auto] sm:gap-x-5">
                        <div class="min-w-0 sm:hidden">
                            <p class="truncate font-semibold">{{ $room->name }}</p>
                            <p class="mt-0.5 truncate text-xs tabular-nums text-text-muted">
                                {{ $room->code }} · {{ $room->building->name }} · {{ Number::ordinal($room->floor) }} floor · {{ $room->photos->count() }} of {{ $maxPhotos }} photos
                            </p>
                        </div>

                        <span class="hidden font-mono text-xs font-medium text-text-muted sm:block">
                            <span class="rounded-md bg-bg-light px-1.5 py-0.5">{{ $room->code }}</span>
                        </span>

                        <div class="hidden min-w-0 sm:block">
                            <p class="truncate font-semibold">{{ $room->name }}</p>
                            <p class="truncate text-xs text-text-muted">{{ $room->type->label() }}</p>
                        </div>

                        <p class="hidden truncate text-sm text-text-muted sm:block">{{ $room->building->name }}</p>

                        <p class="hidden tabular-nums sm:block">{{ Number::ordinal($room->floor) }}</p>

                        <div class="hidden sm:block">
                            @include('admin.photo-meter', ['count' => $room->photos->count(), 'max' => $maxPhotos])
                        </div>

                        <a href="{{ route('admin.rooms.edit', $room) }}"
                            class="justify-self-end rounded-lg border border-border px-3 py-1.5 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">
                            Edit<span class="sr-only"> {{ $room->name }}</span>
                        </a>
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-sm text-text-muted">
                        {{ $selectedBuilding !== '' ? 'No rooms in this building.' : 'No rooms on the map yet. Import the map file first.' }}
                    </li>
                @endforelse

                <li data-no-match hidden class="px-5 py-8 text-center text-sm text-text-muted">No rooms match that search. Try a room name, a code or a building.</li>
            </ul>

            <nav aria-label="Pages" data-pager hidden class="flex flex-wrap items-center justify-end gap-2 border-t border-border px-5 py-3"></nav>
        </section>
    </div>
@endsection
