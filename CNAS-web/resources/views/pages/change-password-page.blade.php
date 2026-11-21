{{-- "Choose a new password" (Auth\TemporaryPasswordController): after signing in with a temporary password from the
     admin. The portal waits until this is done (EnsurePasswordIsChanged). --}}
@extends('layout.auth-card-layout')

@php
    $passwordError = $errors->first('password') ?: null;
    $label = 'mb-2 block text-xs font-semibold text-text-primary';
@endphp

@section('title', 'TCC NAV · Choose a new password')
@section('aside.title', 'Make it yours.')
@section('aside.text', 'The administrator gave you a temporary password. Choose one only you know before you continue.')

@section('card')
    <span class="grid size-12 place-items-center rounded-2xl bg-secondary/15 text-secondary-hover" aria-hidden="true">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <rect x="4" y="10.5" width="16" height="10" rx="2" />
            <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" />
        </svg>
    </span>
    <h2 class="mt-4 text-2xl font-semibold tracking-tight text-text-primary">Choose a new password</h2>
    <p class="mt-2 text-sm text-text-muted">You signed in with a temporary password from the administrator. Choose your own to continue.</p>

    <form method="POST" action="{{ route('password.change.update') }}" class="mt-6 space-y-4" novalidate data-submit-once data-clear-errors>
        @csrf
        @method('PUT')
        <div>
            <label for="password" class="{{ $label }}">New password</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required autofocus class="login-input"
                aria-describedby="password-hint @if ($passwordError) password-error @endif" @if ($passwordError) aria-invalid="true" @endif>
            <p id="password-hint" class="mt-1.5 text-xs text-text-muted">At least 8 characters, with letters and numbers.</p>
            @if ($passwordError)
                <p id="password-error" data-error-for="password" class="mt-1 text-xs text-red-600">{{ $passwordError }}</p>
            @endif
        </div>
        <div>
            <label for="password_confirmation" class="{{ $label }}">New password again</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="login-input">
        </div>

        <button type="submit" data-loading-text="Saving…"
            class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-primary px-6 py-3.5 text-sm font-bold text-white shadow-lg transition hover:bg-primary-hover disabled:cursor-wait disabled:bg-primary-hover">
            <span data-submit-spinner hidden class="size-4 animate-spin rounded-full border-2 border-white/35 border-t-white motion-reduce:animate-none" aria-hidden="true"></span>
            <span data-submit-label>Save and continue</span>
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-5 text-center text-xs text-text-muted">
        @csrf
        Not your account?
        <button type="submit" class="font-semibold text-primary underline-offset-4 hover:underline">Sign out</button>
    </form>
@endsection
