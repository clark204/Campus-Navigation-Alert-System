{{-- Reset page (Auth\PasswordResetController@edit), in two steps:
       1. the code from the email, checked on its own (password.verify-code);
       2. once it was right ($codeConfirmed), the new password twice (password.update).
     $login: what was typed on "Forgot password?" (kept in the session). Step 1 never says whether that account exists
     or where the code went. $resendIn: seconds until "Resend code" works (resources/js/resend-cooldown.js). --}}
@extends('layout.auth-card-layout')
@use('App\Services\EmailCodes')
@use('App\Services\PasswordRecovery')

@php
    $codeError = $errors->first('code') ?: $errors->first('reset_token') ?: null;
    $passwordError = $errors->first('password') ?: null;
    $label = 'mb-2 block text-xs font-semibold text-text-primary';
    $primaryButton = 'flex w-full items-center justify-center gap-2.5 rounded-xl bg-primary px-6 py-3.5 text-sm font-bold text-white shadow-lg transition hover:bg-primary-hover disabled:cursor-wait disabled:bg-primary-hover';
    $quietButton = 'w-full rounded-xl border border-border px-4 py-3 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10 disabled:cursor-not-allowed disabled:opacity-50';
@endphp

@section('title', 'TCC NAV · '.($codeConfirmed ? 'Choose a new password' : 'Enter your code'))
@section('aside.title', $codeConfirmed ? 'Almost there.' : 'Check your inbox.')
@section('aside.text', $codeConfirmed
    ? 'Your code was right. Choose a new password within '.PasswordRecovery::GRANT_TTL_MINUTES.' minutes.'
    : 'The code works for 10 minutes. If it isn’t in your inbox, look in spam, or send a new one.')

@section('card')
    {{-- Where they are: 2 short steps, the current one in maroon. --}}
    <ol class="flex gap-1.5" aria-label="Steps">
        <li class="h-1.5 flex-1 rounded-full bg-primary" @unless ($codeConfirmed) aria-current="step" @endunless><span class="sr-only">Step 1: Enter the code</span></li>
        <li @class(['h-1.5 flex-1 rounded-full', 'bg-primary' => $codeConfirmed, 'bg-border' => ! $codeConfirmed]) @if ($codeConfirmed) aria-current="step" @endif><span class="sr-only">Step 2: Choose a new password</span></li>
    </ol>
    <p class="mt-3 text-xs font-semibold text-text-muted">Step {{ $codeConfirmed ? 2 : 1 }} of 2 · {{ $codeConfirmed ? 'New password' : 'Code from the email' }}</p>

    @unless ($codeConfirmed)
        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Enter your code</h2>
        <p class="mt-2 text-sm text-text-muted">
            If an account matches <strong class="font-semibold break-all text-text-primary">{{ $login }}</strong>, we emailed it a {{ EmailCodes::LENGTH }}-digit code.
        </p>

        <form method="POST" action="{{ route('password.verify-code') }}" class="mt-6 space-y-4" novalidate data-submit-once data-clear-errors>
            @csrf
            <div>
                <label for="code" class="{{ $label }}">Code from the email</label>
                <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                    pattern="[0-9]*" maxlength="{{ EmailCodes::LENGTH + 2 }}" required autofocus placeholder="000000"
                    class="login-input py-3.5 text-center text-2xl font-semibold tracking-[.45em] tabular-nums placeholder:tracking-[.45em]"
                    @if ($codeError) aria-invalid="true" aria-describedby="code-error" @endif>
                @if ($codeError)
                    <p id="code-error" data-error-for="code" role="alert" class="mt-1.5 text-xs text-red-600">{{ $codeError }}</p>
                @endif
            </div>

            <button type="submit" data-loading-text="Checking…" class="{{ $primaryButton }}">
                <span data-submit-spinner hidden class="size-4 animate-spin rounded-full border-2 border-white/35 border-t-white motion-reduce:animate-none" aria-hidden="true"></span>
                <span data-submit-label>Continue</span>
            </button>
        </form>

        <form method="POST" action="{{ route('password.resend') }}" class="mt-4" data-submit-once>
            @csrf
            @include('partials.resend-code-button', ['class' => $quietButton, 'seconds' => $resendIn])
        </form>
    @else
        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Choose a new password</h2>
        <p class="mt-2 text-sm text-text-muted">Your code was right. Choose a password only you know.</p>

        <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4" novalidate data-submit-once data-clear-errors>
            @csrf
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

            <button type="submit" data-loading-text="Saving…" class="{{ $primaryButton }}">
                <span data-submit-spinner hidden class="size-4 animate-spin rounded-full border-2 border-white/35 border-t-white motion-reduce:animate-none" aria-hidden="true"></span>
                <span data-submit-label>Save new password</span>
            </button>
            <p class="text-center text-xs text-text-muted">You’ll be signed out everywhere, including the mobile app.</p>
        </form>
    @endunless

    <div class="mt-6 flex flex-wrap justify-center gap-x-4 gap-y-2 border-t border-border pt-5 text-xs">
        <a href="{{ route('password.request') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Use a different email or ID</a>
        <a href="{{ route('password.no-email') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Can’t access your email?</a>
    </div>
@endsection
