{{-- "Verify your email" (Auth\EmailVerificationController): the 6-digit code emailed after registering or signing in.
     $email: where it went. $codeIsUsable: false when it expired or had too many wrong tries (then "Send a new code").
     $resendIn: seconds until a new code can be sent (resources/js/resend-cooldown.js counts down). --}}
@extends('layout.index-layout')
@use('App\Services\EmailCodes')

@php
    $codeError = $errors->first('code') ?: null;
    $emailError = $errors->first('email') ?: null;
    $primaryButton = 'flex w-full items-center justify-center gap-3 rounded-xl bg-primary px-6 py-3.5 text-sm font-bold text-white shadow-lg transition hover:bg-primary-hover disabled:opacity-60';
    $quietButton = 'w-full rounded-xl border border-border px-4 py-3 text-sm font-semibold text-primary transition hover:border-secondary hover:bg-secondary/10 disabled:cursor-not-allowed disabled:opacity-50';
@endphp

@section('title', 'TCC NAV · Verify your email')

@section('content.index')
    {{-- Fills the viewport below the h-18 (4.5rem) header. --}}
    <section class="relative isolate flex min-h-[calc(100svh-4.5rem)] flex-col overflow-hidden">
        <img src="{{ asset('images/background.png') }}" alt="" fetchpriority="high"
            class="absolute inset-0 -z-20 h-full w-full object-cover object-center">
        <div class="absolute inset-0 -z-10 bg-linear-to-r from-primary-hover/95 via-primary/90 to-primary-hover/80"></div>

        <div class="mx-auto grid w-full max-w-7xl flex-1 items-center gap-10 px-5 py-10 sm:px-8 sm:py-12 lg:grid-cols-[1fr_minmax(0,28rem)] lg:gap-16 lg:px-12 lg:py-14">
            <div class="max-w-xl text-white max-lg:hidden">
                <h1 class="text-5xl leading-[1.07] font-semibold tracking-[-.045em] lg:text-6xl">Check your inbox.</h1>
                <p class="mt-6 max-w-md text-base leading-7 text-white/75">
                    One quick step so we know this address is really yours. Replies from the administrator and help
                    with your account go here.
                </p>
                <p class="mt-6 max-w-md text-sm leading-6 text-white/60">
                    The campus map works while you wait.
                    <a href="{{ route('navigate') }}" class="font-semibold text-[#dfbc59] underline-offset-4 hover:underline">Open the map</a>
                </p>
            </div>

            <div class="w-full rounded-3xl bg-surface p-7 shadow-2xl sm:p-9 max-lg:mx-auto max-lg:max-w-md">
                <span class="grid size-12 place-items-center rounded-2xl bg-secondary/15 text-secondary-hover" aria-hidden="true">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="5" width="18" height="14" rx="2" />
                        <path d="m3 7 9 6 9-6" />
                    </svg>
                </span>
                <h2 class="mt-4 text-2xl font-semibold tracking-tight text-text-primary">Verify your email</h2>
                <p class="mt-2 text-sm text-text-muted">
                    @if ($codeIsUsable)
                        Enter the {{ EmailCodes::LENGTH }}-digit code we sent to
                    @else
                        Your last code can’t be used any more. Send a new one to
                    @endif
                    <strong class="font-semibold break-all text-text-primary">{{ $email }}</strong>.
                </p>

                <form method="POST" action="{{ route('email.verify.store') }}" class="mt-6 space-y-4" novalidate data-submit-once data-clear-errors>
                    @csrf
                    <div>
                        <label for="code" class="mb-2 block text-xs font-semibold text-text-primary">Code from the email</label>
                        <input id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                            pattern="[0-9]*" maxlength="{{ EmailCodes::LENGTH + 2 }}" required autofocus placeholder="000000"
                            class="login-input py-4 text-center text-2xl font-semibold tracking-[.45em] tabular-nums placeholder:tracking-[.45em]"
                            @if ($codeError) aria-invalid="true" aria-describedby="code-error" @endif>
                        @if ($codeError)
                            <p id="code-error" data-error-for="code" role="alert" class="mt-1.5 text-xs text-red-600">{{ $codeError }}</p>
                        @else
                            <p class="mt-1.5 text-xs text-text-muted">It works for {{ EmailCodes::TTL_MINUTES }} minutes. Check your spam folder if it isn’t there.</p>
                        @endif
                    </div>

                    <button type="submit" class="{{ $primaryButton }}">Verify email</button>
                </form>

                <form method="POST" action="{{ route('email.verify.resend') }}" class="mt-3" data-submit-once>
                    @csrf
                    @include('partials.resend-code-button', ['class' => $quietButton, 'seconds' => $resendIn])
                </form>

                {{-- A typo at sign-up: fix it here, since Settings waits until the email is verified. --}}
                <details class="group mt-6 border-t border-border pt-5" @if ($emailError) open @endif>
                    <summary class="cursor-pointer list-none text-center text-xs font-semibold text-primary underline-offset-4 hover:underline [&::-webkit-details-marker]:hidden">
                        Wrong email address?
                    </summary>
                    <form method="POST" action="{{ route('email.verify.email') }}" class="mt-4 space-y-3" novalidate data-submit-once>
                        @csrf
                        @method('PUT')
                        <div>
                            <label for="email" class="mb-2 block text-xs font-semibold text-text-primary">Your email address</label>
                            <input id="email" name="email" type="email" required maxlength="255" autocomplete="email" autocapitalize="none" spellcheck="false"
                                value="{{ $emailError ? old('email', $email) : $email }}" class="login-input"
                                @if ($emailError) aria-invalid="true" aria-describedby="email-error" @endif>
                            @if ($emailError)
                                <p id="email-error" class="mt-1.5 text-xs text-red-600">{{ $emailError }}</p>
                            @endif
                        </div>
                        <button type="submit" class="{{ $quietButton }}">Save and send a new code</button>
                    </form>
                </details>

                <form method="POST" action="{{ route('logout') }}" class="mt-5 text-center text-xs text-text-muted">
                    @csrf
                    Not your account?
                    <button type="submit" class="font-semibold text-primary underline-offset-4 hover:underline">Sign out</button>
                </form>
            </div>
        </div>
    </section>
@endsection
