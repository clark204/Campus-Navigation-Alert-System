{{-- Sidebar shared by the admin console and the portal. Off-canvas below lg, opened from the top bar menu button.
     Needs: $sidebarId, $navLabel, $homeRoute, $subtitle and $groups (heading or null => items with label, route, icon,
     and optionally `active`: route name patterns that also highlight the item, e.g. its edit pages; `badge`: a count
     shown after the label when above 0, read as "{badge} {badgeLabel}"; `live`: a URL whose JSON `count` refreshes it).
     $helpRoute: route name of the Help & contact page (the portal's), or null for none (the admin console).
     Links without a route yet fall back to '#'. Icons are 24×24 stroke paths. --}}
@php
    // The map gets a footer link unless the navigation already lists it.
    $showsMapLink = collect($groups)->flatten(1)->doesntContain('route', 'navigate');
@endphp

<div data-sidebar-backdrop hidden class="fixed inset-0 z-30 bg-primary-hover/50 lg:hidden"></div>

<aside id="{{ $sidebarId }}" data-sidebar
    class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-primary text-white/80 transition-transform duration-200 not-data-open:max-lg:invisible data-open:translate-x-0 lg:sticky lg:top-0 lg:h-svh lg:translate-x-0">
    <a href="{{ route($homeRoute) }}" class="flex h-16 shrink-0 items-center gap-1 border-b border-white/10 px-3">
        <img src="{{ asset('logo/nav-logo.svg') }}" alt="" class="size-14">
        <span class="-ml-2 flex flex-col leading-tight">
            <span class="font-bold tracking-wide text-secondary">TCC NAV</span>
            <span class="text-xs text-white/60">{{ $subtitle }}</span>
        </span>
    </a>

    <nav aria-label="{{ $navLabel }}" class="flex-1 overflow-y-auto px-3 py-5">
        @foreach ($groups as $heading => $items)
            <div @class(['mt-6' => ! $loop->first])>
                @if ($heading)
                    <p class="mb-2 px-3 text-xs font-medium text-white/45">{{ $heading }}</p>
                @endif
                <ul class="flex flex-col gap-0.5">
                    @foreach ($items as $item)
                        @php($active = request()->routeIs(...(array) ($item['active'] ?? $item['route'])))
                        <li>
                            <a href="{{ Route::has($item['route']) ? route($item['route']) : '#' }}"
                                @if ($active) aria-current="page" @endif
                                @class([
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition',
                                    'bg-white/10 font-semibold text-white shadow-[inset_3px_0_0_var(--color-secondary)]' => $active,
                                    'hover:bg-white/5 hover:text-white' => ! $active,
                                ])>
                                <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
                                    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="{{ $item['icon'] }}" />
                                </svg>
                                {{ $item['label'] }}
                                {{-- A badge with a 'live' URL is kept up to date while the page is open (admin-badges.js). --}}
                                @if (($item['badge'] ?? 0) > 0 || isset($item['live']))
                                    <span @if (isset($item['live'])) data-live-badge="{{ $item['live'] }}" @endif @if (($item['badge'] ?? 0) <= 0) hidden @endif
                                        class="ml-auto rounded-full bg-secondary px-2 py-0.5 text-xs font-bold text-primary-hover tabular-nums">
                                        <span data-live-badge-count>{{ $item['badge'] ?? 0 }}</span><span class="sr-only"> {{ $item['badgeLabel'] ?? '' }}</span>
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    {{-- The landing page is for guests only (signed-in users are redirected), so link the public map, help and sign-out instead. --}}
    <div class="grid gap-1 border-t border-white/10 p-3">
        @if ($showsMapLink)
            <a href="{{ route('navigate') }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition hover:bg-white/5 hover:text-white">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M9 4 3 6.5V20l6-2.5 6 2.5 6-2.5V4l-6 2.5zm0 0v13.5m6-11V20" />
                </svg>
                Campus map
            </a>
        @endif
        @if ($helpRoute)
            @php($onHelp = request()->routeIs($helpRoute))
            <a href="{{ route($helpRoute) }}" @if ($onHelp) aria-current="page" @endif
                @class([
                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition',
                    'bg-white/10 font-semibold text-white shadow-[inset_3px_0_0_var(--color-secondary)]' => $onHelp,
                    'hover:bg-white/5 hover:text-white' => ! $onHelp,
                ])>
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM9.5 9.5a2.5 2.5 0 1 1 3.4 2.3c-.6.3-.9.8-.9 1.4v.3M12 17h.01" />
                </svg>
                Help &amp; contact
            </a>
        @endif
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-sm transition hover:bg-white/5 hover:text-white">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                    stroke-linejoin="round" aria-hidden="true">
                    <path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5m5 5H4" />
                </svg>
                Sign out
            </button>
        </form>
    </div>
</aside>
