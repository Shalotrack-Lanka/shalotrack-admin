import { test, expect, state, openAndSettle } from './support/fixtures.js';
import { authFile } from './support/paths.js';

/**
 * Open EVERY page that needs no id in its address, as a real browser, signed in as the role it belongs to.
 * The list comes from the real route table (see browser-db.php), so a new page is covered automatically.
 *
 * A page passes when: it answers (not 4xx/5xx), it shows no "server error" screen, and the browser reports no
 * uncaught JavaScript error, no console error and no 500 from the page's own background requests.
 */

const DOWNLOAD_LIKE = /export|download|pdf|csv|xlsx|print|template|\/report$/i;
const ERROR_SCREEN = /Whoops|Server Error|Undefined (variable|index|array key)|SQLSTATE|Stack trace/i;

const { pages, users } = state();
const publicPages = pages.filter((p) => p.roles.length === 0);

test.describe('public pages', () => {
    for (const p of publicPages.filter((p) => !['/'].includes(p.uri))) {
        test(`${p.uri}`, async ({ page }) => {
            const response = await openAndSettle(page, p.uri);
            expect(response.status(), `${p.uri} answered ${response.status()}`).toBeLessThan(400);
            expect(page.problems).toEqual([]);
        });
    }
});

for (const role of Object.keys(users)) {
    test.describe(`${role} pages`, () => {
        test.use({ storageState: authFile(role) });

        // A page shared by ADMIN and DEALER is opened as the DEALER (the admin has no dealer account to show).
        const mine = pages.filter((p) => {
            if (!p.roles.includes(role)) return false;
            const owner = p.roles.length > 1 ? p.roles.find((r) => r !== 'ADMIN') : p.roles[0];
            return owner === role;
        });

        for (const p of mine) {
            if (p.developerOnly) {
                // Only the company developer login may open these. An ordinary administrator must be refused.
                test(`${p.uri} (developer only: refused for ${role})`, async ({ page }) => {
                    const response = await page.request.get(p.uri);
                    expect(response.status()).toBe(403);
                });
                continue;
            }

            if (DOWNLOAD_LIKE.test(p.uri)) {
                // A download is not shown in a page: only check that it is served, not as an error.
                test(`${p.uri} (download)`, async ({ page }) => {
                    const response = await page.request.get(p.uri);
                    expect(response.status(), `${p.uri} answered ${response.status()}`).toBeLessThan(500);
                });
                continue;
            }

            test(`${p.uri}`, async ({ page }) => {
                const response = await openAndSettle(page, p.uri);
                expect(response.status(), `${p.uri} answered ${response.status()}`).toBeLessThan(400);
                // A signed-in role must be shown the page, never bounced to the login screen (that would be an empty pass).
                expect(new URL(page.url()).pathname, `${role} was sent to the login page from ${p.uri}`).not.toBe('/login');
                expect(await page.locator('body').innerText()).not.toMatch(ERROR_SCREEN);
                expect(page.problems).toEqual([]);
            });
        }
    });
}
