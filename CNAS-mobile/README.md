# CNAS mobile

The phone app for CNAS (Campus Navigation & Alert System), Tagoloan Community College. Expo SDK 58, Expo Router,
TypeScript. It talks only to the CNAS-web API (`../CNAS-web/routes/api.php`).

It covers sign-in, COR registration, email verification, forgot password, in-app emergency alerts, the campus map and
walking navigation with voice (see "Navigation" below).

Everything except push notifications runs in Expo Go. Push alerts (the phone rings for an emergency while CNAS is
closed) need the development build or the preview APK: see "Push alerts" below. Navigation added native modules
(expo-location, expo-speech, expo-sensors, expo-camera, expo-keep-awake), so a development build made before it must
be built again.

## Setup

1. Install packages: `npm install` (add new ones with `npx expo install <package>`, never `npm install <package>`).
2. Copy `.env.example` to `.env` and set `EXPO_PUBLIC_API_URL` to the CNAS-web site, without `/api`:
   - local testing: `http://<your PC's LAN IP>:8000`, for example `http://192.168.1.2:8000`
   - production: `https://tcc-mapsafe.online`
3. Start the site so the phone can reach it (in `CNAS-web`):
   - `php artisan serve --host=0.0.0.0 --port=8000`
   - a queue worker (`php artisan queue:work`), or no verification or reset email is ever sent
   - Windows may ask to let PHP through the firewall: allow it on private networks.
4. Start the app: `npx expo start --go` for Expo Go (scan the QR code with Expo Go), or `npx expo start` for the
   development build (see "Push alerts"). The phone must be on the same Wi-Fi as the PC.

After changing `.env`, reload the app (press `r` in the Expo terminal).

Before finishing any change: `npx expo lint` and `npx tsc --noEmit`.

## Screens (`src/app`)

