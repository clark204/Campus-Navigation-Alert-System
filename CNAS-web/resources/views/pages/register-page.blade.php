@extends('layout.index-layout')

@section('title', 'TCC NAV · Create account')

@section('content.index')
    {{-- Fills the viewport below the h-18 (4.5rem) header. --}}
    <section class="relative isolate flex min-h-[calc(100svh-4.5rem)] flex-col overflow-hidden">
        <img src="{{ asset('images/background.png') }}" alt="" fetchpriority="high"
            class="absolute inset-0 -z-20 h-full w-full object-cover object-center">
        <div class="absolute inset-0 -z-10 bg-linear-to-r from-primary-hover/95 via-primary/90 to-primary-hover/80"></div>

        <div class="mx-auto w-full max-w-7xl px-5 pt-6 sm:px-8 lg:px-12">
            @include('partials.breadcrumbs', ['items' => [
                ['label' => 'Home', 'url' => route('landing')],
                ['label' => 'Create account'],
            ]])
        </div>

        <div class="mx-auto grid w-full max-w-7xl flex-1 items-center gap-10 px-5 py-8 sm:px-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,40rem)] lg:gap-14 lg:px-12 lg:py-10">
            <div class="h-full py-24 max-w-md text-white max-lg:hidden">
                <p class="mb-6 flex items-center gap-3 text-[10px] font-semibold tracking-[.22em] text-[#ead59c]">
                    <span class="h-px w-9 bg-secondary"></span> ONE ACCOUNT. EVERY CORNER OF CAMPUS.
                </p>
                <h1 class="text-5xl leading-[1.07] font-semibold tracking-[-.045em] lg:text-6xl">
                    Join<br><span class="text-[#dfbc59]">TCC NAV.</span>
                </h1>
                <p class="mt-6 text-base leading-7 text-white/75">
                    Create your account to find your way around campus.
                </p>
            </div>

            {{-- Frontend only: submitting does nothing until registration is wired up. --}}
            <div class="w-full rounded-3xl bg-surface p-6 shadow-2xl sm:p-8 max-lg:mx-auto max-lg:max-w-2xl">
                <p class="text-[10px] font-bold tracking-[.2em] text-secondary-hover">TCC NAV ACCOUNT</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Create account</h2>
                <p class="mt-1 text-sm text-text-muted">Fill in your details to get started.</p>

                <form class="mt-6 grid gap-4 sm:grid-cols-2" onsubmit="event.preventDefault()" novalidate>
                    <div>
                        <label for="school_id" class="mb-1.5 block text-xs font-semibold text-text-primary">
                            School ID <span class="font-normal text-text-muted">(optional)</span>
                        </label>
                        <input id="school_id" name="school_id" type="text" autocapitalize="none" spellcheck="false"
                            placeholder="e.g. 2024-00123" class="login-input">
                    </div>

                    <div>
                        <label for="email" class="mb-1.5 block text-xs font-semibold text-text-primary">Email</label>
                        <input id="email" name="email" type="email" autocomplete="email" autocapitalize="none"
                            spellcheck="false" required placeholder="name@tcc.edu.ph" class="login-input">
                    </div>

                    <div>
                        <label for="first_name" class="mb-1.5 block text-xs font-semibold text-text-primary">First name</label>
                        <input id="first_name" name="first_name" type="text" autocomplete="given-name" required
                            class="login-input">
                    </div>

                    <div>
                        <label for="last_name" class="mb-1.5 block text-xs font-semibold text-text-primary">Last name</label>
                        <input id="last_name" name="last_name" type="text" autocomplete="family-name" required
                            class="login-input">
                    </div>

                    <div>
                        <label for="middle_name" class="mb-1.5 block text-xs font-semibold text-text-primary">
                            Middle name <span class="font-normal text-text-muted">(optional)</span>
                        </label>
                        <input id="middle_name" name="middle_name" type="text" autocomplete="additional-name"
                            class="login-input">
                    </div>

                    {{-- Hardcoded until a colleges table exists; values are college IDs. --}}
                    <div>
                        <label for="college_id" class="mb-1.5 block text-xs font-semibold text-text-primary">College</label>
                        <select id="college_id" name="college_id" required class="login-input">
                            <option value="" disabled selected>Select your college</option>
                            <option value="C01">College of Information Technology</option>
                            <option value="C02">College 2</option>
                            <option value="C03">College 3</option>
                        </select>
                    </div>

                    @foreach ([
                        ['id' => 'password', 'label' => 'Password', 'placeholder' => 'Create a password'],
                        ['id' => 'password_confirmation', 'label' => 'Confirm password', 'placeholder' => 'Re-enter your password'],
                    ] as $field)
                        <div>
                            <label for="{{ $field['id'] }}" class="mb-1.5 block text-xs font-semibold text-text-primary">{{ $field['label'] }}</label>
                            <div class="relative">
                                <input id="{{ $field['id'] }}" name="{{ $field['id'] }}" type="password" autocomplete="new-password"
                                    required placeholder="{{ $field['placeholder'] }}" class="login-input pr-12">
                                <button type="button" data-password-toggle aria-controls="{{ $field['id'] }}" aria-label="Show password"
                                    class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-xl text-text-muted transition hover:text-primary">
                                    <svg data-icon-show class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg data-icon-hide class="hidden size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                        <path d="M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.4 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.1M6.6 6.6C3.8 8.4 2 12 2 12s3.6 7 10 7a9.7 9.7 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    @endforeach

                    <button type="submit"
                        class="mt-2 flex w-full sm:col-span-2 items-center justify-center gap-4 rounded-xl bg-primary px-6 py-3.5 text-sm font-bold text-white shadow-lg transition hover:bg-primary-hover">
                        Create account
                    </button>
                </form>

                <p class="mt-6 border-t border-border pt-4 text-center text-xs text-text-muted">
                    Already have an account?
                    <a href="{{ route('login') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Sign in</a>
                </p>
            </div>
        </div>
    </section>
@endsection
