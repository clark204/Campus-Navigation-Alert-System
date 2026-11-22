# Emergency alerts

Read this before touching alerts, push notifications, or the "I'm safe" check.

## Decisions
- Types: fire, earthquake, flood, typhoon, security, other. Each type has a default level (fire, earthquake, security: emergency; flood, typhoon: warning; other: advisory), preselected on the send form.
- Three levels (`AlertSeverity`, `alerts.severity`). What people get:
  - advisory: a yellow banner only. No takeover, alarm, vibration, "I'm safe" or route to safety, and the emergency map doesn't change. A normal push.
  - warning: an orange banner, a one-time popup with the message and instructions, and a short chime (`sounds/chime.wav`). No looping alarm and no "I'm safe". "Show route to safety" only when the alert has a location (a pin or affected buildings). A normal push.
  - emergency: the red takeover, looping alarm, vibration, "I'm safe", route to safety, and a push that waits for a tap.
- Drills (`is_drill`) work at every level and say DRILL.
- Changing the level of an active alert is logged (`alert_logs` action `severity`, with detail like "Warning → Emergency") and pushed again. The same tag on the phone replaces the old notification.
- `GET /api/alerts/active` also sends `severity`, `severity_label` and `has_location`.
- Delivery: full-screen takeover with alarm sound when CNAS is open, red banner after that, browser push when CNAS is closed, and phone push to the CNAS app (see "Phone push").
- Admin marks the incident on the map (pin + danger radius + affected buildings). Whole-campus alerts have no pin.
- Drills (`is_drill`) show "DRILL" and use a softer sound.
- An alert ends as "All clear" or "False alarm" (`AlertResolution`).
- Signed-in users can tap "I'm safe" during an emergency only (the endpoint refuses other levels). Answers are admin-only and deleted 30 days after the alert ends.
- Everything is logged in `alert_logs`.

## Built
- Admin: send, edit wording, resolve, history (`app/Http/Controllers/Admin/AlertController.php`, views `admin/alert*-page.blade.php`, map pin picker `resources/js/alert-picker.js`)
- Public: `GET /api/alerts/active` (`Api/ActiveAlertController`, `AlertResource`), polled by open pages
- Front end: `resources/js/alerts/` (takeover, alarm, banner state, push, storage), partials `resources/views/partials/alert-*.blade.php`
- Push: `laravel-notification-channels/webpush`, `App\Jobs\SendAlertPush`, `AlertPushNotification`, `PushSubscriptionController`, `public/sw.js`, `public/manifest.json`
- "I'm safe": `SafetyCheckController`, `safety_checks` table (one answer per user per alert). Web: `POST /alerts/{alert}/safe` (session). Mobile app: `POST /api/alerts/{alert}/safe` (Sanctum, same controller, throttle 10 a minute) → 200 `{status: "safe"}`, 409 `{message}` "This alert is over." or "Only an emergency asks whether you are safe.". Unverified users can answer on both.
- Levels: `App\Enums\AlertSeverity` (label, color, `description()` for when to choose it, `example()`, and `studentsGet()` for what students receive; the admin forms use these, see `admin/severity-cards`), `AlertType::defaultSeverity()`, the level picker on the send and edit forms (`admin/alert-send-page`, `alert-edit-page`), the level badge (`admin/severity-badge`). Front end: `alerts/popup.js` (the warning popup, `partials/alert-popup`), `alerts/takeover-state.js` (`shouldTakeOver`, `shouldPopUp`), `alerts/banner-state.js` (`showsSafeButton`, `showsRouteToSafety`), the banner colors in `partials/alert-banner`.
- Route to safety: `resources/js/navigation/evacuation.js` (blocks paths inside the danger zone, routes to the nearest evacuation area)
- "Already answered" for the app: `GET /api/alerts/active` adds `data.my_safety_status` (`"safe"` or null) when the request has a bearer token. The alert still comes from the shared cache (`Alert::current`). The token is only checked during an active emergency, so ordinary polls cost nothing extra (a bad token then gets null, like a guest). Guests never get the key. The response stays `no-store` (this route has no ETag).