| Screen | File | API |
| --- | --- | --- |
| Welcome: Sign in, Create account, emergency numbers (tap to call), alert banner, mini-map | `index.tsx` | `GET /emergency-contacts` |
| Sign in with ID number or email | `sign-in.tsx` | `POST /login` (device name = phone model) |
| Create account: privacy notice and consent, COR upload, check details (name re-split only), email and password, then signs in | `register.tsx` | `POST /register/cor`, `POST /register`, `POST /login` |
| Verify email: 6-digit code, resend with countdown, "Skip for now" | `verify-email.tsx` | `POST /email/verify`, `POST /email/resend` |
| Forgot password: send code, enter code, new password | `forgot-password.tsx` | `POST /password/forgot`, `/password/verify-code`, `/password/reset` |
| Campus map for guests, with Back (signed in, it is the Map tab) | `map.tsx` | `GET /map` |
| Navigate: walking directions for guests and signed-in users (`?to=`, `?from=` place ids, like the web) | `navigate.tsx` (`components/navigation/`) | `GET /map` |
| **Admin area**: a Stack with the tabs (Dashboard, Map, Concerns, Accounts, Settings) and the pages they open. A tapped report push (new concern, "I need help") opens its screen (`lib/admin-reports.ts`) | `admin/_layout.tsx`, `admin/(tabs)/_layout.tsx` | |
| Settings (admin): Academic term (add, set as current with a confirmation), Emergency numbers (add, edit, delete, move, show or hide), Contact details, System status, My account; website links for map data, photos, QR codes. The dashboard shows the term warning card | `admin/(tabs)/settings.tsx`, `academic-term.tsx`, `emergency-contacts.tsx`, `contact-details.tsx`, `system-status.tsx`, `account.tsx` (`components/admin/settings.tsx`) | `/admin/academic-terms`, `/admin/emergency-contacts`, `/admin/contact-details`, `/admin/system-status` |
| Admin dashboard: the active alert panel (live "I'm safe" count, level, Edit wording, All clear, False alarm) or Send an alert; Concerns, Accounts, Alert history; website links for map data, photos, terms, emergency numbers, settings | `admin/(tabs)/index.tsx` (`components/alert-admin/`) | `GET /alerts` (every 10 s while open), `/admin/concerns` |
| Send an alert: type and level, drill, wording; map pin (building under it ticked), danger zone or whole campus; "This will alert everyone" | `admin/send-alert.tsx`, `portal/send-alert.tsx` | `GET /alerts/form`, `/map`, `POST /alerts` |
| End (All clear / False alarm), edit wording and level, Who is safe (every 5 s, by college), Alert history | `admin/end-alert.tsx`, `edit-alert.tsx`, `safety.tsx`, `alert-history.tsx` (`portal/` has end and history) | `/alerts/{id}/resolve`, `PUT /alerts/{id}`, `/alerts/{id}/safety` |
| Concerns tab (Open/Resolved, new in bold, badge) and one concern (reply, Mark as resolved, Reopen) | `admin/(tabs)/concerns.tsx`, `admin/concern.tsx` (`components/admin/concerns.tsx`) | `/admin/concerns…` |
| Accounts tab (search, role and "Can send alerts" filters), one account (trust, faculty or staff, temporary password, delete), create or edit | `admin/(tabs)/accounts.tsx`, `account-view.tsx`, `account-form.tsx` (`components/admin/accounts.tsx`) | `/admin/accounts…` |
| Emergency alerts for trusted faculty and staff (Home shows it when `can_send_alerts`) | `portal/alerts.tsx` | `GET /alerts` |
| **Portal area** for students, faculty and staff: a Stack with the tabs (Home, Map, Account) and the pages below | `portal/_layout.tsx`, `portal/(tabs)/_layout.tsx` | |
| Portal home: greeting, the bell (unread count), alert banner, next class with Directions, My schedule / Saved places / Help & contact, emergency numbers. Staff get no class card | `portal/(tabs)/index.tsx` | `GET /schedule`, `/places`, `/notifications` |
| My schedule (students) or My classes (faculty): today first, then the week; Directions per class; tap to edit | `portal/schedule.tsx` | `GET /schedule` |
| Add, edit or delete a class: day chips, time chips, room list with search | `portal/class-form.tsx` (`components/class-form-parts.tsx`) | `GET /schedule/rooms`, `POST`/`PUT`/`DELETE /schedule` |
| Import from COR (students) | `portal/schedule-import.tsx` | `POST /schedule/import` |
| Saved places and Recent: tap for directions, remove, clear Recent. Saving is the star on Navigate's place card | `portal/saved-places.tsx` | `GET /places`, `/places/{room}/save`, `DELETE /places/recent` |
| Help & contact: topic and message, then My messages with status and replies | `portal/help.tsx` | `GET`/`POST /concerns` |
| Notifications (the bell): "The administrator replied" opens Help & contact at that message | `portal/notifications.tsx` | `GET /notifications`, `POST /notifications/…/read` |
| Change password (also replaces a temporary password) and Change email (code to the new address, resend, cancel), both areas | `portal/` and `admin/change-password.tsx`, `change-email.tsx` (`components/change-*-screen.tsx`) | `/account/password`, `/account/email…` |
| Map tab (both): pinch to zoom, drag, double-tap, building names, "Where to?" opens Navigate | `admin/(tabs)/map.tsx`, `portal/(tabs)/map.tsx` (`components/campus-map-screen.tsx`) | `GET /map` |
| Account tab (portal) and Settings → My account (admin): name, role, email (verified or not), ID, college, program, alerts on this phone, voice directions, Change password, Change email, the admin console link (admin only), **Sign out** | `portal/(tabs)/account.tsx`, `admin/account.tsx` (`components/account-screen.tsx`) | `POST /logout` |

