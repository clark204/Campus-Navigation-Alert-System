{{-- The school's seal first (the campus belongs to Tagoloan Community College; TCC NAV is a system built for it), then
     the TCC NAV logo, like the mobile app's Welcome screen. Guests: "Sign in" then "Start" (register, the call to action).
     On the login and registration pages the form is the action, so the header shows only the logos. Signed in:
     "Dashboard" and "Sign out". --}}
<header class="flex h-18 justify-center border-b border-white/10 bg-primary text-white">
    <div class="flex w-full items-center justify-between gap-3 max-sm:px-4 sm:px-10 md:px-15 lg:px-28">
        <a href="{{ route('landing') }}" class="flex min-w-0 shrink items-center gap-2 sm:gap-3" aria-label="TCC NAV home, Tagoloan Community College">
            <img src="{{ asset('logo/tcc-seal.png') }}" alt="Tagoloan Community College seal" width="360" height="360"
                class="size-10 shrink-0 rounded-full bg-white ring-2 ring-white/30 sm:size-12">
            {{-- On a white disc like the seal: the logo's dark lines don't show on maroon. Phones keep only the seal. --}}
            <span class="flex size-12 shrink-0 items-center justify-center rounded-full bg-white ring-2 ring-white/30 max-sm:hidden">
                <img src="{{ asset('logo/nav-logo.svg') }}" alt="TCC NAV logo" class="size-11">
            </span>
            <span class="flex min-w-0 flex-col">
                <span class="text-sm leading-tight font-bold text-white sm:text-base md:text-lg">Tagoloan Community College</span>
                <span class="truncate text-[.65rem] text-secondary max-sm:hidden md:text-xs">TCC NAV · Campus Navigation &amp; Alert System</span>
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