## Phone push (CNAS app, built 2026-11-22)
- **Tokens**: `device_push_tokens` (`DevicePushToken`): `expo_token` (unique, `ExponentPushToken[…]` or `ExpoPushToken[…]`), `user_id` (nullable, null on account delete), `platform` (android/ios), `device_name`, `last_seen_at`. `Api\PushTokenController`:
  - `POST /api/push-tokens {expo_token, platform, device_name?}` is public. The app sends it on every start and after sign-in. A valid bearer token links the phone to that account (`$request->user('sanctum')`, no auth middleware); a guest or bad token never unlinks a linked phone (same rule as web push). It answers `{subscribed, linked}`.
  - `DELETE /api/push-tokens {expo_token}` is "Turn off alerts" in the app.
  - Throttle `push-tokens`: 10 a minute per token, 300 per IP (the campus shares one IP).
  - `POST /api/logout {expo_token?}` unlinks only that phone from the account; it keeps getting alerts as a guest.
- **Sending**: `Alert::pushToDevices()` (send, level change, resolve) dispatches `SendAlertPush` (browsers) and `FanOutExpoAlertPush`, which queues one `SendExpoAlertPush` job per 100 tokens (Expo's batch limit; a retry only resends its own batch, #15 in deploy-review). `App\Services\ExpoPush` posts to `https://exp.host/--/api/v2/push/send` with the Laravel Http client (no package). It adds `Authorization: Bearer EXPO_ACCESS_TOKEN` when that is set; it is only needed if "Enhanced push security" is on at expo.dev. A 429 or 5xx throws, so the batch retries (3 tries, 10 then 30 s).
- **Message** (`ExpoPush::message`): title and body are the web push's (`AlertPushNotification::title()`/`body()`).
  - data: `{alert_id, screen: "alert"}`.
  - tag and collapseId: `cnas-alert-{id}` (`AlertPushNotification::tag()`), so the all clear replaces the alert on the phone. Android replaces by `tag`; `collapseId` covers iOS.
  - TTL: 1 h.
  - Channels and sounds (the app creates the channels):
    - a sent emergency: `emergency` channel, `alarm.wav`, priority high
    - any drill (sent or over): `drill` channel, `drill.wav` (high when it is an emergency drill)
    - warnings, advisories and every "all clear" or "false alarm": `warning` channel, `chime.wav`, priority default
- **Dead tokens**: a ticket with `DeviceNotRegistered` deletes the token at once. Ok tickets get `CheckExpoPushReceipts` 15 minutes later (Expo's advice), which deletes tokens whose receipt says `DeviceNotRegistered`. Other receipt errors are ignored.
- System status shows "App push (Expo)": never red, it only says whether `EXPO_ACCESS_TOKEN` is set. "Push subscribers" counts browsers and phones.

## Not built yet (planned)
- Staff alert requests that wait for admin approval (admin-marked trusted faculty and staff who send directly are built: see accounts.md)
- "I need help" answer with a location note, and the admin live view of answers
Check the code before assuming any of these exist.

## Things that break it
- Admin → Settings → System status checks the push keys and HTTPS and shows a live subscriber count (`docs/settings.md`). It does not check the queue worker.
- A queue worker must be running or no browser or phone gets a push. Receipt checks run 15 minutes later on the same queue.
- Phone push needs the app as a development or preview build with FCM set up (CNAS-mobile/README.md). Expo Go on Android gets no push. See README.md (also covers OPENSSL_CONF on Windows/Laragon and VAPID keys).
- Push and the service worker need HTTPS. iPhone only gets push after Add to Home Screen.
- Browsers block sound until the user taps once ("Enable emergency sound" prompt).
- Never regenerate the VAPID keys.

## Tests
`tests/Feature/Admin/AlertTest.php`, `AlertBannerTest.php`, `AlertSeverityTest.php` (each level), `SafetyCheckTest.php` (also `my_safety_status`), `SendAlertPushTest.php`, `PushSubscriptionTest.php`, `ExpoAlertPushTest.php` (phone push, Http faked), `DevicePushTokenTest.php`, `tests/js/alerts.test.js` (the level rules), `tests/js/evacuation.test.js`
