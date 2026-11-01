@extends('layout.index-layout')

@section('title', 'TCC NAV · Welcome to campus')

@section('content.index')
    {{-- Fills the viewport below the h-18 (4.5rem) header. --}}
    <section class="relative isolate flex min-h-[calc(100svh-4.5rem)] items-center overflow-hidden">
        <img src="{{ asset('images/background.png') }}" alt="" fetchpriority="high"
            class="absolute inset-0 -z-20 h-full w-full object-cover object-center">
        <div class="absolute inset-0 -z-10 bg-linear-to-r from-primary-hover/95 via-primary/90 to-primary-hover/80"></div>

        <div class="mx-auto grid w-full max-w-7xl items-center gap-10 px-5 py-10 sm:px-8 sm:py-12 lg:grid-cols-[.85fr_1.15fr] lg:gap-12 lg:px-12 lg:py-14">
            <div class="max-w-xl text-white">
                <p class="mb-6 flex items-center gap-3 text-[10px] font-semibold tracking-[.22em] text-[#ead59c]">
                    <span class="h-px w-9 bg-secondary"></span> YOUR CAMPUS. YOUR NEXT CHAPTER.
                </p>
                <h1 class="text-5xl leading-[1.07] font-semibold tracking-[-.045em] sm:text-6xl lg:text-[68px]">
                    Know your campus.<br><span class="text-[#dfbc59]">Find your path.</span>
                </h1>
                <p class="mt-6 max-w-md text-base leading-7 text-white/75">
                    From your first class to your next destination, feel at home at Tagoloan Community College.
                    Get to know the places that connect our campus.
                </p>
                <a href="{{ Route::has('navigation.index') ? route('navigation.index') : '#' }}"
                    class="mt-8 inline-flex items-center gap-6 rounded-xl bg-secondary px-6 py-3.5 text-sm font-bold text-primary-hover shadow-lg transition hover:bg-secondary-hover hover:text-white">
                    Explore the campus <span aria-hidden="true">→</span>
                </a>
                <p class="mt-4 text-xs text-white/55">Campus directions are open to everyone. No login needed.</p>
                <div class="mt-9 flex flex-wrap gap-x-6 gap-y-3 border-t border-white/15 pt-5 text-xs text-white/70">
                    <span>⌖ Tagoloan, Misamis Oriental</span>
                    <span>For students, faculty & visitors</span>
                </div>
            </div>

            <div class="min-w-0">
                @include('partials.campus-map-preview')
            </div>
        </div>
    </section>
@endsection
