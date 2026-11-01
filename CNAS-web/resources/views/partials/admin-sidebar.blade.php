{{-- Admin sidebar. Links without a route yet fall back to '#'. Icons are 24×24 stroke paths. --}}
@php
    $groups = [
        null => [
            ['label' => 'Overview', 'route' => 'admin.dashboard', 'icon' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z'],
        ],
        'Campus map' => [
            ['label' => 'Buildings', 'route' => 'admin.buildings', 'icon' => 'M4 21V5l8-3 8 3v16M9 21v-5h6v5M8 9h1m6 0h1M8 13h1m6 0h1'],
            ['label' => 'Rooms & landmarks', 'route' => 'admin.landmarks', 'icon' => 'M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z'],
            ['label' => 'Paths & entrances', 'route' => 'admin.paths', 'icon' => 'M6 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM18 9a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM8 17h7a3 3 0 0 0 0-6H9a3 3 0 0 1 0-6h7'],
        ],
        'People' => [
            ['label' => 'Accounts', 'route' => 'admin.accounts', 'icon' => 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20m6-9a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm10 9v-1.5a3.5 3.5 0 0 0-2.5-3.35m-2-11a3.5 3.5 0 0 1 0 6.7'],
            ['label' => 'Colleges', 'route' => 'admin.colleges', 'icon' => 'M2 9l10-5 10 5-10 5zm4 2v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5m4-2v5'],
        ],
        'Site' => [
            ['label' => 'Settings', 'route' => 'admin.settings', 'icon' => 'M4 6h9m4 0h3M4 12h3m4 0h9M4 18h11m4 0h1M15 4v4M9 10v4m8 2v4'],
        ],
    ];
@endphp

<div data-admin-backdrop hidden class="fixed inset-0 z-30 bg-primary-hover/50 lg:hidden"></div>

<aside id="admin-sidebar" data-admin-sidebar
    class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-primary text-white/80 transition-transform duration-200 not-data-open:max-lg:invisible data-open:translate-x-0 lg:sticky lg:top-0 lg:h-svh lg:translate-x-0">
    <a href="{{ route('admin.dashboard') }}" class="flex h-16 shrink-0 items-center gap-1 border-b border-white/10 px-3">
        <img src="{{ asset('logo/nav-logo.svg') }}" alt="" class="size-14">
        <span class="-ml-2 flex flex-col leading-tight">
            <span class="font-bold tracking-wide text-secondary">TCC NAV</span>
            <span class="text-xs text-white/60">Admin console</span>
        </span>
    </a>

    <nav aria-label="Admin navigation" class="flex-1 overflow-y-auto px-3 py-5">
        @foreach ($groups as $heading => $items)
            <div @class(['mt-6' => ! $loop->first])>
                @if ($heading)
                    <p class="mb-2 px-3 text-xs font-medium text-white/45">{{ $heading }}</p>
                @endif
                <ul class="flex flex-col gap-0.5">
                    @foreach ($items as $item)
                        @php($active = request()->routeIs($item['route']))
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
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>

    <div class="border-t border-white/10 p-3">
        <a href="{{ route('landing') }}" class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition hover:bg-white/5 hover:text-white">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"
                stroke-linejoin="round" aria-hidden="true">
                <path d="M14 4h6v6m0-6-9 9m-2-8H5a1 1 0 0 0-1 1v13a1 1 0 0 0 1 1h13a1 1 0 0 0 1-1v-4" />
            </svg>
            View public site
        </a>
    </div>
</aside>
