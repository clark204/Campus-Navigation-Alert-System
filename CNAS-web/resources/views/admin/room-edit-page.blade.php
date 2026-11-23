@extends('layout.admin-layout')
@use('Illuminate\Support\Number')

@php
    $breadcrumbs = [['label' => $room->name]];
@endphp

@section('title', $room->name)
@section('heading', 'Edit room')

@section('content.admin')
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">{{ $room->name }}</h1>
            <p class="mt-1 text-sm text-text-muted tabular-nums">
                {{ $room->code }} · {{ $room->building->name }} · {{ Number::ordinal($room->floor) }} floor · {{ $room->type->label() }}
            </p>
        </div>

        <section aria-labelledby="name-heading" class="rounded-2xl border border-border bg-surface p-5">
            <h2 id="name-heading" class="font-semibold">Name</h2>
            <form method="POST" action="{{ route('admin.rooms.update', $room) }}" novalidate data-submit-once class="mt-4 grid gap-4">
                @csrf
                @method('PUT')

                <div>
                    <label for="name" class="mb-1.5 block text-xs font-semibold text-text-primary">Name</label>
                    <input id="name" name="name" type="text" required maxlength="100" value="{{ old('name', $room->name) }}" class="login-input"
                        @error('name') aria-invalid="true" aria-describedby="name-error" @enderror>
                    @error('name') <p id="name-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" class="rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-primary-hover">Save</button>
                    <a href="{{ route('admin.rooms.index') }}" class="text-sm font-semibold text-text-muted underline-offset-4 hover:text-primary hover:underline">Cancel</a>
                </div>
            </form>
        </section>

        @include('admin.place-photos', ['place' => $room, 'routeBase' => 'admin.rooms', 'maxPhotos' => $maxPhotos])
    </div>
@endsection
