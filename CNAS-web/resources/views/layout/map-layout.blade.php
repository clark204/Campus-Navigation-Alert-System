{{-- Full-screen app layout for map pages: no site header or footer, no page scroll. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#581825">
    <meta name="description" content="@yield('description', 'Walking directions around Tagoloan Community College.')">
    <title>@yield('title', 'TCC NAV')</title>
    @include('partials.app-head')
    @vite(['resources/css/app.css', 'resources/js/alerts/index.js'])
    @stack('scripts.map')
</head>

<body class="overflow-hidden bg-bg-light font-sans text-text-primary antialiased">
    @yield('content.map')

    {{-- The banner covers the map's top bar while an alert is up. No sound prompt: "Show route to safety" is the tap
         that turns sound on here. --}}
    <div class="fixed inset-x-0 top-0 z-50 pt-[env(safe-area-inset-top)]">
        @include('partials.alerts', ['soundPrompt' => false, 'compactBanner' => true])
    </div>
</body>

</html>
