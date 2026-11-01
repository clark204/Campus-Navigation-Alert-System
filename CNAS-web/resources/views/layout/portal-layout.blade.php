{{-- Portal layout shared by students and faculty: slim top bar, plus a bottom tab bar on phones (the tabs move into the top bar from md).
     Needs $role (App\Enums\UserRole) from the route and a placeholder $person from the page until auth exists. --}}
@use('App\Enums\UserRole')
@php
    $everyone = [UserRole::Student, UserRole::Faculty];
    $portalTabs = collect([
        ['label' => 'Home', 'route' => "{$role->value}.dashboard", 'roles' => $everyone, 'icon' => 'M4 11l8-7 8 7v9a1 1 0 0 1-1 1h-4v-6H9v6H5a1 1 0 0 1-1-1z'],
        ['label' => 'Map', 'route' => 'navigate', 'roles' => $everyone, 'icon' => 'M9 4 3 6.5V20l6-2.5 6 2.5 6-2.5V4l-6 2.5zm0 0v13.5m6-11V20'],
        ['label' => 'Classes', 'route' => "{$role->value}.classes", 'roles' => [UserRole::Faculty], 'icon' => 'M4 6.5A2.5 2.5 0 0 1 6.5 4H20v14H6.5A2.5 2.5 0 0 0 4 20.5zm0 0v14M8 8h8'],
        ['label' => 'Saved', 'route' => "{$role->value}.saved", 'roles' => $everyone, 'icon' => 'M6 4h12v17l-6-4-6 4z'],
        // TODO: the Profile page gets an "Edit name" form with the registration re-split rules (see RegistrationService::ensureNameMatchesCor).
        ['label' => 'Profile', 'route' => "{$role->value}.profile", 'roles' => $everyone, 'icon' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm-7 9a7 7 0 0 1 14 0'],
    ])->filter(fn (array $tab): bool => in_array($role, $tab['roles'], true))->values()->all();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#581825">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Home') · TCC NAV</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles.portal')
</head>

<body class="bg-bg-light font-sans text-text-primary antialiased">
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-4">Skip to content</a>

    @include('partials.portal-header', ['tabs' => $portalTabs])

    {{-- Bottom padding keeps the last card clear of the phone tab bar and the home indicator. --}}
    <main id="main-content"
        class="px-4 pt-6 pb-[calc(6rem+env(safe-area-inset-bottom))] sm:px-8 md:pt-8 md:pb-12 xl:px-10">
        @yield('content.portal')

        <div class="mx-auto max-w-6xl">
            @include('partials.help-footer')
        </div>
    </main>

    @include('partials.portal-tabbar', ['tabs' => $portalTabs])

    @stack('scripts.portal')
</body>

</html>
