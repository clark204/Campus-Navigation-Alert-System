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
- Delivery: full-screen takeover with alarm sound when CNAS is open, red banner after that, browser push when CNAS is closed.
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

## Not built yet (planned)
- Staff alert requests that wait for admin approval (admin-marked trusted faculty and staff who send directly are built: see accounts.md)
- "I need help" answer with a location note, and the admin live view of answers
Check the code before assuming any of these exist.

## Things that break it
- Admin → Settings → System status checks the push keys and HTTPS and shows a live subscriber count (`docs/settings.md`). It does not check the queue worker.
- A queue worker must be running or no phone gets a push. See README.md (also covers OPENSSL_CONF on Windows/Laragon and VAPID keys).
- Push and the service worker need HTTPS. iPhone only gets push after Add to Home Screen.
- Browsers block sound until the user taps once ("Enable emergency sound" prompt).
- Never regenerate the VAPID keys.

## Tests
`tests/Feature/Admin/AlertTest.php`, `AlertBannerTest.php`, `AlertSeverityTest.php` (each level), `SafetyCheckTest.php`, `SendAlertPushTest.php`, `PushSubscriptionTest.php`, `tests/js/alerts.test.js` (the level rules), `tests/js/evacuation.test.js`
