{{-- Portal "Saved places" (Portal\SavedPlacesController): every saved room with Remove, and the recent places with
     Clear. Places are saved with "Save" on the /navigate directions card; recent ones are added by its Start. --}}
@extends('layout.portal-layout')

@section('title', 'Saved places')
@section('heading', 'Saved places')

@section('content.portal')
    <div class="mx-auto flex max-w-3xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Saved places</h1>
            <p class="mt-1 text-sm text-text-muted">Tap a place for walking directions.</p>
        </div>

        <section aria-labelledby="saved-heading" class="rounded-2xl border border-border bg-surface p-5">
            <h2 id="saved-heading" class="font-semibold">Saved <span class="font-normal text-text-muted tabular-nums">({{ $savedPlaces->count() }})</span></h2>
            @if ($savedPlaces->isEmpty())
                {{-- How saving works, shown as the steps it takes. --}}
                <p class="mt-2 text-sm text-text-muted">Nothing saved yet. To save a place:</p>
                <ol class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
                    @foreach (['Open the campus map.', 'Choose the room as your destination.', 'Tap Save next to “Where to?”.'] as $step)
                        <li class="flex items-start gap-2.5 rounded-xl bg-bg-light p-3">
                            <span class="grid size-6 shrink-0 place-items-center rounded-full bg-primary text-xs font-bold text-white">{{ $loop->iteration }}</span>
                            {{ $step }}
                        </li>
                    @endforeach
                </ol>
                <a href="{{ route('navigate') }}"
                    class="mt-4 inline-flex min-h-11 items-center rounded-lg bg-primary px-4 text-sm font-semibold text-white transition hover:bg-primary-hover">
                    Open the campus map
                </a>
            @else
                <ul class="mt-3 flex flex-col gap-1">
                    @foreach ($savedPlaces as $room)
                        @include('portal.place-row', ['room' => $room, 'kind' => 'saved', 'removable' => true])
                    @endforeach
                </ul>
            @endif
        </section>

        <section id="recent" aria-labelledby="recent-heading" class="scroll-mt-20 rounded-2xl border border-border bg-surface p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="recent-heading" class="font-semibold">Recent</h2>
                @if ($recentPlaces->isNotEmpty())
                    <form method="POST" action="{{ route('places.recent.clear') }}" data-confirm="Clear your recent places? Your saved places stay.">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="inline-flex min-h-11 items-center rounded-lg border border-border px-4 text-sm font-semibold text-text-primary transition hover:border-red-300 hover:bg-red-50 hover:text-red-700">
                            Clear recent
                        </button>
                    </form>
                @endif
            </div>
            @if ($recentPlaces->isEmpty())
                <p class="mt-2 text-sm text-text-muted">Places you start directions to show up here. Only your last {{ \App\Services\RecentPlaces::KEEP }} are kept.</p>
            @else
                <ul class="mt-3 flex flex-col gap-1">
                    @foreach ($recentPlaces as $room)
                        @include('portal.place-row', ['room' => $room, 'kind' => 'recent'])
                    @endforeach
                </ul>
                <p class="mt-3 text-xs text-text-muted">Only your last {{ \App\Services\RecentPlaces::KEEP }} places are kept.</p>
            @endif
        </section>
    </div>
@endsection
