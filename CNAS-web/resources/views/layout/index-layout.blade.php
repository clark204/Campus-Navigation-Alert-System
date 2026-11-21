<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#581825">
    <meta name="description" content="@yield('description', 'Get to know Tagoloan Community College and find your way around campus with TCC NAV.')">
    <title>@yield('title', 'TCC NAV')</title>
    @include('partials.app-head')
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/alerts/index.js'])
    @stack('styles.index')
</head>

<body id="top" class="bg-bg-light font-sans text-text-primary antialiased">
    <a href="#main-content"
        class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-4">Skip to content</a>

    @include('partials.alerts')

    @include('partials.site-header')

    <main id="main-content">
        @yield('content.index')
    </main>

    @include('partials.site-footer')

    @include('partials.toasts')

    @stack('scripts.index')
</body>

</html>