`_layout.tsx` decides which screens exist (Expo Router protected routes), with exactly one area open at a time:
- **guests:** Welcome, Sign in, Create account, Forgot password and the map
- **unverified email:** Verify email (until "Skip for now", which lasts until the app is closed)
- **the admin:** the admin layout (`/admin`)
- **students, faculty and staff:** the portal (`/portal`), like the website's areas

Every top-level screen must stay inside a guard: when the session changes, Expo Router jumps to the first screen still
allowed, and an unguarded one would win (signing in used to land on the map, with no way to sign out). An area's own
pages go inside its folder, not at the root, for the same reason: as root screens, signing in opened Help & contact
instead of Home. On start the
saved token is checked with `GET /me`. A 401 from any call signs out and goes back to Welcome. Offline, the last known
user is shown.

The schedule and saved places are kept on the phone (`lib/portal-data.ts`, AsyncStorage) and shown offline with "Showing
your schedule from …" and Try again. They refresh when a screen opens and the copy is older than 30 seconds, and on
pull to refresh. Signing out forgets them.

## Intro

Every cold start shows a short intro over the screens while the session and fonts load:
- a pin drops onto the logo's peak
- gold rings spread from it
- "CNAS" and "Campus Navigation & Alert System" fade in
- everything fades into Welcome or Home

It lasts about 1.8 s (`components/intro.tsx`).
- Tap anywhere to skip it.
- With the phone's reduce motion setting on, it is only a short fade.
- It never holds back an emergency: the takeover is a Modal on top, and the intro leaves as soon as one shows.

The native splash (`app.json`, expo-splash-screen) uses the same background (`#FAF8F5`) and the same logo at the same size (`assets/images/splash-logo.png`). The intro hides the splash on its first frame, so there is no flash. The logo comes from `CNAS-web/public/logo/nav-logo.svg` (copied to `assets/images/logo.svg` and drawn by `components/logo.tsx`).

## Campus map

`src/lib/campus-map.ts` loads `GET /api/map` and keeps the building outlines, paths and landmark names for drawing, and the buildings, paths, rooms (`entrances`), stairs and landmarks for navigation (`nav`, without photos).
- **Cache:** saved in AsyncStorage (`cnas.map.v2`) with the response's ETag. The next load sends `If-None-Match`, so an unchanged map comes back as a 304 with no download. It is checked again at most every 5 minutes, and offline the saved copy is used.
- **Drawing:** `components/campus-svg.tsx` draws it with react-native-svg, no tiles, in the web map's colors:
  - buildings brick red
  - campus and grounds green
  - evacuation area darker green
  - facilities beige
  - walkways white, gates gold
  - buildings named by an active emergency in alarm red
- **Mini-map** (`components/mini-map.tsx`, Welcome, Home and Dashboard, top right): a 68 px circle fitted to the campus outline. Tap opens Navigate. During an active emergency with a location, the incident shows as a pulsing red dot (still with reduce motion on).
- **Map screen** (`app/map.tsx`, the Map tabs): pinch and drag (react-native-gesture-handler 3 hooks with Reanimated), double-tap to zoom in or reset, in `components/zoomable-map.tsx` (shared with Navigate, which adds a camera). The incident gets a dot and its danger radius.
- **Names:** landmark names without "the", else the building name. A name only appears once the zoom is high enough that it doesn't overlap a bigger building's name.

Reanimated shared values use `.get()` / `.set()`, not `.value`: the React Compiler is on, and it reads `.value` during render otherwise (Reanimated warns about that).

## Navigation

Walking directions like /navigate on the website (CNAS-web `docs/navigation.md`), in `app/navigate.tsx`.

- **One engine, shared with the website.** The route graph, router, directions, legs, GPS smoothing, tracker, voice
  prompts, step counter and simulator are not copied: `metro.config.js` imports them from
  `../CNAS-web/resources/js/navigation` as `@nav/<file>.js`, so the app and the website give the same route and the
  same directions. `src/lib/navigation/engine.ts` is the only file that imports them and adds the types. Those files
  must stay free of the DOM and MapLibre; CNAS-web `tests/js/navigation-shared.test.js` checks it. EAS uploads the
  whole git repository, so they are there in cloud builds.
