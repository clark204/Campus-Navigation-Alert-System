{{-- Emergency alerts on every page, guests included (every layout includes this right after <body>):
     the banner, the full-screen alarm takeover and the one-time "Enable emergency sound" prompt.
     From the view composer in AppServiceProvider: $alert (Alert::current(): active, recent all clear, or null),
     $canMarkSafe (signed in), $markedSafe and $askedForHelp (this user's answer for the active alert).
     resources/js/alerts polls api/alerts/active every 15 seconds and refills the data-field elements.
     $soundPrompt: false where the page has its own sound flow (the emergency map); $compactBanner: true there too.
     $hideResolved: true in the admin console: no green banner there (the alert history shows it was resolved).
     $takeover: false in the admin console, where admins send and manage alerts (they get the banner only, so no
     sound prompt either). The warning popup comes with the takeover. --}}
@use('App\Http\Resources\AlertResource')
<div data-alerts
    data-poll-url="{{ route('api.alerts.active') }}"
    data-safe-url="{{ route('alerts.safe', ['alert' => '__ALERT__']) }}"
    data-help-url="{{ route('alerts.help', ['alert' => '__ALERT__']) }}"
    data-push-url="{{ route('push-subscriptions.store') }}"
    data-alarm-sound="{{ asset('sounds/alarm.wav') }}"
    data-drill-sound="{{ asset('sounds/drill.wav') }}"
    data-chime-sound="{{ asset('sounds/chime.wav') }}"
    data-sw-url="{{ asset('sw.js') }}"
    @if ($hideResolved ?? false) data-hide-resolved @endif>
    <script type="application/json" data-alert-initial>@json($alert ? (new AlertResource($alert))->resolve() : null)</script>

    @include('partials.alert-banner', ['alert' => ($hideResolved ?? false) && $alert?->isActive() === false ? null : $alert])
    @if ($takeover ?? true)
        @include('partials.alert-takeover')
        @include('partials.alert-popup')
    @endif
    @if ($soundPrompt ?? true)
        @include('partials.alert-sound-prompt')
    @endif
</div>
