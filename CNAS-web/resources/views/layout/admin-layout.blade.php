{{-- Admin console layout: sidebar navigation + top bar. Off-canvas sidebar below lg. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#581825">
    <meta name="robots" content="noindex">
    <title>@yield('title', 'Admin') · TCC NAV</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles.admin')
</head>

<body class="bg-bg-light font-sans text-text-primary antialiased">
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-4">Skip to content</a>

    <div class="min-h-svh lg:grid lg:grid-cols-[16rem_minmax(0,1fr)]">
        @include('partials.admin-sidebar')

        <div class="flex min-w-0 flex-col">
            @include('partials.admin-topbar')

            <main id="main-content" class="flex-1 px-4 py-6 sm:px-8 sm:py-8 xl:px-10">
                @yield('content.admin')
            </main>
        </div>
    </div>

    @stack('scripts.admin')
</body>

</html>
