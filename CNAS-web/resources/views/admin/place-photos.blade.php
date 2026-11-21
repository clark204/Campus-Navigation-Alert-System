{{-- Photos of a building or room ($place). Up to $maxPhotos; the first by position is the cover. $routeBase is
     'admin.buildings' or 'admin.rooms'. Normal photos show as images; 360° photos open in a viewer (place-photos.js). --}}
@php($photos = $place->photos)
<section aria-labelledby="photos-heading" class="rounded-2xl border border-border bg-surface p-5" data-place-photos>
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="photos-heading" class="font-semibold">Photos</h2>
        <p class="text-sm text-text-muted tabular-nums">{{ $photos->count() }} of {{ $maxPhotos }}. The first is the cover.</p>
    </div>

    @if ($photos->isEmpty())
        <p class="mt-4 text-sm text-text-muted">No photos yet.</p>
    @else
        <ol class="mt-4 grid gap-4 sm:grid-cols-2">
            @foreach ($photos as $photo)
                <li class="flex flex-col gap-3 rounded-xl border border-border p-3">
                    @if ($photo->kind === \App\Enums\PhotoKind::Panorama)
                        <details data-panorama-preview data-src="{{ $photo->imageUrl() }}">
                            <summary data-still="{{ $photo->previewUrl() }}" class="w-fit cursor-pointer text-sm font-semibold text-primary underline-offset-4 hover:underline">Look around</summary>
                            <div data-panorama-viewer class="mt-2 aspect-2/1 w-full overflow-hidden rounded-lg bg-bg-light"></div>
                        </details>
                    @else
                        <img src="{{ $photo->imageUrl() }}" data-still="{{ $photo->previewUrl() }}" alt="" loading="lazy" class="aspect-4/3 w-full rounded-lg bg-bg-light object-cover">
                    @endif

                    <p class="text-xs text-text-muted tabular-nums">
                        {{ $loop->first ? 'Cover · ' : '' }}{{ $photo->kind->label() }} · {{ $photo->width }}×{{ $photo->height }}
                    </p>

                    <div class="flex flex-wrap gap-2">
                        <form method="POST" action="{{ route($routeBase.'.photos.move', [$place, $photo]) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="direction" value="up">
                            <button type="submit" @disabled($loop->first)
                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10 disabled:opacity-40">Move up</button>
                        </form>
                        <form method="POST" action="{{ route($routeBase.'.photos.move', [$place, $photo]) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="direction" value="down">
                            <button type="submit" @disabled($loop->last)
                                class="rounded-lg border border-border px-3 py-1.5 text-xs font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10 disabled:opacity-40">Move down</button>
                        </form>
                        <form method="POST" action="{{ route($routeBase.'.photos.destroy', [$place, $photo]) }}" data-confirm="Delete this photo? This cannot be undone.">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 transition hover:bg-red-50">Delete</button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif

    @if ($photos->count() < $maxPhotos)
        <form method="POST" action="{{ route($routeBase.'.photos.store', $place) }}" enctype="multipart/form-data" novalidate data-submit-once data-place-photo-form
            class="mt-6 grid gap-4 border-t border-border pt-5">
            @csrf
            <fieldset class="grid gap-2">
                <legend class="mb-1 text-xs font-semibold text-text-primary">Type of photo</legend>
                <label class="flex items-start gap-2 text-sm">
                    <input type="radio" name="kind" value="photo" data-photo-kind class="mt-1" @checked(old('kind', 'photo') === 'photo')>
                    <span>Normal photo <span class="text-text-muted">(JPEG, PNG or WebP, up to 8 MB)</span></span>
                </label>
                <label class="flex items-start gap-2 text-sm">
                    <input type="radio" name="kind" value="panorama" data-photo-kind class="mt-1" @checked(old('kind') === 'panorama')>
                    <span>360 photo <span class="text-text-muted">(JPEG, twice as wide as it is tall, at least 2048 px wide)</span></span>
                </label>
            </fieldset>

            <div>
                <label for="photos-{{ $place->code }}" class="mb-1.5 block text-xs font-semibold text-text-primary">Choose photos</label>
                <input id="photos-{{ $place->code }}" name="photos[]" type="file" multiple required data-photo-input
                    accept="image/jpeg,image/png,image/webp"
                    class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-bg-light file:px-4 file:py-2 file:text-sm file:font-semibold file:text-primary">
                <p class="mt-1 text-xs text-text-muted">You can add {{ $maxPhotos - $photos->count() }} more.</p>
                @php($photoErrors = collect($errors->getBag('default')->messages())->filter(fn ($messages, $key) => $key === 'kind' || $key === 'photos' || str_starts_with($key, 'photos.'))->flatten())
                @foreach ($photoErrors as $message)
                    <p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @endforeach
            </div>

            <button type="submit" class="w-fit rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">Upload photos</button>
        </form>
    @else
        <p class="mt-6 border-t border-border pt-5 text-sm text-text-muted">This place has the most photos allowed ({{ $maxPhotos }}). Delete one to add another.</p>
    @endif
</section>
