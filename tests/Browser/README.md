# Browser tests (Admin Portal)

Real Chromium opens the real pages, signs in as each role and clicks through them. They catch what the PHP tests
cannot: JavaScript errors, broken buttons, menus that do not open.

## What they check

- `login.spec.js`: login page, wrong password, right password, lockout after 5 wrong passwords.
- `smoke-pages.spec.js`: EVERY page that needs no id in its address (about 110, read from the real route list),
  opened as the role it belongs to. A page fails on a server error, an error screen, an uncaught JavaScript
  error or a console error. Developer-only pages must refuse an ordinary admin.
- `roles.spec.js`: a signed-in role is refused on every other role's pages.
- `js-screens.spec.js`: scan device, sidebar menus, phone menu, logout, dealer screens.

## Safe by design

- Same database settings as the PHP Pgsql tests (`.env.testing`, the isolated `autotest` schema). Before anything
  starts, `tests/Browser/support/browser-db.php` checks the schema is `autotest` and the user is `autotest_user...`.
  If not, everything stops.
- Accounts and rows are created with reserved names (`autotest-browser-...`, `Autotest Browser ...`) and removed
  at the end, with everything the pages created for them. Leftovers from a killed run are swept next time.
- The test server cannot reach the real mobile API, gateway, mail or the internet.
- Nothing here registers devices or sends commands; that is covered by the PHP tests.

## Run

One time on a computer: `npm ci` and `npx playwright install chromium`.

Each time (build again after changing `resources/js` or `resources/css`):

    npm run build
    npm run test:browser

Only some tests: `npx playwright test login`, or `npx playwright test --grep "scan device"`.
A report with screenshots of failures: `npx playwright show-report tests/Browser/.report`.

PHP must be on the PATH. The suite takes about 3 minutes on a local database; a remote database is slower.
