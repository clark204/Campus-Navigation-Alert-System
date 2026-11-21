@extends('layout.admin-layout')

@section('title', $building->name)
@section('heading', 'Edit building')

@section('content.admin')
    <div class="mx-auto flex max-w-3xl flex-col gap-6">
        <div>
            <a href="{{ route('admin.buildings.index') }}" class="text-sm font-semibold text-primary underline-offset-4 hover:underline">Buildings</a>
            <h1 class="mt-2 text-2xl font-semibold tracking-tight text-primary sm:text-3xl">{{ $building->name }}</h1>
            <p class="mt-1 text-sm text-text-muted tabular-nums">
                {{ $building->code }} · {{ $building->type->label() }}@if ($building->floors) · {{ $building->floors }} {{ \Illuminate\Support\Str::plural('floor', $building->floors) }}@endif
            </p>
        </div>

        <section aria-labelledby="names-heading" class="rounded-2xl border border-border bg-surface p-5">
            <h2 id="names-heading" class="font-semibold">Names</h2>
            <form method="POST" action="{{ route('admin.buildings.update', $building) }}" novalidate data-submit-once class="mt-4 grid gap-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="name" class="mb-1.5 block text-xs font-semibold text-text-primary">Name</label>
                    <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $building->name) }}" class="login-input"
                        @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name') <p id="name-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="landmark_name" class="mb-1.5 block text-xs font-semibold text-text-primary">Landmark name</label>
                    <input id="landmark_name" name="landmark_name" type="text" maxlength="60" value="{{ old('landmark_name', $building->landmark_name) }}"
                        placeholder="e.g. the Library" class="login-input" @error('landmark_name') aria-invalid="true" aria-describedby="landmark-error" @enderror>
                    <p class="mt-1 text-xs text-text-muted">The name students hear in walking directions. Include “the” where it reads naturally.</p>
                    @error('landmark_name') <p id="landmark-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">Save</button>
                    <a href="{{ route('admin.buildings.index') }}" class="text-sm font-semibold text-text-muted underline-offset-4 hover:text-primary hover:underline">Cancel</a>
                </div>
            </form>
        </section>

        @include('admin.place-photos', ['place' => $building, 'routeBase' => 'admin.buildings', 'maxPhotos' => $maxPhotos])
    </div>
@endsection
