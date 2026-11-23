# Emergency alerts

Read this before touching alerts, push notifications, or the "I'm safe" check.

## Decisions
- Types: fire, earthquake, flood, typhoon, security, other. Each type has a default level (fire, earthquake, security: emergency; flood, typhoon: warning; other: advisory), preselected on the send form.
- Three levels (`AlertSeverity`, `alerts.severity`). What people get:
  - advisory: a yellow banner on the web, a yellow toast that closes by itself in the app. No takeover, alarm, vibration, "I'm safe" or route to safety, and the emergency map doesn't change. A normal push.
  - warning: on the web an orange banner and a one-time popup; in the app an orange toast that stays until closed. Both chime (`sounds/chime.wav`). No looping alarm and no "I'm safe". "Show route to safety" only when the alert has a location (a pin or affected buildings). A normal push.
  - emergency: the red takeover, looping alarm, vibration, "I'm safe", route to safety, and a push that waits for a tap.
- Drills (`is_drill`) work at every level and say DRILL.
- Changing the level of an active alert is logged (`alert_logs` action `severity`, with detail like "Warning → Emergency") and pushed again. The same tag on the phone replaces the old notification.
- `GET /api/alerts/active` also sends `severity`, `severity_label` and `has_location`.
- Delivery: full-screen takeover with alarm sound when CNAS is open, red banner after that, browser push when CNAS is closed, and phone push to the CNAS app (see "Phone push").
- Admin marks the incident on the map (pin + danger radius + affected buildings). Whole-campus alerts have no pin.
- Drills (`is_drill`) show "DRILL" and use a softer sound.
- An alert ends as "All clear" or "False alarm" (`AlertResolution`).
- Signed-in users can tap "I'm safe" during an emergency only (the endpoint refuses other levels). Answers are admin-only and deleted 30 days after the alert ends.
- Who sees the answer buttons: every signed-in account, any role, during an active emergency (the view composer's `canMarkSafe`; the app shows them whenever someone is signed in, since only an emergency takes over). Admins and trusted staff can answer for themselves too: on the web through the banner in the admin console (the console has no takeover, since that is where alerts are sent from), in the app on the takeover like everyone else. The live view and its counts leave admins out (`SafetyCheckSummary`). Guests get "Sign in to tell the school you're safe" instead.

## Wording: quick to read (built 2026-11-23)
- The takeover shows only what someone needs in three seconds: the kind ("EMERGENCY · FIRE" or "DRILL · FIRE"), the title (60 characters at most, two lines with an ellipsis), the location (one line), and one bold "What to do now" line. The full message, longer instructions and the time sent are under "More details", which scrolls on its own. The answers are pinned to the bottom and never scroll away (360 px phones, large text): "I'm safe" (white), "I need help" (outlined), then "Show route to safety" (web; the app has no evacuation route yet), then Dismiss.
- `alerts.what_to_do` (migration `2026_11_23_102332`, max 100, `Alert::WHAT_TO_DO_MAX`), required for an emergency (`required_if:severity,emergency`), optional otherwise. Title max `Alert::TITLE_MAX` (60). Both in `UpdateAlertRequest::wordingRules()`, so the send and edit forms on the web and in the app share them. `Alert::whatToDo()` falls back to the first sentence of the instructions for older alerts (cut to 100); `AlertResource` sends that as `what_to_do`.
- The type templates (`AlertType::template()`) are short and plain and fill "What to do now" ("Leave the building now. Go to the open field.", "Drop, cover and hold on. Leave when the shaking stops.", …).
- The forms: `admin/alert-wording-fields` (counters, "Under More details") with `admin/alert-phone-preview` (the takeover for an emergency, the toast otherwise), run by `resources/js/alert-wording.js`. App: `components/alert-admin/wording.tsx` (`WordingFields`, `PhonePreview`, on the send form's confirm step and Edit alert); `GET /api/alerts/form` sends `limits`.
- Push: the title is the kind plus the alert's title ("EMERGENCY · FIRE: Fire on campus"), the body the location and "What to do now" (`AlertPushNotification::title()`, `kind()`, `body()`; shared with the phone push).

## Nothing moves when an alert arrives (built 2026-11-23)
- Web: a banner that was on the page at first render keeps its place; one a poll brings in later gets `data-floating` (fixed over the top, short lines only) so the page under it never jumps (`alerts/index.js`, `partials/alert-banner`).
- App: no banner any more. Warnings, advisories and the end of an alert are toasts (`components/alert-toasts.tsx`, `lib/toasts.tsx`, `components/toast-host.tsx`), once per alert version; tapping one opens the Alerts screen. After the takeover is dismissed, a red pill under the status bar ("EMERGENCY · FIRE ›", `EmergencyPill` in `components/alert-overlays.tsx`) floats over every screen until the alert ends and reopens the takeover without the alarm. Navigation moves its instruction card and search row below the pill (`usePillSpace`). Toasts hide (and close) while the takeover is open.
- App Alerts screen (`components/alerts-screen.tsx`, routed as `campus-alerts` inside each area: guests, `portal/`, `admin/`; a root screen open to signed-in people would win the redirect after signing in): the current alert in full (with the answers during an emergency) and every alert of the last 30 days from `GET /api/alerts/recent` (`Api\RecentAlertsController`, public, 20 at most, no admin names). Linked from Welcome ("Campus alerts") and portal Home ("Alerts").
- Everything is logged in `alert_logs`.

## Built
- **Admin alerts presentation** (`admin/alerts-page`): full-width incident workspace, the shared resolve form and live safety panel, then (emergency only) the help workspace, then History. See `docs/agent-work-log.md` for coordinated work/checks.
- **History** (`admin/alert-history-table`, `AlertController::index`): a GET filter form (`q` title/message, `type`, `severity`, `kind=real|drill`, `month=YYYY-MM` in campus time; invalid values are ignored), 25 a page, `withQueryString`. One table row per alert (two-line items on phones): sent date and time, title, type, Drill, level, outcome (Active now / All clear / False alarm), how many asked for help (`withCount`, no N+1) and how long it lasted (`Alert::lastedLabel`). The whole row links to the report.
- **Report** (`GET /admin/alerts/{alert}`, `AlertController::show`, `admin/alert-report-page`): Sent / Ended / Lasted / Answers, what people saw, the activity (`AlertLogAction::describe`), and for an emergency the still help map with every request and how it was handled (`admin/alert-help-report-list`). After 30 days the answers are gone and it says so.
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

## "I need help" (built 2026-11-23)
- During an active emergency only (`Alert::safetyCheckRefusal`, the same rule and 409 messages as "I'm safe"), signed-in users answer "I'm safe" or "I need help" with an optional note (`NeedHelpRequest`, 200 characters). One answer per user and alert: the latest replaces the earlier (`SafetyCheck::markSafe` clears the note, `markNeedHelp`). `SafetyStatus::NeedHelp` = `need_help`; `safety_checks.note` (migration `2026_11_23_063659`), deleted with the answer after 30 days.
- Web: `POST /alerts/{alert}/help` (`NeedHelpController`), "I need help" under "I'm safe" on the takeover and banner (`partials/alert-safe-button`: a `<details>` with the note; `alerts/index.js` posts it in the background). The view composer passes `markedSafe` and `askedForHelp`.
- App: `POST /api/alerts/{alert}/help`, `NeedHelpButton` (`components/alert-parts.tsx`) on the takeover and banner. `GET /api/alerts/active` `my_safety_status` is `safe`, `need_help` or null.
- Every "I need help" pushes a report to the admins' phones (`AdminReport::needsHelp`, settings.md "Reports").

## Help requests: location and handling (built 2026-11-23)
- Each "I need help" is a request: New → On the way → Helped (`HelpStatus`, worked out from `safety_checks.responder_id` / `helped_at` in `SafetyCheck::helpStatus`, not stored). Migration `2026_11_23_144338` adds `latitude`, `longitude`, `accuracy_m`, `located_at`, `responder_id`, `responding_at`, `helped_by`, `helped_at`. Only the latest position is kept (no trail). Answering again resets it all (`HELP_RESET`): "I'm safe" closes the request and forgets the position; a new "I need help" reopens it. Deleted with the answer after 30 days.
- Location is opt-in: "Share my location with the responders" (ticked by default) on the web form and in the app. `NeedHelpRequest` takes optional `latitude`, `longitude`, `accuracy` (≤ 5000 m); `HelpPingRequest` takes the same.
- While the request is open the person's page or app checks in every 15 s: `POST /alerts/{alert}/help/ping` (web) or `/api/alerts/{alert}/help/ping` (`HelpPingController`, throttle 12/min) with the latest position (or none) → `{help: {status, label, responder_name}}`. 409 when there is nothing to share (never asked, helped, "I'm safe", alert over) and the client stops. Web: `resources/js/alerts/help-tracker.js` (`watchPosition`, only while the page is open; a reopened page resumes if they shared before, `cnas.alerts.helpShare`). App: `src/lib/help-tracker.ts`. `GET /api/alerts/active` also has `data.my_help` for the app.
- What the person sees: "Sent. The administrators can see your request." (`NeedHelpController::THANKS`; not "on the way" until someone responds), "<admin> is on the way.", "Marked as helped. Stay safe." In a `data-help-state` line under the buttons.
- Admin: `HelpRequestController` `respond` / `helped` (`POST /admin/alerts/{alert}/help/{safetyCheck}/respond|helped`, and `/api/alerts/...` for the app; admin only, `scopeBindings` so the check must belong to the alert, 404 for an "I'm safe" answer, 409 when the alert is over or already helped). Both are logged (`AlertLogAction::HelpResponding`, `HelpResolved`, detail = the person's name). Responding pushes "<admin> is on the way" to the person's phones (`SendHelpUpdatePush`, `ExpoPush::helpUpdateMessage`, warnings channel).
- Web workspace (`admin/alert-help-workspace`, `resources/js/alerts/help-map.js`, pure helpers in `alerts/help-data.js`): MapLibre map with the incident pin and danger zone, a dot per open request (red pulsing waiting, amber on the way, faded after 60 s without a fix, accuracy circle), and the queue (`admin/alert-help-panel`, refreshed every 5 s by `alert-safety-live.js` `initLivePanel`; points in `data-help-points`). Cards: name, note, "In Library, 40 m from the incident" (worked out in the browser), live/last seen, Show on map, I'm responding / Take over, Mark helped. A new request beeps (Sound on/off toggle, `cnas.helpSound`), flashes its card and puts "(n) need help" in the tab title.
- App admin: the "Who is safe" response also has `help_requests[]` (`SafetyCheckSummary::helpRequestData`).

## "I'm safe" live view (built 2026-11-23)
- `App\Services\SafetyCheckSummary`: `counts()` = safe, need_help, total (every account except admins), not answered, and per college through the account's program (`NO_COLLEGE` = "none" for accounts without one, listed last); `people()` = who answered, "I need help" first (with the note), then newest first, one college or "none", at most 200 names. Admins are left out of both.
- Web: the active alert on Admin > Alerts shows "N need help" in red, the counts, college chips (`?college=`, a link with `#safety`) and the names (needs-help rows red with the note). The panel is its own partial (`admin/alert-safety-panel`) and is live: `resources/js/alert-safety-live.js` asks `admin.alerts.safety` (`GET /admin/alerts/{alert}/safety?college=`, admin only, `no-store`, 30 a minute) every 10 s while the tab is visible and swaps the panel in, so no reload is needed.
- App: `GET /api/alerts/{alert}/safety?college=` (admin only, `no-store`, people with `status` and `note`), asked every 15 s on the app's "Who is safe" screen and at once when a report push arrives.

## Mobile app (built 2026-11-23)
`Api\AlertManagementController`, behind `EnsureUserCanSendAlerts` (the admin and trusted faculty and staff), with the web's requests and Alert methods, so logging (`alert_logs`) and push are the same:
- `GET /api/alerts`: the active alert (`ManagedAlertResource`: AlertResource plus sent_by, resolved_by, and the log), the admin's `safety` counts, and the history (admin: 15 a page with logs; trusted: the last 5), `can_edit` (admin).
- `GET /api/alerts/form`: the types (level, `campus_wide`, template), the levels (description, example, students_get), radii, buildings, resolutions (with their default messages, drill or not).
- `POST /api/alerts` (`StoreAlertRequest`, `Alert::send`; 422 `form` when one is active), `PUT /api/alerts/{alert}` (admin, `UpdateAlertRequest`: revise + changeSeverity; 409 when it ended), `POST /api/alerts/{alert}/resolve` (`ResolveAlertRequest`; 409 when it ended).
- The incident pin helpers are shared: `resources/js/navigation/incident.js` (`buildingAt`, `locationLabel`), used by `alert-picker.js` and the app (`@nav/incident.js`).
- App screens: the admin Dashboard's active alert panel (live count, level, Edit wording, All clear, False alarm), `admin/send-alert` (type and level, drill, wording; map pin with the building under it ticked, 30/50/100 m or whole campus; "This will alert everyone"), `end-alert`, `edit-alert`, `safety`, `alert-history`. Trusted faculty and staff get the same send and end screens under `portal/alerts` (no edit, no live view), shown when `UserResource.can_send_alerts`.

## Not built yet (planned)
- Staff alert requests that wait for admin approval (admin-marked trusted faculty and staff who send directly are built: see accounts.md)
Check the code before assuming any of these exist.

## Things that break it
- Admin → Settings → System status checks the push keys and HTTPS and shows a live subscriber count (`docs/settings.md`). It does not check the queue worker.
- A queue worker must be running or no browser or phone gets a push. Receipt checks run 15 minutes later on the same queue.
- Phone push needs the app as a development or preview build with FCM set up (CNAS-mobile/README.md). Expo Go on Android gets no push. See README.md (also covers OPENSSL_CONF on Windows/Laragon and VAPID keys).
- Push and the service worker need HTTPS. iPhone only gets push after Add to Home Screen.
- Browsers block sound until the user taps once ("Enable emergency sound" prompt).
- Never regenerate the VAPID keys.

## Tests
`tests/Feature/AlertWordingTest.php` (limits, "What to do now", the fallback, the recent list), `tests/js/alert-wording.test.js`, `tests/Feature/Admin/AlertTest.php`, `AdminReportPushTest.php` ("I need help" and the admin push), `Api/AlertManagementApiTest.php` (the app's API and the live view), `tests/js/incident.test.js`, `AlertBannerTest.php`, `AlertSeverityTest.php` (each level), `SafetyCheckTest.php` (also `my_safety_status`), `SendAlertPushTest.php`, `PushSubscriptionTest.php`, `ExpoAlertPushTest.php` (phone push, Http faked), `DevicePushTokenTest.php`, `tests/js/alerts.test.js` (the level rules), `tests/js/evacuation.test.js`, `HelpRequestTest.php` (location, ping, respond/helped, app API), `Admin/AlertsPresentationTest.php` (History filters, report), `tests/js/help-requests.test.js`
