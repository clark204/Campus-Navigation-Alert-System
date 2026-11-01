{{-- Guests: "Sign in" then "Start" (register, the call to action). On the login and registration pages the form
     is the action, so the header shows only the logo. Signed in: "Dashboard" and "Sign out". --}}
<header class="flex h-18 justify-center border-b border-white/10 bg-primary text-white">
    <div class="flex w-full items-center justify-between gap-3 max-sm:px-4 sm:px-10 md:px-15 lg:px-28">
        <a href="{{ route('landing') }}" class="flex min-w-0 shrink items-center md:min-w-100 lg:w-140" aria-label="TCC NAV home">
            <img src="{{ asset('logo/nav-logo.svg') }}" alt="TCC NAV Logo" class="size-16 shrink-0 sm:size-18 md:size-24">
            <span class="-ml-3 flex min-w-0 flex-col">
                <span class="text-sm font-bold tracking-wider text-secondary sm:text-base md:text-lg lg:text-xl">TCC
                    NAV</span>
                <span class="text-[.50rem] tracking-widest text-white/70 max-[380px]:hidden md:text-xs">TAGOLOAN COMMUNITY COLLEGE</span>
            </span>
        </a>
        <nav class="flex shrink-0 items-center gap-2 sm:gap-3" aria-label="Account">
            @auth
                <a href="{{ route(auth()->user()->role->homeRoute()) }}" class="header-cta">Dashboard</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="header-button">Sign out</button>
                </form>
            @elseif (! request()->routeIs('login', 'register*'))
                <a href="{{ route('login') }}" class="header-button">Sign in</a>
                <a href="{{ route('register') }}" class="header-cta">Start</a>
            @endauth
        </nav>
    </div>
</header>
