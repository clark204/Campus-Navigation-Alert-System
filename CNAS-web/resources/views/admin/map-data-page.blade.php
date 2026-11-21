@extends('layout.admin-layout')

@section('title', 'Map data')
@section('heading', 'Map data')

@section('content.admin')
    <div class="mx-auto flex max-w-5xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Map data</h1>
            <p class="mt-1 text-sm text-text-muted">Shapes, floors and positions are edited in the other app. Names and photos are edited on the Buildings and Rooms pages. Bring the file back as campus.geojson.</p>
        </div>

        @if (session('importReport'))
            @php($report = session('importReport'))
            <section aria-labelledby="import-result-heading" class="rounded-2xl border border-border bg-surface p-5">
                <h2 id="import-result-heading" class="font-semibold">What the import changed</h2>
                <div class="mt-4 grid gap-6 md:grid-cols-2">
                    @foreach ([
                        'added' => 'Added',
                        'updated' => 'Updated',
                        'matched' => 'Matched by name and redrawn (kept their code)',
                        'duplicates' => 'Possible duplicates: added as new, please check',
                        'kept' => 'Kept, because they are not in the file',
                    ] as $key => $heading)
                        @if (count($report[$key]) > 0)
                            <div>
                                <h3 class="text-sm font-semibold">{{ $heading }} <span class="font-normal tabular-nums text-text-muted">({{ count($report[$key]) }})</span></h3>
                                <ul class="mt-2 divide-y divide-border">
                                    @foreach ($report[$key] as $item)
                                        <li class="flex items-baseline justify-between gap-3 py-1.5 text-sm">
                                            <span class="truncate">{{ $item['name'] }}</span>
                                            <span class="shrink-0 text-xs tabular-nums text-text-muted">{{ $item['kind'] }} · {{ $item['code'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endforeach
                </div>
            </section>
        @endif

        <div class="grid gap-6 md:grid-cols-2">
            <section aria-labelledby="export-heading" class="flex flex-col rounded-2xl border border-border bg-surface p-5">
                <h2 id="export-heading" class="font-semibold">Export the map</h2>
                <p class="mt-2 text-sm text-text-muted">
                    The whole map in one file: buildings, paths, rooms, stairs and landmark names, as they are on the map now.
                    Edit it in the other app.
                </p>
                <a href="{{ route('admin.map.export') }}"
                    class="mt-5 inline-flex w-fit items-center rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">
                    Download campus.geojson
                </a>
            </section>

            <section aria-labelledby="import-heading" class="flex flex-col rounded-2xl border border-border bg-surface p-5">
                <h2 id="import-heading" class="font-semibold">Import a map</h2>
                <p class="mt-2 text-sm text-text-muted">
                    Codes already on the map keep their names and landmark names. Their shapes and other details come from the file.
                    New codes take the file's names. Nothing is deleted: anything missing from the file is kept and listed after the import.
                </p>

                <form method="POST" action="{{ route('admin.map.import') }}" enctype="multipart/form-data" class="mt-5 grid gap-4" novalidate data-submit-once>
                    @csrf

                    <div>
                        <label for="file" class="mb-1.5 block text-xs font-semibold text-text-primary">campus.geojson</label>
                        <input id="file" name="file" type="file" accept=".geojson,application/geo+json" required
                            class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-bg-light file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary"
                            @error('file') aria-invalid="true" aria-describedby="file-error" @enderror>
                        @error('file') <p id="file-error" role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <button type="submit"
                        class="w-fit rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">
                        Import the map
                    </button>
                </form>
            </section>
        </div>
    </div>
@endsection
