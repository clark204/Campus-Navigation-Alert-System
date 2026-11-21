{{-- <head> tags every layout shares: the web app manifest and icons (CNAS can be installed, which iPhones need for push),
     the CSRF token for fetch() calls, and the push settings read by resources/js/alerts. --}}
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="manifest" href="{{ asset('manifest.json') }}">
<link rel="icon" href="{{ asset('logo/nav-logo.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
<meta name="apple-mobile-web-app-title" content="CNAS">
<meta name="mobile-web-app-capable" content="yes">
<meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
{{-- Who this browser's push subscription belongs to; it is saved again when this changes (e.g. after signing in). --}}
<meta name="push-subscriber" content="{{ auth()->check() ? 'user:'.auth()->id() : 'guest' }}">
