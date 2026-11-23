# Admin settings

Read this before touching /admin/settings, the footer contact details, emergency contacts, the admin's own account, or the system status checks.

## Decisions
- One page, `/admin/settings` (route `admin.settings`), admin only, with five tabs: Contact details (default), Emergency contacts, Academic term, My account, System status (`SettingsController::TABS`).
- **Academic term** (moved here 2026-11-23, no longer in the sidebar): the current term, Add a term (`StoreAcademicTermRequest`, bag `academicTerm`) and "Set as current" with a confirmation (`AcademicTermController`, messages `addedMessage`/`currentMessage`). GET /admin/academic-terms redirects to the tab. The dashboard only shows the warning banner when action is needed (`AcademicTerm::warning`), linking here. See registration.md.
  - `?tab=` picks the open tab. The server renders the other panels with `hidden`, and every tab is a plain link, so the page works without JavaScript.
  - Each save and each validation error redirects to its own tab (`SettingsController::tabUrl()`).
  - `resources/js/settings-tabs.js` switches tabs without a reload and updates `?tab=` with `replaceState`. It uses roving tabindex with Arrow keys, Home and End (ARIA tabs pattern).
  - Unsaved changes: hidden panels keep typed values, and a tab with unsaved changes shows a dot. Before a form is sent, it asks whether to discard unsaved changes in other forms (the capture-phase submit listener runs before `data-confirm` and `data-submit-once`). Leaving the page warns (`beforeunload`).
