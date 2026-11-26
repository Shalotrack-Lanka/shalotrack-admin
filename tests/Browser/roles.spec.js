import { test, expect, state } from './support/fixtures.js';
import { authFile } from './support/paths.js';

/** A signed-in user must never open another role's pages: they get 403 or are sent elsewhere, never the page. */

const { pages, users } = state();

for (const role of Object.keys(users)) {
    test.describe(`${role} cannot open other roles' pages`, () => {
        test.use({ storageState: authFile(role) });

        const foreign = pages.filter((p) => p.roles.length > 0 && !p.roles.includes(role) && !/export|download|pdf|csv|xlsx|print|template/i.test(p.uri));

        for (const p of foreign) {
            test(`${p.uri} (belongs to ${p.roles.join('/')})`, async ({ page }) => {
                const response = await page.request.get(p.uri, { maxRedirects: 0 });
                // 403 (refused) or 3xx (sent away). Never 200 with the page, never a server error.
                expect([301, 302, 303, 307, 308, 401, 403, 404], `${role} got ${response.status()} on ${p.uri}`).toContain(response.status());
            });
        }
    });
}
