{{-- Portal layout shared by students and faculty, on the same shell as the admin console: sidebar navigation + top bar,
     off-canvas sidebar below lg. Needs $role (App\Enums\UserRole) from the route: the portal shown, which for staff
     is the faculty portal, so it can differ from the signed-in user's own role. --}}
@use('App\Enums\UserRole')
@php
    $sidebarId = 'portal-sidebar';
    $everyone = [UserRole::Student, UserRole::Faculty];
    $groups = collect([
        null => [
            ['label' => 'Home', 'route' => "{$role->value}.dashboard", 'roles' => $everyone, 'icon' => 'M4 11l8-7 8 7v9a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z'],
            ['label' => 'My schedule', 'route' => 'student.schedule.index', 'active' => 'student.schedule.*', 'roles' => [UserRole::Student], 'icon' => 'M7 3v3m10-3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z'],
            ['label' => 'Campus map', 'route' => 'navigate', 'roles' => $everyone, 'icon' => 'M9 4 3 6.5V20l6-2.5 6 2.5 6-2.5V4l-6 2.5zm0 0v13.5m6-11V20'],
        ],
        'Places' => [
            ['label' => 'Saved places', 'route' => "{$role->value}.saved", 'roles' => $everyone, 'icon' => 'M6 4h12v17l-6-4-6 4z'],
        ],
        // Faculty only: staff share this portal but don't teach.
        'Teaching' => [
            ['label' => 'My classes', 'route' => 'faculty.classes.index', 'active' => 'faculty.classes.*', 'roles' => [UserRole::Faculty], 'when' => request()->user()->role === UserRole::Faculty, 'icon' => 'M4 6.5A2.5 2.5 0 0 1 6.5 4H20v14H6.5A2.5 2.5 0 0 0 4 20.5zm0 0v14M8 8h8'],
        ],
        // Only for faculty and staff the admin trusts to send alerts (Admin > Accounts).
        'Safety' => [
            ['label' => 'Emergency alerts', 'route' => 'faculty.alerts.index', 'active' => 'faculty.alerts.*', 'roles' => [UserRole::Faculty], 'when' => request()->user()->canSendAlerts(), 'icon' => 'M12 3 2 20h20zM12 10v4m0 3h.01'],
        ],
        'Account' => [
            // Profile, email and password, and emergency alerts are tabs of Settings (also in the top bar's account menu).
            ['label' => 'Settings', 'route' => "{$role->value}.settings", 'roles' => $everyone, 'icon' => 'M4 6h9m4 0h3M4 12h3m4 0h9M4 18h11m4 0h1M15 4v4M9 10v4m8 2v4'],
        ],
    ])
        ->map(fn (array $items): array => array_values(array_filter($items, fn (array $item): bool => in_array($role, $item['roles'], true) && ($item['when'] ?? true))))
        ->filter()
        ->all();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#581825">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Home') · TCC NAV</title>
    @include('partials.app-head')
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/alerts/index.js'])
    @stack('styles.portal')
</head>

<body class="bg-bg-light font-sans text-text-primary antialiased">
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-4">Skip to content</a>

    @include('partials.alerts')

    <div class="min-h-svh lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]">
        @include('partials.app-sidebar', [
            'navLabel' => 'Portal navigation',
            'homeRoute' => "{$role->value}.dashboard",
            'subtitle' => ucfirst($role->value).' portal',
            'helpRoute' => "{$role->value}.help",
        ])

        <div class="flex min-w-0 flex-col">
            {{-- No top bar search: rooms are found on the campus map. --}}
            @include('partials.app-topbar', ['search' => null])

            <main id="main-content" class="flex-1 px-4 py-6 sm:px-8 sm:py-8 xl:px-10">
                @yield('content.portal')
            </main>
        </div>
    </div>

    @include('partials.toasts')

    @stack('scripts.portal')
</body>

</html>
