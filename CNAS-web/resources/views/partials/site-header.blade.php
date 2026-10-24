<header class="flex h-18 justify-center border-b border-white/10 bg-primary text-white">
    <div class="flex w-full items-center justify-between max-sm:px-5 sm:px-10 md:px-15 lg:px-28">
        <a href="{{ route('landing') }}" class="flex w-80 items-center md:min-w-100 lg:w-140" aria-label="TCC NAV home">
            <img src="{{ asset('logo/nav-logo.svg') }}" alt="TCC NAV Logo" class="size-16 sm:size-18 md:size-24">
            <span class="-ml-3 flex flex-col">
                <span class="text-sm font-bold tracking-wider text-secondary sm:text-base md:text-lg lg:text-xl">TCC NAV</span>
                <span class="text-[.50rem] tracking-widest text-white/70 md:text-xs">TAGOLOAN COMMUNITY COLLEGE</span>
            </span>
        </a>
        <nav class="flex w-full flex-wrap items-center justify-end gap-2 text-xs font-semibold" aria-label="Main navigation">
            @auth
                <form method="POST" action="{{ Route::has('logout') ? route('logout') : '#' }}">
                    @csrf
                    <button type="submit" class="nav-button">Sign Out</button>
                </form>
            @elseif (! request()->routeIs('login'))
                <a href="{{ Route::has('login') ? route('login') : '#' }}" class="nav-button">Sign In</a>
            @endauth
        </nav>
    </div>
</header>
