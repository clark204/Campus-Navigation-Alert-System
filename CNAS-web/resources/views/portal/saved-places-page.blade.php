{{-- Portal "Saved places" (Portal\SavedPlacesController): every saved room with Remove, and the recent places with
     Clear. Places are saved with "Save" on the /navigate directions card; recent ones are added by its Start. --}}
@extends('layout.portal-layout')

@section('title', 'Saved places')
@section('heading', 'Saved places')

@section('content.portal')
    <div class="mx-auto flex max-w-3xl flex-col gap-6 sm:gap-8">
        <div class="border-b border-border pb-6">
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Saved places</h1>
            <p class="mt-2 text-sm leading-relaxed text-text-muted">Tap a place for walking directions.</p>
        </div>

        <section aria-labelledby="saved-heading" class="rounded-2xl border border-border bg-surface p-5 shadow-sm sm:p-6">
            <h2 id="saved-heading" class="flex items-center gap-3 text-lg font-semibold text-primary">Saved <span class="inline-flex min-h-7 min-w-7 items-center justify-center rounded-full bg-secondary/15 px-2 text-xs font-semibold text-primary tabular-nums">{{ $savedPlaces->count() }}</span></h2>
            @if ($savedPlaces->isEmpty())
                {{-- How saving works, shown as the steps it takes. --}}
                <p class="mt-3 text-sm leading-relaxed text-text-muted">Nothing saved yet. To save a place:</p>
                <ol class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    @foreach (['Open the campus map.', 'Choose the room as your destination.', 'Tap Save next to “Where to?”.'] as $step)
                        <li class="flex items-start gap-3 rounded-xl border border-border/60 bg-bg-light p-4 leading-relaxed sm:flex-col">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full bg-primary text-xs font-bold text-white">{{ $loop->iteration }}</span>
                            {{ $step }}
                        </li>
                    @endforeach
                </ol>
                <a href="{{ route('navigate') }}"
                    class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-primary px-5 text-sm font-semibold text-white transition hover:bg-primary-hover max-sm:w-full">
                    Open the campus map
                </a>
            @else
                <ul class="mt-4 flex flex-col gap-1 border-t border-border pt-2">
                    @foreach ($savedPlaces as $room)
                        @include('portal.place-row', ['room' => $room, 'kind' => 'saved', 'removable' => true])
                    @endforeach
                </ul>
            @endif
        </section>

        <section id="recent" aria-labelledby="recent-heading" class="scroll-mt-20 rounded-2xl border border-border bg-surface p-5 shadow-sm sm:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 id="recent-heading" class="text-lg font-semibold text-primary">Recent</h2>
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
                <p class="mt-4 rounded-xl border border-dashed border-border bg-bg-light p-4 text-sm leading-relaxed text-text-muted">Places you start directions to show up here. Only your last {{ \App\Services\RecentPlaces::KEEP }} are kept.</p>
            @else
                <ul class="mt-4 flex flex-col gap-1 border-t border-border pt-2">
                    @foreach ($recentPlaces as $room)
                        @include('portal.place-row', ['room' => $room, 'kind' => 'recent'])
                    @endforeach
                </ul>
                <p class="mt-4 border-t border-border pt-4 text-xs leading-relaxed text-text-muted">Only your last {{ \App\Services\RecentPlaces::KEEP }} places are kept.</p>
            @endif
        </section>
    </div>
@endsection
