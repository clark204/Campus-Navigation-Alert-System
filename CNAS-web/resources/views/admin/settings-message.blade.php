{{-- The error message of one settings section ($section: contact, emergency or account). The controllers flash
     'section' with 'error', so an error shows only in the section that was saved. Success messages ('status') show as
     a toast (partials/toasts). $error: an extra error to show here (e.g. from an error bag). $class: spacing, e.g. 'mt-4'. --}}
@php
    $class ??= '';
    $error = ($error ?? null) ?: (session('section') === $section ? session('error') : null);
@endphp
@if ($error)
    <p role="alert" class="{{ $class }} rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $error }}</p>
@endif
