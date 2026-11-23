@extends('layout.admin-layout')

@section('title', 'Buildings')
@section('heading', 'Buildings')

@section('content.admin')
    <div class="mx-auto flex max-w-5xl flex-col gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Buildings</h1>
                <p class="mt-1 text-sm text-text-muted">Names, direction names and photos. Shapes, floors and types come from the map file.</p>
            </div>
            <p class="text-sm tabular-nums text-text-muted">{{ $total }} places, {{ $withPhotos }} with photos</p>
        </header>

        <section aria-labelledby="buildings-heading" class="overflow-hidden rounded-2xl border border-border bg-surface"
            data-table-search-root data-page-size="10">
            <h2 id="buildings-heading" class="sr-only">All buildings</h2>

            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3">
                <label for="building-search" class="sr-only">Search buildings</label>
                <input id="building-search" type="search" data-table-search placeholder="Search by name, code or direction name"
                    aria-controls="buildings-list" autocomplete="off" class="login-input w-full sm:w-80">
                <p data-summary role="status" aria-live="polite" class="text-sm tabular-nums text-text-muted"></p>
            </div>

            <ul id="buildings-list" role="list" data-still-list class="divide-y divide-border">
                <li class="hidden items-center gap-x-5 bg-bg-light px-5 py-2.5 text-xs font-medium text-text-muted sm:grid sm:grid-cols-[6rem_4.5rem_minmax(0,1fr)_4.5rem_8.5rem_auto]"
                    aria-hidden="true">
                    <span>Photo</span>
                    <span>Code</span>
                    <span>Name</span>
                    <span class="text-right">Floors</span>
                    <span>Photos</span>
                    <span></span>
                </li>

                @forelse ($buildings as $building)
                    @php($cover = $building->photos->first())
                    <li data-row data-search="{{ strtolower($building->name.' '.$building->code.' '.$building->landmark_name.' '.$building->type->label()) }}"
                        class="grid grid-cols-[6rem_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-5 py-3 transition-colors hover:bg-bg-light/60 sm:grid-cols-[6rem_4.5rem_minmax(0,1fr)_4.5rem_8.5rem_auto] sm:gap-x-5">
                        <div>
                            @if ($cover)
                                {{-- Hovering the cover shows a larger preview (still-preview.js). --}}
                                <img src="{{ $cover->thumbUrl() }}" data-still="{{ $cover->previewUrl() }}" alt="" loading="lazy" width="96" height="72"
                                    class="aspect-4/3 w-24 rounded-lg bg-bg-light object-cover ring-1 ring-border">
                            @else
                                <span class="flex aspect-4/3 w-24 items-center justify-center rounded-lg border border-dashed border-border text-[11px] text-text-muted">No photos</span>
                            @endif
                        </div>

                        <div class="min-w-0 sm:hidden">
                            <p class="truncate font-semibold">{{ $building->name }}</p>
                            <p class="mt-0.5 truncate text-xs tabular-nums text-text-muted">{{ $building->code }} · {{ $building->photos->count() }} of {{ $maxPhotos }} photos</p>
                        </div>

                        <span class="hidden font-mono text-xs font-medium text-text-muted sm:block">
                            <span class="rounded-md bg-bg-light px-1.5 py-0.5">{{ $building->code }}</span>
                        </span>

                        <div class="hidden min-w-0 sm:block">
                            <p class="truncate font-semibold">{{ $building->name }}</p>
                            <p class="truncate text-xs text-text-muted">
                                {{ $building->type->label() }} · {{ $building->landmark_name ? 'Directions say “'.$building->landmark_name.'”' : 'No direction name yet' }}
                            </p>
                        </div>

                        <p class="hidden text-right tabular-nums text-text-muted sm:block">{{ $building->floors ?? '—' }}</p>

                        <div class="hidden sm:block">
                            @include('admin.photo-meter', ['count' => $building->photos->count(), 'max' => $maxPhotos])
                        </div>

                        <div class="flex items-center gap-2 justify-self-end">
                            {{-- Printable QR code that starts directions here (docs/navigation.md "QR codes"). --}}
                            <a href="{{ route('admin.buildings.qr', $building) }}"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-border px-3 py-1.5 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 14h2M14 18h2m2 0h2v2M18 16v2" /></svg>
                                QR code<span class="sr-only"> for {{ $building->name }}</span>
                            </a>
                            <a href="{{ route('admin.buildings.edit', $building) }}"
                                class="rounded-lg border border-border px-3 py-1.5 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10">
                                Edit<span class="sr-only"> {{ $building->name }}</span>
                            </a>
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-8 text-center text-sm text-text-muted">No buildings on the map yet. Import the map file first.</li>
                @endforelse

                <li data-no-match hidden class="px-5 py-8 text-center text-sm text-text-muted">No places match that search. Try a name, a code or a direction name.</li>
            </ul>

            <nav aria-label="Pages" data-pager hidden class="flex flex-wrap items-center justify-end gap-2 border-t border-border px-5 py-3"></nav>
        </section>
    </div>
@endsection
