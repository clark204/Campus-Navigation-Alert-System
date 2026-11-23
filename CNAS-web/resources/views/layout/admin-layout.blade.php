{{-- Admin console layout: sidebar navigation + top bar. Off-canvas sidebar below lg. The portal layout shares this shell. --}}
@use('App\Services\ConcernInbox')
@php
    $sidebarId = 'admin-sidebar';
    $groups = [
        null => [
            ['label' => 'Overview', 'route' => 'admin.dashboard', 'icon' => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z'],
            ['label' => 'Emergency alerts', 'route' => 'admin.alerts.index', 'icon' => 'M12 3 2 20h20zM12 10v4m0 3h.01'],
        ],
        'Campus map' => [
            ['label' => 'Map data', 'route' => 'admin.map.index', 'active' => ['admin.map.*'], 'icon' => 'M12 3v12m0 0-4-4m4 4 4-4M4 17v3h16v-3'],
            ['label' => 'Buildings', 'route' => 'admin.buildings.index', 'active' => ['admin.buildings.*'], 'icon' => 'M4 21V5l8-3 8 3v16M9 21v-5h6v5M8 9h1m6 0h1M8 13h1m6 0h1'],
            ['label' => 'Rooms', 'route' => 'admin.rooms.index', 'active' => ['admin.rooms.*'], 'icon' => 'M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12zm0-9.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z'],
            ['label' => '360° photos', 'route' => 'admin.panoramas.index', 'active' => ['admin.panoramas.*'], 'icon' => 'M4 8h3l2-3h6l2 3h3v11H4zm8 8a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z'],
        ],
        'People' => [
            ['label' => 'Concerns', 'route' => 'admin.concerns.index', 'active' => ['admin.concerns.*'], 'badge' => app(ConcernInbox::class)->openCount(), 'badgeLabel' => 'open', 'live' => route('admin.concerns.count'), 'icon' => 'M4 5h16v11H8l-4 4zm4 4h8m-8 3h5'],
            ['label' => 'Accounts', 'route' => 'admin.accounts.index', 'active' => ['admin.accounts.*'], 'icon' => 'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20m6-9a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zm10 9v-1.5a3.5 3.5 0 0 0-2.5-3.35m-2-11a3.5 3.5 0 0 1 0 6.7'],
        ],
        'Site' => [
            ['label' => 'Settings', 'route' => 'admin.settings', 'icon' => 'M4 6h9m4 0h3M4 12h3m4 0h9M4 18h11m4 0h1M15 4v4M9 10v4m8 2v4'],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#581825">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Admin') · TCC NAV</title>
    @include('partials.app-head')
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/alerts/index.js'])
    @stack('styles.admin')
</head>

<body class="bg-bg-light font-sans text-text-primary antialiased">
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-4">Skip to content</a>

    @include('partials.alerts', ['takeover' => false, 'soundPrompt' => false, 'hideResolved' => true])

    <div class="min-h-svh lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]">
        @include('partials.app-sidebar', [
            'navLabel' => 'Admin navigation',
            'homeRoute' => 'admin.dashboard',
            'subtitle' => 'Admin console',
            'helpRoute' => null,
        ])

        <div class="flex min-w-0 flex-col">
            @include('partials.app-topbar', [
                'search' => [
                    'id' => 'admin-search', 'label' => 'Search the admin console', 'placeholder' => 'Search buildings, rooms, people',
                    'action' => route('admin.search'), 'suggestUrl' => route('admin.search.suggestions'),
                ],
            ])

            <main id="main-content" class="flex-1 px-4 py-6 sm:px-8 sm:py-8 xl:px-10">
                @yield('content.admin')
            </main>
        </div>
    </div>

    @include('partials.toasts')

    @stack('scripts.admin')
</body>

</html>
