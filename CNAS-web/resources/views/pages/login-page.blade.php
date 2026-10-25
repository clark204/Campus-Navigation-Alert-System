@extends('layout.index-layout')

@section('title', 'TCC NAV · Sign in')

@section('content.index')
    {{-- Fills the viewport below the h-18 (4.5rem) header. --}}
    <section class="relative isolate flex min-h-[calc(100svh-4.5rem)] flex-col overflow-hidden">
        <img src="{{ asset('images/background.png') }}" alt="" fetchpriority="high"
            class="absolute inset-0 -z-20 h-full w-full object-cover object-center">
        <div class="absolute inset-0 -z-10 bg-linear-to-r from-primary-hover/95 via-primary/90 to-primary-hover/80"></div>

        <div class="mx-auto w-full max-w-7xl px-5 pt-6 sm:px-8 lg:px-12">
            @include('partials.breadcrumbs', ['items' => [
                ['label' => 'Home', 'url' => route('landing')],
                ['label' => 'Sign in'],
            ]])
        </div>

        <div class="mx-auto grid w-full max-w-7xl flex-1 items-center gap-10 px-5 py-10 sm:px-8 sm:py-12 lg:grid-cols-[1fr_minmax(0,28rem)] lg:gap-16 lg:px-12 lg:py-14">
            <div class="h-full py-24 max-w-xl text-white max-lg:hidden">
                <p class="mb-6 flex items-center gap-3 text-[10px] font-semibold tracking-[.22em] text-[#ead59c]">
                    <span class="h-px w-9 bg-secondary"></span> ONE ACCOUNT. EVERY CORNER OF CAMPUS.
                </p>
                <h1 class="text-5xl leading-[1.07] font-semibold tracking-[-.045em] lg:text-6xl">
                    Welcome back<br><span class="text-[#dfbc59]">to TCC.</span>
                </h1>
                <p class="mt-6 mb-9 max-w-md text-base leading-7 text-white/75">
                    Sign in to manage your campus experience.
                </p>
            </div>

            {{-- Frontend only: submitting does nothing until authentication is wired up. --}}
            <div class="w-full rounded-3xl bg-surface p-7 shadow-2xl sm:p-9 max-lg:mx-auto max-lg:max-w-md">
                <p class="text-[10px] font-bold tracking-[.2em] text-secondary-hover">TCC NAV ACCOUNT</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Sign in</h2>
                <p class="mt-2 text-sm text-text-muted">Use your school ID number or email address.</p>

                <form class="mt-8 space-y-5" onsubmit="event.preventDefault()" novalidate>
                    <div>
                        <label for="login" class="mb-2 block text-xs font-semibold text-text-primary">ID number or email</label>
                        <input id="login" name="login" type="text" autocomplete="username" autocapitalize="none"
                            spellcheck="false" required placeholder="e.g. 2024-00123 or name@tcc.edu.ph"
                            class="login-input">
                    </div>

                    <div>
                        <div class="mb-2 flex items-center justify-between">
                            <label for="password" class="text-xs font-semibold text-text-primary">Password</label>
                            <a href="#" class="text-xs font-semibold text-primary underline-offset-4 hover:underline">Forgot password?</a>
                        </div>
                        <div class="relative">
                            <input id="password" name="password" type="password" autocomplete="current-password" required
                                placeholder="Enter your password" class="login-input pr-12">
                            <button type="button" data-password-toggle aria-controls="password" aria-label="Show password"
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

                    <label class="flex w-fit items-center gap-2.5 text-xs text-text-muted">
                        <input type="checkbox" name="remember" class="size-4 rounded accent-primary">
                        Keep me signed in
                    </label>

                    <button type="submit"
                        class="flex w-full items-center justify-center gap-4 rounded-xl bg-primary px-6 py-3.5 text-sm font-bold text-white shadow-lg transition hover:bg-primary-hover">
                        Sign in <span aria-hidden="true">→</span>
                    </button>
                </form>

                <p class="mt-5 text-center text-xs text-text-muted">
                    Don't have an account?
                    <a href="{{ route('register') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Create one</a>
                </p>

                <p class="mt-6 border-t border-border pt-5 text-center text-xs text-text-muted">
                    Just looking for directions?
                    <a href="{{ route('landing') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Explore the campus without signing in</a>
                </p>
            </div>
        </div>
    </section>
@endsection
