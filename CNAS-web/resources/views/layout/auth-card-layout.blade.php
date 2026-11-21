{{-- The sign-in look (maroon photo background, words on the left, a white card on the right) for the password pages:
     "Forgot password?", the reset page, COR recovery and "Choose a new password". Pages fill 'card', and 'aside.title'
     and 'aside.text' for the left side (hidden on phones). --}}
@extends('layout.index-layout')

@section('content.index')
    {{-- Fills the viewport below the h-18 (4.5rem) header. --}}
    <section class="relative isolate flex min-h-[calc(100svh-4.5rem)] flex-col overflow-hidden">
        <img src="{{ asset('images/background.png') }}" alt="" fetchpriority="high"
            class="absolute inset-0 -z-20 h-full w-full object-cover object-center">
        <div class="absolute inset-0 -z-10 bg-linear-to-r from-primary-hover/95 via-primary/90 to-primary-hover/80"></div>

        <div class="mx-auto grid w-full max-w-7xl flex-1 items-center gap-10 px-5 py-10 sm:px-8 sm:py-12 lg:grid-cols-[1fr_minmax(0,28rem)] lg:gap-16 lg:px-12 lg:py-14">
            <div class="max-w-xl text-white max-lg:hidden">
                <h1 class="text-5xl leading-[1.07] font-semibold tracking-[-.045em] lg:text-6xl">@yield('aside.title')</h1>
                <p class="mt-6 max-w-md text-base leading-7 text-white/75">@yield('aside.text')</p>
            </div>

            <div class="w-full rounded-3xl bg-surface p-7 shadow-2xl sm:p-9 max-lg:mx-auto max-lg:max-w-md">
                @yield('card')
            </div>
        </div>
    </section>
@endsection
