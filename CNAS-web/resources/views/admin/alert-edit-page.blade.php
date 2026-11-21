{{-- Edit the wording of the active alert. Everyone sees it on the next poll; no new notification is sent. --}}
@extends('layout.admin-layout')
@use('App\Enums\AlertSeverity')

@section('title', 'Edit alert')
@section('heading', 'Emergency alerts')

@section('content.admin')
    <div class="mx-auto flex max-w-3xl flex-col gap-6">
        <div>
            <a href="{{ route('admin.alerts.index') }}" class="text-sm font-semibold text-primary underline-offset-4 hover:underline">Emergency alerts</a>
            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Edit alert</h1>
            <p class="mt-1 text-sm text-text-muted">
                {{ $alert->type->label() }} at {{ $alert->locationLabel() }}. Everyone sees the new wording within 15 seconds.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.alerts.update', $alert) }}" novalidate data-submit-once
            class="grid gap-4 rounded-2xl border border-border bg-surface p-5">
            @csrf
            @method('PUT')

            <div>
                <label for="title" class="mb-1.5 block text-xs font-semibold">Title</label>
                <input id="title" name="title" type="text" maxlength="120" required value="{{ old('title', $alert->title) }}" class="login-input"
                    @error('title') aria-invalid="true" aria-describedby="title-error" @enderror>
                @error('title') <p id="title-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="message" class="mb-1.5 block text-xs font-semibold">What is happening</label>
                <textarea id="message" name="message" rows="3" maxlength="1000" required class="login-input"
                    @error('message') aria-invalid="true" aria-describedby="message-error" @enderror>{{ old('message', $alert->message) }}</textarea>
                @error('message') <p id="message-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="instructions" class="mb-1.5 block text-xs font-semibold">What to do <span class="font-normal text-text-muted">(optional)</span></label>
                <textarea id="instructions" name="instructions" rows="4" maxlength="2000" class="login-input"
                    @error('instructions') aria-invalid="true" aria-describedby="instructions-error" @enderror>{{ old('instructions', $alert->instructions) }}</textarea>
                @error('instructions') <p id="instructions-error" class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            @include('admin.severity-cards', ['selected' => AlertSeverity::tryFrom((string) old('severity', $alert->severity->value)), 'hint' => 'Changing the level sends everyone a new notification.'])

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-lg bg-alarm px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-alarm-deep">
                    Save changes
                </button>
                <a href="{{ route('admin.alerts.index') }}" class="text-sm font-semibold text-text-muted hover:text-primary">Cancel</a>
            </div>
        </form>
    </div>
@endsection
