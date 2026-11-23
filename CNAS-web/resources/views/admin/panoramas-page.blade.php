{{-- Admin "360° photos": the photo points users can open on the maps. Upload one here (or import a folder with
     php artisan panoramas:import), place it and set its north on the map editor, then show it to users. --}}
@extends('layout.admin-layout')

@section('title', '360° photos')
@section('heading', '360° photos')

@section('content.admin')
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">360° photos</h1>
                <p class="mt-1 text-sm text-text-muted">
                    Photo points people can open on the campus map and while navigating. A photo is hidden until its north is set and you show it to users.
                </p>
            </div>
            <button type="button" data-open-upload aria-haspopup="dialog" aria-controls="upload-modal"
                class="shrink-0 rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">
                Upload photo
            </button>
        </div>

        @error('is_visible')
            <p role="alert" class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</p>
        @enderror

        {{-- The upload form, in a dialog opened from "Upload photo". It opens again by itself when the server sends the
             errors back (panoramas-upload.js), so they are not lost. --}}
        @php($reopen = $errors->hasAny(['photo', 'name']) || $tooLarge !== null)
        <div id="upload-modal" data-upload-modal role="dialog" aria-modal="true" aria-labelledby="upload-title"
            @unless ($reopen) hidden @endunless @if ($reopen) data-reopen @endif
            class="fixed inset-0 z-[900] flex items-center justify-center bg-neutral-950/60 p-4">
            <div class="grid w-full max-w-lg gap-4 rounded-2xl border border-border bg-surface p-5 shadow-2xl">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 id="upload-title" class="font-semibold">Upload a photo</h2>
                        <p class="mt-1 text-sm text-text-muted">
                            A full 360° JPEG (twice as wide as it is tall), up to {{ $maxSize }}. It's placed by the GPS in the photo; you'll check the position and set north on the map next.
                        </p>
                    </div>
                    <button type="button" data-upload-close aria-label="Close the upload"
                        class="grid size-9 shrink-0 place-items-center rounded-full text-text-muted transition hover:bg-bg-light hover:text-primary">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <form method="POST" action="{{ route('admin.panoramas.store') }}" enctype="multipart/form-data" novalidate
                    data-panorama-upload data-max-bytes="{{ $maxBytes }}" data-too-big="{{ \App\Http\Requests\Admin\StorePanoramaRequest::tooBigMessage() }}"
                    class="grid gap-3">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
                    <div class="min-w-0">
                        <label for="photo" class="mb-1.5 block text-xs font-semibold">Photo</label>
                        <input id="photo" name="photo" type="file" accept="image/jpeg" required
                            class="block w-full text-sm text-text-muted file:mr-3 file:rounded-lg file:border file:border-border file:bg-bg-light file:px-3 file:py-2 file:text-sm file:font-semibold file:text-text-primary"
                            aria-describedby="photo-error" @if ($tooLarge || $errors->has('photo')) aria-invalid="true" @endif>
                    </div>
                    <div class="min-w-0">
                        <label for="name" class="mb-1.5 block text-xs font-semibold">Name <span class="font-normal text-text-muted">(optional)</span></label>
                        <input id="name" name="name" type="text" maxlength="100" value="{{ old('name') }}" placeholder="e.g. Main walkway by the gate" class="login-input"
                            @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    </div>
                    <p id="photo-error" data-upload-error role="alert" class="text-sm text-red-600 empty:hidden">{{ $tooLarge ?? $errors->first('photo') }}</p>
                    @error('name') <p id="name-error" class="text-xs text-red-600">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap justify-end gap-2">
                        <button type="button" data-upload-close
                            class="rounded-lg border border-border px-4 py-2.5 text-sm font-semibold text-text-primary transition hover:border-primary hover:text-primary">
                            Cancel
                        </button>
                        <button type="submit" data-upload-submit
                            class="rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover disabled:cursor-wait disabled:opacity-70">
                            Upload
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <section aria-labelledby="panoramas-heading" class="grid gap-4"
            data-panoramas-list data-points="{{ $points->toJson() }}" data-map-url="{{ route('api.map') }}">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <h2 id="panoramas-heading" class="text-lg font-semibold">All photos <span class="text-sm font-normal text-text-muted">({{ $total }} in total)</span></h2>
                <form method="GET" action="{{ route('admin.panoramas.index') }}" role="search" class="flex flex-wrap items-end gap-2">
                    <div>
                        <label for="panorama-search" class="mb-1 block text-xs font-semibold">Search</label>
                        <input id="panorama-search" name="q" type="search" value="{{ $search }}" placeholder="Name or code" class="login-input w-56">
                    </div>
                    <div>
                        <label for="panorama-status" class="mb-1 block text-xs font-semibold">Status</label>
                        <select id="panorama-status" name="status" class="login-input w-48">
                            @foreach ($statuses as $key => $label)
                                <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="rounded-lg border border-border px-3 py-2 text-sm font-semibold text-text-primary transition hover:border-primary hover:text-primary">
                        Filter
                    </button>
                </form>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-border bg-surface">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <caption class="sr-only">360° photo points</caption>
                    <thead class="bg-bg-light text-xs font-semibold text-text-muted">
                        <tr>
                            <th scope="col" class="px-4 py-3">Photo</th>
                            <th scope="col" class="px-4 py-3">Name</th>
                            <th scope="col" class="px-4 py-3">Code</th>
                            <th scope="col" class="px-4 py-3">Floor</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($rows as $row)
                            @php($panorama = $row['panorama'])
                            <tr data-panorama-row="{{ $panorama->code }}" class="align-middle">
                                <td class="px-4 py-3">
                                    {{-- The still preview on hover is drawn on the page (panoramas-list.js), so the table cannot clip it. --}}
                                    <button type="button" data-preview="{{ $panorama->code }}" data-still="{{ $panorama->thumbUrl() }}" aria-label="Preview {{ $panorama->name }}"
                                        class="block rounded-lg focus-visible:outline-2 focus-visible:outline-primary">
                                        <img src="{{ $panorama->thumbUrl() }}" alt="" loading="lazy" width="128" height="64"
                                            class="h-16 w-32 rounded-lg bg-bg-light object-cover">
                                    </button>
                                </td>
                                <td class="px-4 py-3 font-semibold break-words">{{ $panorama->name }}</td>
                                <td class="px-4 py-3"><span class="rounded-md bg-bg-light px-1.5 py-0.5 font-mono text-[11px] font-medium text-text-muted">{{ $panorama->code }}</span></td>
                                <td class="px-4 py-3 text-text-muted">{{ Number::ordinal($panorama->floor) }}</td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1.5 text-[11px] font-semibold">
                                        @if ($panorama->is_visible)
                                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-800 ring-1 ring-emerald-200">Shown</span>
                                        @else
                                            <span class="rounded-full bg-bg-light px-2 py-0.5 text-text-muted ring-1 ring-border">Hidden</span>
                                        @endif
                                        @unless ($panorama->hasNorth())
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-800 ring-1 ring-amber-200">North not set</span>
                                        @endunless
                                        @if ($row['checkPosition'])
                                            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-800 ring-1 ring-amber-200"
                                                title="{{ $row['meters'] === null ? 'No walkway on this floor' : round($row['meters']).' m from the nearest walkway' }}">
                                                Check position<span class="font-normal"> · {{ $row['meters'] === null ? 'no walkway on this floor' : round($row['meters']).' m from a walkway' }}</span>
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <form method="POST" action="{{ route('admin.panoramas.visibility', $panorama) }}" data-submit-once>
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="is_visible" value="{{ $panorama->is_visible ? 0 : 1 }}">
                                            <button type="submit" @disabled(! $panorama->is_visible && ! $panorama->hasNorth())
                                                @if (! $panorama->is_visible && ! $panorama->hasNorth()) title="Set north on the map first" @endif
                                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-primary transition hover:border-primary hover:text-primary disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-border disabled:hover:text-text-primary">
                                                {{ $panorama->is_visible ? 'Hide' : 'Show' }}<span class="sr-only"> {{ $panorama->name }}</span>
                                            </button>
                                        </form>
                                        <button type="button" data-preview="{{ $panorama->code }}"
                                            class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-primary transition hover:border-primary hover:text-primary">
                                            Preview<span class="sr-only"> {{ $panorama->name }}</span>
                                        </button>
                                        <a href="{{ route('admin.panoramas.map', ['photo' => $panorama->code]) }}"
                                            class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-text-primary transition hover:border-primary hover:text-primary">
                                            Edit<span class="sr-only"> {{ $panorama->name }}</span>
                                        </a>
                                        <form method="POST" action="{{ route('admin.panoramas.destroy', $panorama) }}"
                                            data-confirm="Delete {{ $panorama->name }}? Its photo is removed from the site.">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:border-red-600 hover:bg-red-50">
                                                Delete<span class="sr-only"> {{ $panorama->name }}</span>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-6 text-center text-sm text-text-muted">
                                    @if ($total === 0)
                                        No photos yet. Upload one above
                                    @else
                                        No photos match this search or filter.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                <p class="text-text-muted">{{ $rows->total() }} {{ \Illuminate\Support\Str::plural('photo', $rows->total()) }}</p>
                {{ $rows->links() }}
            </div>

            {{-- The preview: the 360° viewer with its link arrows, for any photo (hidden ones too). Loaded when opened. --}}
            <div data-preview-modal hidden role="dialog" aria-modal="true" aria-labelledby="preview-title"
                class="fixed inset-0 z-[1000] flex flex-col bg-neutral-950 text-white">
                <div class="flex items-center justify-between gap-3 px-4 pt-[max(0.75rem,env(safe-area-inset-top))] pb-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold tracking-wide text-white/60 uppercase">360° photo preview</p>
                        <h2 id="preview-title" data-preview-title class="truncate text-base font-semibold"></h2>
                    </div>
                    <button type="button" data-preview-close aria-label="Close the preview"
                        class="grid size-10 shrink-0 place-items-center rounded-full bg-white/10 text-white transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-white">
                        <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                    </button>
                </div>
                <div class="relative min-h-0 flex-1">
                    <div data-preview-viewer class="absolute inset-0"></div>
                    <p data-preview-failed hidden class="absolute inset-0 flex items-center justify-center p-6 text-center text-sm text-white/80">
                        This photo can't be shown on this device right now.
                    </p>
                </div>
                <p class="px-4 pt-2 pb-[max(0.75rem,env(safe-area-inset-bottom))] text-xs text-white/60">
                    Drag to look around. Tap an arrow on the ground to walk to the next photo. Hidden photos are shown here only.
                </p>
            </div>
        </section>
    </div>
@endsection
