# Pre-deployment review: CNAS-web (and the /api the mobile app will use)

Reviewed 2026-11-18. This was a review only: no code, config, migration or test was changed.

## Summary

**Safe to deploy? Not yet.** The code is in good shape. All 715 PHP tests and 265 JS tests pass, Pint is clean, and `php artisan optimize` works. Every admin route sits behind `auth` plus the admin check. Students can only reach their own schedule, places, settings and "I'm safe" answers. I found no XSS, CSRF or mass-assignment holes, and no secrets in git or in the JS bundles. COR PDFs are never stored.

Four things should be fixed or decided first:

1. `php artisan db:seed` on the server creates a known login (`test@example.com` / `password`).
2. The alert banner cache is cleared before the database saves the alert. On a cache store other than the current database one, open pages and the app can miss a new alert, or keep sounding the alarm after "All clear".
3. Registration allows 5 COR uploads a minute and 20 an hour per IP address. Campus Wi-Fi gives everyone the same IP, so in-class sign-up will be blocked.
4. ~~There is no way to recover a forgotten password, not even for the admin to set a new one.~~ **Fixed 2026-11-21** (see #4).

Most of the rest are `.env` and server settings, listed in the checklist at the end.

## 1. Results of the checks

| Command | Result |
| --- | --- |
| `php artisan test --compact` | Passed: 715 tests, 2689 assertions, 65 s. No failures, no warnings. |
| `npm run test:js` | Passed: 265 tests, 66 suites, 0 failed, 0 skipped. |
| `npm run build` | Built in 2.82 s. One warning: `(!) Some chunks are larger than 500 kB after minification.` (`maplibre-gl-*.js` 1,028 kB / 273 kB gzip, `index.module-*.js` 630 kB / 162 kB gzip). Not an error; both load on map pages only. |
| `vendor/bin/pint --test` | Passed. |
| `php artisan route:list --except-vendor` | 112 routes, no errors. |
| `php artisan optimize` (extra) | config, events, routes and views all cache without errors. No `env()` outside `config/`, no route closures. (Cleared again afterwards; see section 5.) |
| `composer audit` (extra) | 8 advisories in 5 packages: laravel/framework (low), symfony/http-foundation (4), symfony/http-kernel, symfony/routing, symfony/yaml (dev only). |
| `npm audit --omit=dev` (extra) | 1 critical: maplibre-gl ≤ 6.4.0, XSS in `DOM.sanitize()` (GHSA-jrc7-96c5-q579). |

## 2. Findings (most serious first)

| # | Severity | Area | File and line | What is wrong | What could happen to a real user | Suggested fix |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | High | Seeders | `database/seeders/DatabaseSeeder.php:29` | `DatabaseSeeder` always creates a student `test@example.com` whose factory password is `password`. Running it twice fails on the duplicate email. With `composer install --no-dev` it fails because Faker is a dev package. | If someone runs `php artisan db:seed` on the server, anyone can sign in as a student with a known password. | Create the test user only when `app()->isLocal()`. In production run only the named seeders (see checklist). |
| 2 | High | Alerts, cache | `app/Models/Alert.php:83` (`saved` → `Cache::forget`), called inside `DB::transaction` at `:114`, `:142`, `:169` | The `alerts.current` cache is cleared before the transaction commits. A poll that lands in that gap rebuilds the cache from the old state and keeps it forever. With the current `CACHE_STORE=database` on the same MySQL connection this is mostly hidden, because the delete is part of the same transaction. With Redis, file or memcached it is real. Every open page polls every 15 s. | A new emergency does not show on open pages or in the app (push still arrives), or the takeover alarm keeps running after "All clear", until the next alert change. | Clear the cache after commit (`DB::afterCommit(...)` in the `saved` hook, or move the `forget` after the transaction). Also cache with a short TTL (for example 30–60 s) as a safety net. Do this before switching the cache to Redis. |
| 3 | High | Rate limits | `app/Providers/AppServiceProvider.php:111-112` | `register` and `register-cor` are limited per IP: 5 a minute, 20 an hour. The comment in `routes/api.php` already notes that the whole campus can share one IP. | When a class registers together on campus Wi-Fi, everyone after the 5th student in a minute, or the 20th in an hour, gets "Too many COR uploads". | Key the limit by IP plus something per student (the uploaded file hash, or the draft token for `register`), or raise the hourly cap a lot. Keep a per-IP limit only as a high ceiling. |
| 4 | High | Accounts | `resources/views/pages/login-page.blade.php:63` (`href="#"`); no reset routes; no admin "set password" action | "Forgot password?" goes nowhere. There is no reset flow and the admin cannot set a new password for someone. ~~Email changes are not confirmed, so a typo also locks the user out.~~ **Fixed 2026-11-21:** a new email only replaces the old one after the code sent to it is entered, and a mistyped sign-up email can be fixed on the verify page (docs/registration.md, "Email verification"). ~~The password part is still open.~~ **Fixed 2026-11-21:** "Forgot password?" emails a code to the account's email (verified or not) and never shows whether an account exists; someone who can't open their email goes to the administrator with their school ID (a self-service COR recovery was removed: anyone with a copy of a COR could take over the account); the admin can set a temporary password, which must be changed at the next sign-in. Each ends all sessions and app tokens. See docs/registration.md, "Password recovery", and docs/accounts.md. | A student who forgets their password can only get in again if the admin deletes the account, which also deletes their schedule and places, and they register again. Faculty and staff must be recreated. | Before launch, at least add an admin "Set temporary password" button on the account page (signs out other sessions, like the password change does). A real email reset can come later, once mail is set up. |
| 5 | Medium | Proxies | `bootstrap/app.php:19` (`trustProxies(at: '*')`) | Every immediate peer is trusted for `X-Forwarded-*`. There is also no trusted-hosts check. The composer audit lists Symfony advisories on forwarded headers and on generated URLs using a host the client picks. | If the server is reachable without the proxy, a client can fake its IP and skip every rate limit, and the sign-in history shows false IPs. Behind two proxies (for example Cloudflare → nginx), every user appears as the CDN's IP and they all share one rate-limit bucket. | Set the real proxy IPs (or Cloudflare's ranges), firewall the origin so only the proxy reaches it, add `trustHosts`, and run `composer update` (#9). |
| 6 | Medium | Registration (documented decision) | `app/Services/Cor/CorValidator.php` (text checks only; QR not used per `docs/registration.md`) | The COR checks look only at the PDF's text. A hand-made PDF with a classmate's ID and name passes. | Someone registers as another student, and the real student gets "an account already exists". During an emergency the impersonator can tap "I'm safe" in that student's name, so the admin's count is wrong. | Keep the decision for now, but document the risk. Tell the admin to handle "someone used my ID" concerns by deleting that account. Consider checking the QR or the registrar's list later. |
| 7 | Medium | Privacy | `app/Http/Controllers/Api/RegisterController.php:21` (uses `UploadCorRequest`) | The web flow requires the privacy consent checkbox (`UploadCorWithConsentRequest`). The API does not ask for it. | Students who register from the mobile app have their COR processed without recorded consent (RA 10173). | ~~Use `UploadCorWithConsentRequest` for `POST /api/register/cor` too.~~ **Fixed 2026-11-22:** the API requires `consent`; the app sends `consent=1` after showing the notice (test in `ApiRegistrationTest`). |
| 8 | Medium | Privacy | `resources/views/pages/register-page.blade.php:41-53`; `partials/site-footer.blade.php:74` | The notice says "We keep only your student ID, name, college and email". The code also keeps the program, "I'm safe" answers (30 days), saved and recent places, last-seen time, push subscriptions and concerns (with no deletion period). The "Privacy & transparency" footer link is `#`. People whose accounts the admin creates never see a notice. | The privacy text does not match what the system stores, which is a problem under RA 10173 if anyone asks. | Rewrite the notice to list everything kept and for how long. Add a real privacy page and link it from the footer and the portal. |
| 9 | Medium | Dependencies | `composer.lock`, `package.json:30` (`maplibre-gl ^5.24.0`) | 8 PHP advisories (see table 1). maplibre-gl has a critical XSS in `DOM.sanitize()`, but this app never uses MapLibre popups or `setHTML`, so it can't be triggered today. | Mostly theoretical now. The Symfony proxy and Host issues add to #5. | `composer update` to patched laravel/framework and symfony versions, then re-run the tests. Plan the maplibre-gl 6 upgrade separately (it is a breaking major). |
| 10 | Medium | Privacy | `database/migrations/2026_11_17_160309_create_concerns_table.php:18`; `app/Services/AccountDeletion.php` | Concerns are never deleted, and deleting an account keeps their concerns with the email address (only `user_id` is cleared). `docs/portal.md` lists this as not built. | A deleted student's email and messages stay forever. | Delete resolved concerns after a set time (for example 1 year) with `Prunable`, and blank the email when the account is deleted. |
| 11 | Medium | Performance | `resources/js/alerts/index.js:90`, `routes/api.php` (`/api/alerts/active`), `.env` `CACHE_STORE=database` | Every open page (guests too) and later the app polls every 15 s. Each poll is a PHP request plus a MySQL cache read. 1,000 open pages is about 67 requests a second. | During a real emergency, when everyone opens the site, a small server slows down just when it matters. | Run php-fpm (never `artisan serve`), size workers for the polling, and move the cache to Redis once #2 is fixed. |
| 12 | Medium | Config | `config/session.php:172` | `SESSION_SECURE_COOKIE` is not set, so the session cookie is also sent over plain HTTP. `.env` also has `APP_NAME=Laravel`, so the cookie is `laravel_session`. | A session can leak if any page is ever opened over http. | Set `SESSION_SECURE_COOKIE=true` and `APP_NAME=CNAS` in production. |
| 13 | Low | Rate limits | `app/Providers/AppServiceProvider.php:96` | Login is limited per login + IP only. | One IP can try a common password against many accounts without being slowed. | Add a second per-IP limit (for example 30 a minute) next to the per-account one. |
| 14 | Low | Map cache | `app/Models/PlacePhoto.php:34`, `app/Models/Panorama.php:46` (`forgetPayload` in `saved`), called inside transactions in `PlacePhotos::add/move` and `PanoramaController::saveAll` | Same before-commit pattern as #2, for `/api/map`. | Rarely, a new photo, a reorder or a 360° photo change doesn't show on the map until the next edit. | Same fix as #2 (`DB::afterCommit`). |
| 15 | Low | Push | `app/Jobs/SendAlertPush.php:27,38` | One job sends to every subscription in chunks with `$tries = 3`. If it fails part way, the retry sends to everyone again. The default `queue:work --timeout` is 60 s and `DB_QUEUE_RETRY_AFTER` is 90 s. | Some phones buzz twice for one alert, or a very large list times out and later phones get nothing. | Dispatch one job per chunk, run the worker with `--timeout=120`, and set `DB_QUEUE_RETRY_AFTER` above it. |
| 16 | Low | Uploads | `app/Services/PlacePhotos.php:41` / `PhotoCopies::decode` | Normal photos are checked for type and size (8 MB) but not pixel size. A small PNG can be 20,000 × 20,000 px. | Admin only: such a file crashes the request (out of memory). | Refuse images over, say, 8,000 px on a side (`dimensions:max_width=…,max_height=…`). |
| 17 | Low | Uploads | `app/Services/Cor/CorExtractor.php:56` | The public COR upload parses any PDF up to 5 MB with no decode memory limit. | A crafted PDF can make that request fail with a 500 error (rate limited, so not a site outage). | `$config->setDecodeMemoryLimit(...)` and a sane PHP `memory_limit`. |
| 18 | Low | Validation | `app/Http/Requests/Registration/RegistrationRules.php:45` | Registration uses `email`; every other email field uses `email:rfc,strict`. | Some odd addresses are accepted at sign-up but would be refused if the user tries to change them later. | Use `email:rfc,strict` here too. |
| 19 | Low | Content | `config/cnas.php:18-19` | The fallback contact details are a personal Gmail address and the placeholder phone `+63 900-000-000`. They show in the footer until the admin saves Contact details. | Students see a fake phone number on day one. | Save the real details in Admin > Settings right after deploy. Optionally put the school's office address in the config. |
| 20 | Low | Error pages | `resources/views/errors/` (does not exist) | Laravel's default error pages are used. With `APP_DEBUG=false` they show no stack trace, which is safe, but they are plain and unbranded. | A student sees a bare "404" or "Page expired" page. | Optional: add `404`, `419`, `500` and `503` views in the site style. |
| 21 | Low | Images | `resources/js/panorama-map.js:146` (`preload: true`); 360° copies are 4096 px, about 1 MB | Street view preloads neighbouring 4096 px photos. Normal photos are fine (1600 px web copy, 480 px thumbnail). | Several MB of mobile data per walk in street view. | Make a 2048 px copy for phones, or turn off preload on slow connections. |
| 22 | Low | Indexes | migrations for `users`, `alerts`, `concerns` | No index on `users.role`, `users.created_at`, `alerts.sent_at` or `concerns.created_at`, which are used in where/order. | None at today's size (hundreds or a few thousand rows). | Add them when the tables grow. |
| 23 | Low | Tests | whole suite | Tests run on SQLite only (the redundant `ExampleTest` was deleted). Nothing tests the before-commit cache gap (#2), API consent (#7) or `DatabaseSeeder`. | A MySQL-only problem would reach production unnoticed. | Run the suite once against a MySQL test database in CI or before each release. Add tests for #2 and #7 when they are fixed. |

Checked and fine (no finding): admin middleware on every `admin.*` route; `ClassSchedulePolicy` on edit, update and delete; scoped bindings for photos; trusted-alert middleware; `Fillable` on every model, with `role`, `verified_at` and `alert_trusted_at` kept out of it; every `POST` form has `@csrf`, with no CSRF exceptions; no `{!! !!}` in Blade; every `innerHTML` is static markup, escaped server HTML or `escapeHtml()`; photo copies are re-encoded by GD (no EXIF or GPS kept); deleting a photo or 360° photo deletes its files; the COR temp file is deleted right after reading; `storage/app/private` is empty; the COR fixtures use fake names and IDs; no `.env`, keys or logs in git; the VAPID private key is only read on the server (only the public key is in `<meta>`); `AdminSeeder` never resets an existing admin's email or password (and has a test); retention for drafts (every 15 min), sign-ins (90 days), "I'm safe" (30 days) and expired tokens is scheduled and tested; GPS position never leaves the phone; recent places are capped at 8; the settings, emergency-contact, term and map caches are cleared on every save; `public/sw.js` caches nothing and uses `skipWaiting` plus `clients.claim`, so a deploy can't leave phones on old code; migrations all have `down()` methods and use no SQLite-only SQL.

## 3. Missing before production

- ~~**Password recovery**~~: built 2026-11-21 (#4). It needs the mail settings and a queue worker in production, like email verification.
- **A privacy page**, and a notice that matches what is stored (#8), shown to accounts the admin creates too.
- **Concern retention**, and removing the email when an account is deleted (#10).
- **Queue worker monitoring**: if the worker dies, no phone gets a push and nothing warns the admin (the status check was removed at your request). At least use Supervisor with autorestart, and look at `failed_jobs`.
- **Backups**: the MySQL database and `storage/app/public` (photos and 360° photos are not in git).
- **Mobile app API**: CNAS-mobile v1 (sign-in, registration, email verification, password reset, in-app alerts) is built on the API. "I'm safe" has an API route since 2026-11-22. Still missing: native (Expo) push and saved places.
- **Branded error pages** (optional, #20).

## 4. Deployment checklist (in order)

**Server**
1. PHP 8.3 with `gd`, `exif`, `openssl`, `pdo_mysql`, `mbstring`, `intl`, `bcmath` (`gmp` optional, makes push faster). MySQL 8. nginx or Apache with HTTPS.
2. php.ini: `upload_max_filesize=40M`, `post_max_size=50M` (360° photos are up to 40 MB; six normal photos are up to 48 MB), `memory_limit=512M`. nginx: `client_max_body_size 50m`.
3. Only the proxy may reach the app. Set the real proxy IPs in `bootstrap/app.php` (#5).

**Code**
4. `composer install --no-dev --optimize-autoloader`
5. `npm ci && npm run build`

**.env (production values)**
```
APP_NAME=CNAS
APP_ENV=production
APP_KEY=            # php artisan key:generate once, then never change it
APP_DEBUG=false
APP_URL=https://your-domain        # push links and icons use this
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info                      # COR rejections are logged at info
LOG_DAILY_DAYS=14

DB_CONNECTION=mysql
DB_HOST=…  DB_PORT=3306  DB_DATABASE=cnas
DB_USERNAME=cnas_app                # not root
DB_PASSWORD=…                       # strong

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=false

CACHE_STORE=database                # keep until finding #2 is fixed, then redis
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=180            # must be above the worker --timeout
FILESYSTEM_DISK=local               # photos use the public disk explicitly
MAIL_MAILER=failover                # verification codes are emailed (queued): MAIL_* (Hostinger) first, then MAIL_FALLBACK_* (Gmail); see .env.example
BCRYPT_ROUNDS=12

VAPID_SUBJECT=mailto:<admin address>
VAPID_PUBLIC_KEY=…                  # php artisan webpush:vapid once; never change
VAPID_PRIVATE_KEY=…

ADMIN_USERNAME=…  ADMIN_EMAIL=…  ADMIN_PASSWORD=…   # strong; remove ADMIN_PASSWORD after the first seed
```
`SANCTUM_STATEFUL_DOMAINS` is not needed: the app uses bearer tokens, which expire after 30 days (`config/sanctum.php`). CORS uses Laravel's default (`api/*`, any origin, no credentials), which is fine for tokens. `config/cnas.php` has no `.env` values, only the fallback contact details (#19).

**Database and files**
6. `php artisan migrate --force`
7. Seed only these, never plain `db:seed`:
   `php artisan db:seed --class=CollegeSeeder --force`, then `ProgramSeeder`, `AcademicTermSeeder` (check it sets the right current term), `AdminSeeder`, `CampusMapSeeder`, `EmergencyContactSeeder`.
8. `php artisan storage:link`
9. Copy `storage/app/public/panoramas` and `place-photos` from the dev machine if you want the existing photos (the database rows point to them).
10. `php artisan optimize`

**Processes**
11. Supervisor: `php artisan queue:work --tries=3 --timeout=120`, with autorestart.
12. Cron: `* * * * * cd /path/to/CNAS-web && php artisan schedule:run >> /dev/null 2>&1`. Without it, drafts, sign-in history and "I'm safe" answers are never deleted.

**After the first deploy**
13. Sign in as admin, change the password, fill in Contact details and Emergency contacts, and open Settings > System status (push keys and HTTPS should both be OK).
14. Check Admin > Academic terms has the right current term, and Admin > Map data shows the map.
15. Turn on alerts on one phone, send a **drill** at advisory level, and confirm the banner and the push arrive and that "All clear" ends it.

**Every later deploy**
16. `php artisan down` → pull → `composer install --no-dev -o` → `npm ci && npm run build` → `php artisan migrate --force` → `php artisan optimize` → `php artisan queue:restart` → `php artisan up`.

## 5. What I could not check, and why

- **Migrations from scratch on MySQL, and their `down()`**: you asked for no `migrate:fresh` or rollback, so I didn't run them. Your dev MySQL database shows all 33 migrations have run (`migrate:status`), and I read every `down()` by eye. The tests only use SQLite.
- **Real phones, push delivery, HTTPS and the proxy**: there is no production server yet, and I don't know the hosting setup (one proxy or two, CDN or not). That decides finding #5.
- **CNAS-mobile**: it only has `src/app/index.tsx` and `_layout.tsx` (the Expo template), so I reviewed the API from the server side only.
- **Load**: no load test. The polling numbers in #11 are estimates.
- **Size of the `/api/map` response**: measuring it would have written to the cache table, so I didn't.
- **Real CORs**: only the anonymised fixtures were checked.
- **One side effect to report**: to test `php artisan optimize` I ran `php artisan optimize:clear` afterwards to leave no cached config behind. That command also flushes the application cache, and with `CACHE_STORE=database` it deleted the rows in the dev database's `cache` table (map payload, contact details, current term, rate-limit counters). They rebuild on the next request. Nothing else in the database was touched.
