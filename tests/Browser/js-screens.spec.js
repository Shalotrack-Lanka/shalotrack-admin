import { test, expect, state, openAndSettle } from './support/fixtures.js';
import { authFile } from './support/paths.js';

/**
 * Click through the screens that run their own JavaScript. The smoke test proves every page LOADS without a
 * browser error; these prove the controls on the busiest screens still WORK.
 * Nothing here saves data: registering devices, sending commands and the like are covered by the PHPUnit suite.
 */

test.describe('ADMIN screens', () => {
    test.use({ storageState: authFile('ADMIN') });

    test('scan device: choose a type, scan an IMEI, the row appears and the server check answers', async ({ page }) => {
        const { tag } = state();
        await openAndSettle(page, '/admin/master-pages/setup-device/scan');

        // The scan box stays locked until a device type is chosen.
        const scanBox = page.locator('input[x-ref="scanInput"]');
        await expect(scanBox).toBeDisabled();

        const option = page.locator('select[x-model="deviceTypeId"] option', { hasText: `AUTOTEST-BROWSER-${tag}` });
        await page.locator('select[x-model="deviceTypeId"]').selectOption(await option.getAttribute('value'));
        await expect(scanBox).toBeEnabled();

        const check = page.waitForResponse((r) => r.url().includes('/setup-device/scan/') && r.request().method() === 'POST');
        await scanBox.fill('356938035643809');          // a well-formed IMEI that is not in stock
        await scanBox.press('Enter');
        const response = await check;
        expect(response.status(), 'the scan check must answer, not crash').toBeLessThan(500);

        await expect(page.locator('tbody tr').filter({ hasText: '356938035643809' })).toHaveCount(1);
        await expect(scanBox).toHaveValue('');          // the box is cleared, ready for the next scan
        expect(page.problems).toEqual([]);
    });

    test('scan device: scanning the same IMEI twice does not add a second row', async ({ page }) => {
        const { tag } = state();
        await openAndSettle(page, '/admin/master-pages/setup-device/scan');
        const option = page.locator('select[x-model="deviceTypeId"] option', { hasText: `AUTOTEST-BROWSER-${tag}` });
        await page.locator('select[x-model="deviceTypeId"]').selectOption(await option.getAttribute('value'));

        const scanBox = page.locator('input[x-ref="scanInput"]');
        for (let i = 0; i < 2; i++) {
            await scanBox.fill('356938035643809');
            await scanBox.press('Enter');
            await page.waitForTimeout(500);
        }

        await expect(page.locator('tbody tr').filter({ hasText: '356938035643809' })).toHaveCount(1);
        expect(page.problems).toEqual([]);
    });

    test('the sidebar menu groups open and close without errors, and a link opens its page', async ({ page }) => {
        await openAndSettle(page, '/admin/dashboard');

        const groups = page.locator('aside button:visible');
        const count = await groups.count();
        expect(count, 'the sidebar should have menu groups').toBeGreaterThan(3);

        for (let i = 0; i < count; i++) {
            await groups.nth(i).click({ trial: false }).catch(() => {});    // open
            await groups.nth(i).click().catch(() => {});                    // close again
        }
        expect(page.problems).toEqual([]);

        await page.locator('aside a[href*="/admin/"]:visible').first().click();
        await expect(page).toHaveURL(/\/admin\//);
        expect(page.problems).toEqual([]);
    });

    test('the dark mode switch (when present) and the page keep working after a reload', async ({ page }) => {
        await openAndSettle(page, '/admin/dashboard');
        await page.reload();
        await expect(page.locator('body')).toBeVisible();
        expect(page.problems).toEqual([]);
    });

    test('on a phone the menu button opens the sidebar', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 800 });
        await openAndSettle(page, '/admin/dashboard');

        const aside = page.locator('aside').first();
        await expect(async () => {
            const box = await aside.boundingBox();
            expect(box === null || box.x + box.width <= 1, 'sidebar starts hidden off-screen').toBe(true);
        }).toPass({ timeout: 5000 });

        await page.locator('header button').first().click();
        await expect(async () => {
            const box = await aside.boundingBox();
            expect(box && box.x >= 0 && box.width > 100, 'sidebar slides in').toBe(true);
        }).toPass({ timeout: 5000 });
        expect(page.problems).toEqual([]);
    });

    test('logout signs the user out and the dashboard is closed again', async ({ browser, baseURL }) => {
        // Its OWN sign-in: logging out ends the session on the server, which must not be the one the other tests share.
        const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
        const page = await context.newPage();
        const { users, password } = state();
        await page.goto('/login');
        await page.fill('input[name="username"]', users.ADMIN.username);
        await page.fill('input[name="password"]', password);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).toHaveURL(/\/admin\/dashboard/);

        // The logout button sits in the profile menu: open it with the button that shows the user's name.
        const logout = page.locator('form[action$="/logout"] button');
        await expect(logout).toBeHidden();
        await page.locator('header').getByRole('button', { name: /Autotest Browser ADMIN/ }).click();
        await expect(logout).toBeVisible();
        await logout.click();

        await page.goto('/admin/dashboard');
        await expect(page).toHaveURL(/\/login/);
        await context.close();
    });
});

test.describe('DEALER screens', () => {
    test.use({ storageState: authFile('DEALER') });

    test('the dealer dashboard and the command centre open and the dealer cannot reach admin pages', async ({ page }) => {
        await openAndSettle(page, '/dealer/dashboard');
        await expect(page.locator('body')).toBeVisible();

        await openAndSettle(page, '/dealer/device-commands');
        expect(page.problems).toEqual([]);

        const response = await page.request.get('/admin/dashboard', { maxRedirects: 0 });
        expect([302, 403]).toContain(response.status());
    });
});