- Each form saves on its own. The controller flashes `section` (`contact`, `emergency`, `account`) with `status` or `error`, so a message shows only in its own section (`admin/settings-message`). Each form has its own error bag (`contact`, `newEmergencyContact`, `emergencyContact{id}`, `emergencyContacts`, `accountEmail`, `accountPassword`). Several forms share field names, so a field shows `old()` input only when its own bag has errors.
- **Contact details** (email, phone, Facebook URL, address) live in the `settings` table (key/value: `contact.email`, …). `App\Services\ContactDetails` reads them, caches them forever and falls back to `config/cnas.php` for a key that was never saved. Saving clears the cache. Always read them through the service, not `config('cnas.contact')`. The `partials.contact-details` view gets `$contact` from a view composer in `AppServiceProvider`.
- Validation: `email:rfc,strict`; phone `App\Rules\PhoneNumber` (digits, spaces, dashes, an optional leading `+`, must start and end with a digit); Facebook `url:https`.
- **Emergency contacts** (`emergency_contacts`: name, phone nullable, note, position, is_visible). Add, edit, delete, move up/down and show/hide. A contact can only be shown with a number. `position` is not fillable: new contacts go to the end (`booted`) and `EmergencyContact::move()` reorders. `EmergencyContact::listed()` (visible, with a number, in order) is cached and cleared on save or delete. The portal home (view composer for `portal.dashboard-page`, which faculty also see) and the portal Help & contact page show it with `tel:` links. With no listed contacts the block is hidden.
- `GET /api/emergency-contacts` is public: `{data: [{id, name, phone, tel, note}]}`, `Cache-Control: public, max-age=300` and an ETag.
- `EmergencyContactSeeder` adds Campus security, School clinic and Guidance office, hidden and without numbers. It uses `firstOrCreate`, so running it again keeps the admin's edits.
- **My account**: the admin changes their own email (stored lowercase, unique; it only changes after the code emailed to the new address is entered, see registration.md "Email verification") and password (current password, confirmation, `Password::defaults()` = min 8, letters and numbers, set in `AppServiceProvider`). After a password change the controller rotates the remember token, regenerates this session, deletes the user's other rows in `sessions` (database session driver only) and deletes all their Sanctum tokens (mobile app). This browser stays signed in.
- `AdminSeeder` only sets the email and password when it creates the admin. Running it again never resets what the admin changed here.
- **System status** (`App\Services\SystemStatus`, `StatusCheck`): read-only checks shown as an icon plus "OK" or "Needs attention", with one fix sentence when red. `checks()` is keyed. The checks are:
  - push keys (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT` set; values never shown)
  - app push (Expo): never red; says whether the optional `EXPO_ACCESS_TOKEN` is set (value never shown)
  - HTTPS (`$request->isSecure()`, proxies are trusted)
  - current academic term
  - map data (buildings, paths and rooms all above 0, with the counts)
  - push subscribers (browsers in `push_subscriptions` plus phones in `device_push_tokens`, with both counts; red at 0)
- The queue worker check and the admin test notification were removed at the user's request (2026-11-16). Don't add them back unless asked.
- **Live push subscribers**: `resources/js/push-subscribers-live.js` polls `GET admin.settings.push-subscribers` (`SystemStatusController`, JSON `{ok, summary, fix, checked_at}`, `no-store`, throttle 30/min). It polls every 10 s, only while the System status tab is open and the browser tab is visible.
  - It refreshes right away when the tab opens, the page is shown again, the connection returns (`online`), or this browser turns alerts on or off (`push.js` dispatches `cnas:push-subscription-changed`).
  - Failures back off to 20, 40, then 60 s. The last count stays on screen with "Can't reach the server…". On 401, 403 or 419 it stops and asks for a reload.
  - It polls rather than using Server-Sent Events: `php artisan serve` on Windows handles one request at a time, so a held-open stream would block the site. Reverb isn't installed.
  - The count is the database. A device that blocks notifications in its browser settings stays counted until the next alert push to it fails (404/410), when the push package deletes it.
- The page includes `partials.push-alerts` so the admin can turn alerts on or off for this browser.

## Reports for admins (built 2026-11-23)
- A new concern (`ConcernController::store`, web, app and footer) and every "I need help" (`NeedHelpController`) queue `SendAdminReportPush` with an `AdminReport` (title, body, `data.screen` = `concern` + `concern_id` or `safety` + `alert_id`, a tag). It pushes only to `device_push_tokens` linked to admin accounts, on the Android channel `admin_reports` (high importance, the phone's default sound: the app leaves `sound` out, since Android reads "default" as a missing file), through `ExpoPush::deliver()` (shared with the alert push: dead tokens deleted, receipts checked 15 minutes later). The bell and email notifications are unchanged.
- App: the `admin_reports` channel (`push.ts`; it deletes the older `admin` channel, made with a broken sound), `lib/admin-reports.ts` (a report arriving refreshes the Concerns badge, the open Concerns list and the live view at once; tapping opens the concern or "Who is safe"). The Concerns list and the live view also ask every 15 s while open.
- Web: the sidebar's open-concerns badge refreshes every 30 s (`resources/js/admin-badges.js`, `GET /admin/concerns/count`, `ConcernInbox::openCount()` cached 15 s).
- No staff alert requests (decided 2026-11-23: trusted staff send directly), and users can't reply on a concern, so those aren't reports.

## Mobile app Settings (built 2026-11-23)
Admin tabs: Dashboard, Map, Concerns, Accounts, Settings (My account with Sign out is inside Settings). `Api\Admin\SettingsController` (admin only) with the web's requests and messages: `GET/POST /api/admin/academic-terms`, `POST …/{term}/current`; `GET/PUT /api/admin/contact-details`; `GET/POST /api/admin/emergency-contacts`, `PUT/DELETE …/{id}`, `PATCH …/{id}/move {direction}` (`MoveEmergencyContactRequest`, shared with the web), `PATCH …/{id}/visibility`; `GET /api/admin/system-status` (the same checks, read-only). My account is the shared `/api/account`. Map data, photos and QR codes are "Open on the website" links. Tests: `Api/AdminSettingsApiTest.php`.

## Admin dashboard (analytics, built 2026-11-23)
- `App\Services\AdminAnalytics::summary()` is the one source for the web overview (`Admin\DashboardController`, `admin/dashboard-page`) and the app's dashboard (`GET /api/admin/dashboard`, admin only, plus `term_warning`). Counts only, never anyone's details. Campus days (Asia/Manila).
- What it has: accounts (total, by role, new this week against last week), sign-ins per day for 14 days (website and app apart, from `login_activities`, so it only reaches back 90 days), the last 7 days against the 7 before and how many people, concerns (open, resolved, the last 30 days by topic, median hours to the first reply), alerts in 90 days (drills apart) and how many answered the last emergency, alert reach (phones + browsers), students by college (top 5 + "Other colleges"), and the rooms people start directions to and save (top 5).
- Web: headline tiles (with the change in words), a stacked sign-ins column chart (hover or focus a day; "Show as a table"), ranked bars, the last-emergency meter, then the campus map, recent sign-ups and User activity as before. App: the same tiles and charts (`components/admin/analytics.tsx`, tap a day), kept on the phone for offline reading. Chart colors (both): website `#A8323F`, app `#A87A10`, checked together with the dataviz palette validator (color vision deficiency and contrast).

## Where the code is
- Controllers: `Admin\SettingsController` (page), `ContactDetailsController`, `EmergencyContactController`, `SystemStatusController`, `Api\EmergencyContactController`, and the shared `AccountController` for My account (the portal settings use it too, see portal.md; its rules are in `App\Services\AccountChanges`, which the app's API uses too)
- Requests: `Admin\UpdateContactDetailsRequest`, `SaveEmergencyContactRequest`, `UpdateEmergencyContactVisibilityRequest`, `Account\UpdateEmailRequest`, `Account\UpdatePasswordRequest`
- Views: `admin/settings-page`, `admin/settings-message`, `admin/status-badge`, `admin/emergency-contact-fields`, `partials/contact-details`, `portal/dashboard-page`
- JS: `resources/js/settings-tabs.js`, `resources/js/push-subscribers-live.js` (loaded from `app.js` only on this page)

## Gotchas
- The cache store can't unserialize objects (`cache.serializable_classes` false), so cached contacts are stored as raw attributes and hydrated.
- The VAPID public key is in every page's `<meta name="vapid-public-key">` by design. Only the private key is secret.
- If `VAPID_SUBJECT` is empty, the push package falls back to the site URL, so push still works. The status check still asks for it to be set.

## Tests
`tests/Feature/Admin/SettingsPageTest.php` (tabs), `ContactDetailsTest.php`, `EmergencyContactTest.php`, `MyAccountTest.php`, `SystemStatusTest.php` (checks and the live count), `AdminAccessTest.php` (settings access), `tests/Feature/EmergencyContactApiTest.php`, `SiteFooterTest.php`, `Seeders/AdminSeederTest.php`, `tests/js/settings.test.js` (tab keys, unsaved-change detection, retry delays)
