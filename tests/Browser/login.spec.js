import { test, expect, state } from './support/fixtures.js';

test.describe('Sign in', () => {
    test('the login page loads with no browser errors', async ({ page }) => {
        await page.goto('/login');
        await expect(page.locator('input[name="username"]')).toBeVisible();
        await expect(page.locator('input[name="password"]')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Log in' })).toBeVisible();
        expect(page.problems).toEqual([]);
    });

    test('a wrong password stays on the login page and shows an error', async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[name="username"]', state().users.ADMIN.username);
        await page.fill('input[name="password"]', 'definitely-wrong-password');
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page).toHaveURL(/\/login$/);
        await expect(page.locator('body')).toContainText(/credentials|do not match|incorrect|failed/i);
        expect(page.problems).toEqual([]);
    });

    test('a right password reaches the admin dashboard, and a signed-out visitor is sent back to login', async ({ page }) => {
        const { users, password } = state();
        await page.goto('/login');
        await page.fill('input[name="username"]', users.ADMIN.username);
        await page.fill('input[name="password"]', password);
        await page.getByRole('button', { name: 'Log in' }).click();

        await expect(page).toHaveURL(/\/admin\/dashboard/);
        expect(page.problems).toEqual([]);

        await page.context().clearCookies();
        await page.goto('/admin/dashboard');
        await expect(page).toHaveURL(/\/login/);
    });

    test('five wrong passwords lock the sixth attempt', async ({ page }) => {
        // A made-up username: the lockout counts attempts per username and address, account or not.
        // (Forged X-Forwarded-For is covered by the PHPUnit tests: a browser test server always connects from
        // the local address, which the portal rightly treats as a trusted proxy, so it cannot play a stranger.)
        const username = 'autotest-browser-nobody-' + Date.now();
        const attempts = [];

        for (let i = 1; i <= 6; i++) {
            await page.goto('/login');
            await page.fill('input[name="username"]', username);
            await page.fill('input[name="password"]', 'wrong-' + i);
            await page.getByRole('button', { name: 'Log in' }).click();
            attempts.push((await page.locator('body').getByText(/too many/i).count()) > 0);
        }

        expect(attempts).toEqual([false, false, false, false, false, true]);
        expect(page.problems).toEqual([]);
    });
});
