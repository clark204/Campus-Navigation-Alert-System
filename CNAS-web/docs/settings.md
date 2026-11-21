# Admin settings

Read this before touching /admin/settings, the footer contact details, emergency contacts, the admin's own account, or the system status checks.

## Decisions
- One page, `/admin/settings` (route `admin.settings`), admin only, with four tabs: Contact details (default), Emergency contacts, My account, System status (`SettingsController::TABS`).
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
  - HTTPS (`$request->isSecure()`, proxies are trusted)
  - current academic term
  - map data (buildings, paths and rooms all above 0, with the counts)
  - push subscribers (count of `push_subscriptions`, red at 0)
- The queue worker check and the admin test notification were removed at the user's request (2026-11-16). Don't add them back unless asked.
- **Live push subscribers**: `resources/js/push-subscribers-live.js` polls `GET admin.settings.push-subscribers` (`SystemStatusController`, JSON `{ok, summary, fix, checked_at}`, `no-store`, throttle 30/min). It polls every 10 s, only while the System status tab is open and the browser tab is visible.
  - It refreshes right away when the tab opens, the page is shown again, the connection returns (`online`), or this browser turns alerts on or off (`push.js` dispatches `cnas:push-subscription-changed`).
  - Failures back off to 20, 40, then 60 s. The last count stays on screen with "Can't reach the server…". On 401, 403 or 419 it stops and asks for a reload.
  - It polls rather than using Server-Sent Events: `php artisan serve` on Windows handles one request at a time, so a held-open stream would block the site. Reverb isn't installed.
  - The count is the database. A device that blocks notifications in its browser settings stays counted until the next alert push to it fails (404/410), when the push package deletes it.
- The page includes `partials.push-alerts` so the admin can turn alerts on or off for this browser.

## Where the code is
- Controllers: `Admin\SettingsController` (page), `ContactDetailsController`, `EmergencyContactController`, `SystemStatusController`, `Api\EmergencyContactController`, and the shared `AccountController` for My account (the portal settings use it too, see portal.md)
- Requests: `Admin\UpdateContactDetailsRequest`, `SaveEmergencyContactRequest`, `UpdateEmergencyContactVisibilityRequest`, `Account\UpdateEmailRequest`, `Account\UpdatePasswordRequest`
- Views: `admin/settings-page`, `admin/settings-message`, `admin/status-badge`, `admin/emergency-contact-fields`, `partials/contact-details`, `portal/dashboard-page`
- JS: `resources/js/settings-tabs.js`, `resources/js/push-subscribers-live.js` (loaded from `app.js` only on this page)

## Gotchas
- The cache store can't unserialize objects (`cache.serializable_classes` false), so cached contacts are stored as raw attributes and hydrated.
- The VAPID public key is in every page's `<meta name="vapid-public-key">` by design. Only the private key is secret.
- If `VAPID_SUBJECT` is empty, the push package falls back to the site URL, so push still works. The status check still asks for it to be set.

## Tests
`tests/Feature/Admin/SettingsPageTest.php` (tabs), `ContactDetailsTest.php`, `EmergencyContactTest.php`, `MyAccountTest.php`, `SystemStatusTest.php` (checks and the live count), `AdminAccessTest.php` (settings access), `tests/Feature/EmergencyContactApiTest.php`, `SiteFooterTest.php`, `Seeders/AdminSeederTest.php`, `tests/js/settings.test.js` (tab keys, unsaved-change detection, retry delays)
