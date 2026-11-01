{{-- Admin top bar: menu toggle (below lg), page heading, search and the signed-in admin (placeholder until auth). --}}
<header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-border bg-surface/95 px-4 backdrop-blur sm:px-8 xl:px-10">
    <button type="button" data-admin-menu aria-controls="admin-sidebar" aria-expanded="false" aria-label="Open navigation"
        class="-ml-2 rounded-lg p-2 text-text-muted transition hover:bg-bg-light hover:text-primary lg:hidden">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
            <path d="M4 7h16M4 12h16M4 17h16" />
        </svg>
    </button>

    <p class="truncate text-base font-semibold text-primary">@yield('heading', 'Overview')</p>

    <form role="search" class="relative ml-auto w-full max-w-xs max-md:hidden" onsubmit="return false">
        <label for="admin-search" class="sr-only">Search the admin console</label>
        <svg class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none"
            stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zm9 2-4-4" />
        </svg>
        <input id="admin-search" type="search" placeholder="Search buildings, rooms, people"
            class="w-full rounded-lg border border-border bg-bg-light py-2 pr-3 pl-9 text-sm placeholder:text-text-muted/80 focus:border-secondary focus:bg-white focus:ring-4 focus:ring-secondary/20 focus:outline-none">
    </form>

    <div class="flex items-center gap-3 max-md:ml-auto">
        <span class="flex size-9 items-center justify-center rounded-full bg-secondary/20 text-sm font-semibold text-primary" aria-hidden="true">CA</span>
        <span class="flex flex-col leading-tight max-sm:hidden">
            <span class="text-sm font-semibold">Campus Admin</span>
            <span class="text-xs text-text-muted">Administrator</span>
        </span>
    </div>
</header>
