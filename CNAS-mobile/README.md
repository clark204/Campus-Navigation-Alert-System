# CNAS mobile

The phone app for CNAS (Campus Navigation & Alert System), Tagoloan Community College. Expo SDK 58, Expo Router,
TypeScript. It talks only to the CNAS-web API (`../CNAS-web/routes/api.php`).

Version 1 covers sign-in, COR registration, email verification, forgot password, in-app emergency alerts and a campus
map to look at. Navigation is not built yet: the map screen links to Navigate on the website.

Everything runs in Expo Go (no development build needed).

## Setup

1. Install packages: `npm install` (add new ones with `npx expo install <package>`, never `npm install <package>`).
2. Copy `.env.example` to `.env` and set `EXPO_PUBLIC_API_URL` to the CNAS-web site, without `/api`:
   - local testing: `http://<your PC's LAN IP>:8000`, for example `http://192.168.1.2:8000`
   - production: `https://tcc-mapsafe.online`
3. Start the site so the phone can reach it (in `CNAS-web`):
   - `php artisan serve --host=0.0.0.0 --port=8000`
   - a queue worker (`php artisan queue:work`), or no verification or reset email is ever sent
   - Windows may ask to let PHP through the firewall: allow it on private networks.
4. Start the app: `npx expo start`, then scan the QR code with Expo Go (Android) or the Camera app (iPhone). The phone
   must be on the same Wi-Fi as the PC.

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
| Home: greeting, college, alert banner, emergency numbers, mini-map, sign out | `home.tsx` | `POST /logout` |
| Campus map: pinch to zoom, drag, double-tap to zoom in or out, building names, link to Navigate on the website (open to guests) | `map.tsx` | `GET /map` |

`_layout.tsx` decides which screens exist (Expo Router protected routes): guests see Welcome, Sign in, Create account
and Forgot password. Signed in, Verify email comes first while `email_verified` is false, then Home. On start the
saved token is checked with `GET /me`. A 401 from any call signs out and goes back to Welcome. Offline, the last known
user is shown.

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

`src/lib/campus-map.ts` loads `GET /api/map` and keeps only the building outlines, paths and landmark names.
- **Cache:** saved in AsyncStorage with the response's ETag. The next load sends `If-None-Match`, so an unchanged map comes back as a 304 with no download. It is checked again at most every 5 minutes, and offline the saved copy is used.
- **Drawing:** `components/campus-svg.tsx` draws it with react-native-svg, no tiles, in the web map's colors:
  - buildings brick red
  - campus and grounds green
  - evacuation area darker green
  - facilities beige
  - walkways white, gates gold
  - buildings named by an active emergency in alarm red
- **Mini-map** (`components/mini-map.tsx`, Welcome and Home, top right): a 68 px circle fitted to the campus outline. During an active emergency with a location, the incident shows as a pulsing red dot (still with reduce motion on).
- **Map screen** (`app/map.tsx`): pinch and drag (react-native-gesture-handler 3 hooks with Reanimated), double-tap to zoom in or reset. The incident gets a dot and its danger radius.
- **Names:** landmark names without "the", else the building name. A name only appears once the zoom is high enough that it doesn't overlap a bigger building's name.

Reanimated shared values use `.get()` / `.set()`, not `.value`: the React Compiler is on, and it reads `.value` during render otherwise (Reanimated warns about that).

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
except "I'm safe". There are no push notifications yet: alerts only arrive while the app is open.

## Code

- `src/lib/api.ts`: the one `api()` helper. JSON, `Accept: application/json` and the bearer token. Laravel 422 errors
  become per-field messages. 429 says how long to wait, and offline gives a plain message.
- `src/lib/session.tsx`: token in `expo-secure-store`, sign-in and sign-out, the current user.
- `src/lib/alarm.ts`: sounds (`assets/sounds`, the same files as the website) via `expo-audio`, and vibration.
- `src/components/`: shared UI, alert banner and overlays, emergency numbers, intro and logo, mini-map and campus drawing.
- `src/theme.ts`: the CNAS-web colors and the Instrument Sans type scale. Light mode only.
