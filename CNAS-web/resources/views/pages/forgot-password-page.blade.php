{{-- "Forgot password?" (Auth\PasswordResetController@create): the email or student ID of the account. A code goes to
     the account's email (verified or not); the next page always says the same thing, whether or not the account
     exists. Someone who can't open their email goes to the administrator (pages/no-email-access-page). --}}
@extends('layout.auth-card-layout')

@php
    $loginError = $errors->first('login') ?: null;
@endphp

@section('title', 'TCC NAV · Forgot password')
@section('aside.title', 'It happens.')
@section('aside.text', 'We’ll email you a code to choose a new password. Your schedule, saved places and everything else stay as they are.')

@section('card')
    <h2 class="mt-4 text-2xl font-semibold tracking-tight text-text-primary">Forgot your password?</h2>
    <p class="mt-2 text-sm text-text-muted">Enter the email or student ID you sign in with. We’ll send a 6-digit code to the email on that account.</p>

    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4" novalidate data-submit-once data-clear-errors>
        @csrf
        <div>
            <label for="login" class="mb-2 block text-xs font-semibold text-text-primary">ID number or email</label>
            <input id="login" name="login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false"
                required autofocus maxlength="255" placeholder="e.g. 20240001 or example@gmail.com" value="{{ $login }}" class="login-input"
                @if ($loginError) aria-invalid="true" aria-describedby="login-error" @endif>
            @if ($loginError)
                <p id="login-error" data-error-for="login" class="mt-1.5 text-xs text-red-600">{{ $loginError }}</p>
            @endif
        </div>

        <button type="submit" data-loading-text="Sending…"
            class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-primary px-6 py-3.5 text-sm font-bold text-white shadow-lg transition hover:bg-primary-hover disabled:cursor-wait disabled:bg-primary-hover">
            <span data-submit-spinner hidden class="size-4 animate-spin rounded-full border-2 border-white/35 border-t-white motion-reduce:animate-none" aria-hidden="true"></span>
            <span data-submit-label>Send code</span>
        </button>
    </form>

    <div class="mt-6 rounded-2xl bg-bg-light px-4 py-3.5 text-sm">
        <p class="font-semibold text-text-primary">Can’t access your email?</p>
        <p class="mt-0.5 text-text-muted">
            The administrator can fix your email and give you a temporary password.
            <a href="{{ route('password.no-email') }}" class="font-semibold text-primary underline-offset-4 hover:underline">What to do</a>
        </p>
    </div>

    <p class="mt-5 text-center text-xs text-text-muted">
        Remembered it?
        <a href="{{ route('login') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Sign in</a>
    </p>
@endsection
