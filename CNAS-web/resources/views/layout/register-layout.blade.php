{{-- Shared shell for the three registration steps. Pages set `title` and fill `content.register` (the card body). --}}
@extends('layout.index-layout')

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
                    Sign up with your Certificate of Registration for this term. Your account is ready right away.
                </p>
            </div>

            <div class="w-full rounded-3xl bg-surface p-6 shadow-2xl sm:p-8 max-lg:mx-auto max-lg:max-w-2xl">
                <p class="text-[10px] font-bold tracking-[.2em] text-secondary-hover">TCC NAV ACCOUNT</p>
                <h2 class="mt-2 text-2xl font-semibold tracking-tight text-text-primary">Create account</h2>

                @include('partials.register-steps', ['current' => $step])

                @error('form')
                    <p role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{{ $message }}</p>
                @enderror

                @yield('content.register')

                <p class="mt-6 border-t border-border pt-4 text-center text-xs text-text-muted">
                    Already have an account?
                    <a href="{{ route('login') }}" class="font-semibold text-primary underline-offset-4 hover:underline">Sign in</a>
                </p>
            </div>
        </div>
    </section>
@endsection
