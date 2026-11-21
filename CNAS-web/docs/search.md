# Admin search

Read this before touching the search bar in the admin top bar or `/admin/search`.

## Decisions
- One search in the admin top bar ("Search buildings, rooms, people"), admin only. The portal has no search: rooms are found on the campus map (the dashboard room search was removed, see portal.md).
- Groups, always in this order, each only when it has a result:
  - Pages: admin pages and Settings tabs, by name or keywords such as "semester" or "password". A page whose route doesn't exist yet is skipped, so Accounts appears once `admin.accounts` exists.
  - Buildings: name, code, landmark name. The campus outline is excluded because it has no edit page.
  - Rooms: name, code, or their building's name.
  - 360° photos: name or code. They open the photo map editor.
  - People: first or last name, school ID, email (`User::scopeSearch`).
- Past emergency alerts are not searched (decided 2026-11-17).
- Every word must match, in any order; a word may match any column of its group. `%`, `_` and `\` split words, so they never act as LIKE wildcards. At least 2 characters; input is cut at 100. Names starting with the first word come first.
- A person opens their page in Admin > Accounts (see accounts.md).
- The search runs on the server, so people's details are never sent to the browser in bulk (RA 10173).

## How it works
- The top bar field is a GET form to `admin.search` (the results page, up to 25 per group), so Enter works without JavaScript.
- `resources/js/admin-search.js` adds suggestions while typing from `admin.search.suggestions`: JSON `{query, groups, all_url}`, 5 per group, throttle 60/min, debounced 200 ms, earlier requests aborted. It is an ARIA combobox and listbox:
  - The arrow keys choose a result (wrapping), Enter opens it, or opens the results page when none is chosen.
  - Escape closes the list, then the phone row, then clears the field.
  - "/" anywhere outside a field jumps to the search.
  - Results are written with `textContent`, never as HTML.
- Below md the field sits behind a search button, which links to the results page when JavaScript is off. The field opens as a row under the top bar (`data-open` on the form).

## Where the code is
`App\Services\AdminSearch`, `Admin\SearchController`, `partials/app-topbar.blade.php` (the `search` array is passed by `layout/admin-layout`), `admin/search-page.blade.php`, `resources/js/admin-search.js`

## Tests
`tests/Feature/Admin/AdminSearchTest.php`, `tests/js/admin-search.test.js`
