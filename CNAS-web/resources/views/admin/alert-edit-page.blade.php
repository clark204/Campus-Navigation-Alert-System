{{-- Edit the wording of the active alert. Everyone sees it on the next poll; no new notification is sent. --}}
@extends('layout.admin-layout')
@use('App\Enums\AlertSeverity')

@php
    $breadcrumbs = [['label' => 'Edit alert']];
@endphp

@section('title', 'Edit alert')
@section('heading', 'Emergency alerts')

@section('content.admin')
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-2xl font-semibold tracking-tight text-primary sm:text-3xl">Edit alert</h1>
            <p class="mt-1 text-sm text-text-muted">
                {{ $alert->type->label() }} at {{ $alert->locationLabel() }}. Everyone sees the new wording within 15 seconds.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.alerts.update', $alert) }}" novalidate data-submit-once
            class="grid gap-4 rounded-2xl border border-border bg-surface p-5">
            @csrf
            @method('PUT')

            {{-- An alert sent before "What to do now" existed starts with the line people already see (its fallback). --}}
            @include('admin.alert-wording-fields', [
                'value' => fn (string $field) => old($field, $field === 'what_to_do' ? $alert->whatToDo() : $alert->{$field}),
                'preview' => ['type' => $alert->type, 'severity' => $alert->severity, 'drill' => $alert->is_drill, 'location' => $alert->locationLabel()],
            ])

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