- **Session** (`src/lib/navigation/session.ts`): the wiring of the web's `main.js` without the DOM, with its texts and
  numbers: voice prompts, wrong way, reroute after 3 fixes more than 10 m off the route, manual mode ("Next") when
  location is off or no fix of 25 m or better comes within 20 s, one floor at a time (the pin is on the stairs, and
  moves to the room after "I'm on the 2nd floor"). `destination.kind` already has `evacuation` for the route to safety.
- **Start:** My location when the fix is 25 m or better and within 40 m of a walkway, else the Entrance Gate with a note
  why (it switches to My location once that becomes usable). "From" picks a gate or room; **Scan QR** (expo-camera)
  reads the CNAS QR codes (the web's `/navigate?from=<place>` links). Scanned while walking, it is "I'm here" at that
  spot, or a new route from there when it is off the route.
- **Search** (`src/lib/navigation/places.ts`): room names, building landmark names, gates, and words like "CR", "lab",
  "office". Codes are never shown. A building goes to its nearest ground-floor door. With nothing typed, the list is
  the web picker's (main.js `fillPicker`): Gates, then every building's rooms by floor (`browsePlaces`); a building
  chip on the first card lists that building's rooms.
- **Phone parts:** `location.ts` (expo-location, about one fix a second, while the app is open only), `steps.ts`
  (accelerometer into the shared step counter), `voice.ts` (expo-speech; "Voice directions" on the Account tab, off by default like the web, saved as
  `cnas.nav.voice`, the old `cnas.nav.muted` is removed; the mute button only changes the trip under way, and the
  route to safety will start its trip with `alwaysOn`), expo-keep-awake while
  walking. No compass is used. Android reports a missing speed or direction as 0 (expo-location copies `getSpeed()`
  and `getBearing()` unchecked); `location.ts` treats 0 as unknown, or the dot would freeze as "standing still".
- **Drawing:** `components/navigation/` (route line in meters inside the zooming map, the blue dot gliding between
  fixes with a direction cone, the dropping pin, the maroon instruction banner). Reduce motion turns off the glide,
  pulse, pin drop and draw-in.
- **Testing away from campus:** in a development build, "debug" (bottom left while walking) shows GPS accuracy, why
  fixes were not used, the heading's source, off-route meters and reroutes, and has **Simulate**, **Walk route** and
  **Walk off** (a fake GPS walk through the same path as real fixes).
- **Restart Metro after `metro.config.js` or `tsconfig.json` changes.** A Metro started before them can't find
  `@nav/...`. It answers the bundle request with HTTP 500, and the development build closes right after you pick the
  server, with no red screen (the phone's crash log says `UnableToResolveError ... @nav/instructions.js`).
- **Web preview gotcha:** after editing navigation files, the web preview can keep serving the old `navigate` route
  chunk (lazy bundles). Restart it with `--clear` before trusting what it shows.

## Alerts

`src/lib/alerts.tsx` polls `GET /alerts/active` every 15 s while the app is open. It stops in the background and
checks again right away when the app comes back. The rules match the website (`CNAS-web/docs/alerts.md`,
`src/lib/alert-rules.ts`):

- **Emergency**: full-screen red takeover with a looping alarm and vibration until the first tap (softer sound for
  drills, which also show DRILL and hazard tape), "I'm safe" for signed-in users (`POST /alerts/{id}/safe`), then the
  red banner.
- **Warning**: orange banner, plus a one-time popup with a chime.
- **Advisory**: yellow banner only.
- **Ended**: green "All clear" or "False alarm" banner with the admin's message until `banner_expires_at`. It can be
  closed.

Each takeover or popup shows once per alert version on a phone (remembered in AsyncStorage). Guests see everything
except "I'm safe". "I'm safe" shows as done when the user already answered on the website or another phone
(`my_safety_status` from the server).

## Push alerts

How it works:
- **Channels:** the app creates three Android notification channels (`src/lib/push.ts`):
  - `emergency`: loudest, `alarm.wav`, the web's vibration pattern, shows on the lock screen
  - `drill`: `drill.wav`
  - `warning`: `chime.wav`, also used for advisories and "All clear"
- **Registering:** it sends its Expo push token to `POST /api/push-tokens` on every start and after sign-in, which links the phone to the account. Sign-out tells the server to unlink it, and the phone keeps getting alerts as a guest. The switch on Home turns alerts off (`DELETE /api/push-tokens`) or on again.
- **Asking:** after the intro, a "Turn on emergency alerts" screen explains the permission once, before Android asks. If the answer is no, Home (and Welcome) show a "Turn on alerts" card that asks again or opens the phone's settings.
- **Tapping:** tapping a notification opens the app and shows that alert at once (the takeover for an emergency), even if the phone already showed it. A push that arrives while the app is open makes it check `/api/alerts/active` right away.
- **Expo Go:** Expo Go can't get a push token on Android, so the app skips push (and the channels, since Expo Go has none of the custom sounds) there and logs a note in the console. Everything else works.
- **Channel fallback:** if Android refuses a channel, the app tries again without the alarm-stream audio settings, then with the default sound, and logs a warning. Android keeps a channel's sound once it exists, so after such a fallback clear the app's data (or reinstall) to get the custom sounds.

### What you do once

You need an Expo account (free) and a Google account for Firebase. Run every command in `CNAS-mobile`.

1. **Link the project to Expo.**
   - `npx eas-cli@latest login`
   - `npx eas-cli@latest init`: this creates the project on expo.dev and adds `extra.eas.projectId` (and `owner`) to `app.json`.
   - If it says it can't edit the config because of `app.config.js`, copy the project ID it prints into `app.json` under `expo.extra.eas.projectId` yourself.
2. **Create the Firebase project.**
   - At https://console.firebase.google.com, add a project (for example "CNAS"; Google Analytics can stay off).
   - In the project, add an **Android app** with the package name `online.tccmapsafe.cnas` (it must match `app.json`). The SHA-1 is not needed.
   - Download `google-services.json` and put it in `CNAS-mobile/`. It is in `.gitignore`, so it is never committed.
3. **Give the file to EAS** (cloud builds can't see ignored files). Run once for each build profile:
   - `npx eas-cli@latest env:create --name GOOGLE_SERVICES_JSON --type file --value ./google-services.json --visibility secret --environment development`
   - the same with `--environment preview`
   - Or on expo.dev: your project, Environment variables, add a variable of type **File** named `GOOGLE_SERVICES_JSON`, for development and preview.
   - `app.config.js` reads it; local builds use `./google-services.json` directly.
4. **Upload the FCM V1 key** (Firebase's permission for Expo to send to your phones).
   - In Firebase: Project settings, Service accounts, **Generate new private key**. Save the JSON as `cnas-firebase-service-account.json`. Don't commit it: `*-service-account*.json` is in `.gitignore`.
   - Upload it with `npx eas-cli@latest credentials`: Android, any profile, Google Service Account, "Manage your Google Service Account Key for Push Notifications (FCM V1)", Set up, Upload a new service account key, and pick the file.
   - Or on expo.dev: Project settings, Credentials, Android, `online.tccmapsafe.cnas`, FCM V1 service account key, upload.
5. **Build the development build:** `npx eas-cli@latest build --profile development --platform android`. It builds in the cloud (about 10 to 20 minutes) and ends with a link and a QR code. Open it on the phone, download the APK, and install it (allow "Install unknown apps" for the browser when Android asks).
6. **Prepare the site** (in `CNAS-web`):
   - `php artisan migrate` (adds `device_push_tokens`)
   - `php artisan serve --host=0.0.0.0 --port=8000`
   - `php artisan queue:work` in a second terminal. Every push goes through the queue, and so does the receipt check 15 minutes later. Before starting a worker, check the `jobs` table for old `SendAlertPush` jobs, which would push old alerts.
   - `EXPO_ACCESS_TOKEN` in `CNAS-web/.env` is only needed if you turn on "Enhanced push security" at expo.dev.

### Every time you work on the app

- **Start:** `npx expo start` in `CNAS-mobile`. With `expo-dev-client` installed it targets the development build. Open the "CNAS" app on the phone (the development build, not Expo Go) and pick the server, or scan the QR code. JS changes reload as usual.
- **Rebuild** (step 5) only after changing `app.json`, `app.config.js`, a config plugin or a package with native code.
- **Expo Go instead:** `npx expo start --go`.
- **Demo APK:** `npx eas-cli@latest build --profile preview --platform android` makes a standalone app with no PC needed. It talks to `https://tcc-mapsafe.online` (`eas.json`), so that site needs this push code, the migration and a queue worker.

### Test list (development build, signed in on the phone)

1. **Locked phone, emergency:** lock the phone and send an emergency from Admin. Within seconds: the alarm sound, vibration and "EMERGENCY · FIRE" on the lock screen, even on silent if the phone allows alarms.
2. **Drill:** send an emergency with "Drill" ticked. You should hear the softer drill sound and see the title "DRILL · …".
3. **Warning:** send a warning. You should hear the chime, and nothing rings like an alarm.
4. **All clear replaces the alert:** resolve the emergency from step 1. The notification turns into "ALL CLEAR · FIRE" in place; it should not be a second notification.
5. **Tap opens the takeover:** send an emergency, dismiss the takeover in the app, close the app, then tap the notification. The app opens straight into the red takeover. Try it from a fully closed app too.
6. **Push while open:** with the app open on Home, send an emergency. The takeover appears at once, not after up to 15 s, and there is no second system banner.
7. **Sign out keeps alerts:** sign out, lock the phone, send an alert. It still arrives. In the database, the phone's `device_push_tokens` row has `user_id` null.
8. **Alerts off:** on Home, turn off "Emergency alerts on this phone", then send an alert. No notification arrives (the row is gone), but the in-app banner still shows when CNAS is open. Turn it back on and the row comes back.
9. **Second phone marks safe:** sign in with the same account on another phone or on the website, send an emergency, and tap "I'm safe" there. Within 15 s the first phone's button shows "Marked safe".
10. **Permission said no:** on a fresh install, answer "Not now" or "Don't allow". Home shows "Turn on alerts". Tapping it asks again, or opens the phone's settings once Android stops asking.
11. **Expo Go still works:** `npx expo start --go`. The app runs, and the console says push is skipped.

## Code

- `src/lib/api.ts`: the one `api()` helper. JSON, `Accept: application/json` and the bearer token. Laravel 422 errors
  become per-field messages. 429 says how long to wait, and offline gives a plain message.
- `src/lib/session.tsx`: token in `expo-secure-store`, sign-in and sign-out, the current user.
- `src/lib/alarm.ts`: sounds (`assets/sounds`, the same files as the website) via `expo-audio`, and vibration.
- `src/lib/push.ts`, `src/lib/push-context.tsx`: channels, permission, the Expo push token, notification taps.
- `app.config.js`: adds `google-services.json` (from EAS or the local file) to `app.json`. `eas.json`: the
  `development` (development client, internal APK) and `preview` (internal APK for the demo) build profiles.
- `src/components/`: shared UI, alert banner and overlays, emergency numbers, intro and logo, mini-map and campus drawing,
  the alerts prompt and the alerts card.
- `src/theme.ts`: the CNAS-web colors and the Instrument Sans type scale. Light mode only.
