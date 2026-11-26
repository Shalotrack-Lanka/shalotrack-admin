import { chromium } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync } from 'node:fs';
import { STATE_DIR, STATE_FILE, authFile } from './paths.js';

/** Seed one login per role (autotest schema only), then sign each one in once and keep the session. */
export default async function globalSetup(config) {
    mkdirSync(STATE_DIR, { recursive: true });

    const seed = spawnSync('php', ['tests/Browser/support/browser-db.php', 'setup', STATE_FILE], { encoding: 'utf8' });
    if (seed.status !== 0) {
        throw new Error('Could not seed the browser test data:\n' + (seed.stderr || seed.stdout));
    }

    const state = JSON.parse(readFileSync(STATE_FILE, 'utf8'));
    const baseURL = config.projects[0].use.baseURL;

    const browser = await chromium.launch();
    try {
        for (const [role, user] of Object.entries(state.users)) {
            const context = await browser.newContext({ baseURL });
            const page = await context.newPage();
            // Slow machines (Windows test server + remote database) need far more than Playwright's default 30 seconds.
            page.setDefaultTimeout(180_000);
            page.setDefaultNavigationTimeout(180_000);
            const started = Date.now();
            await page.goto('/login');
            await page.fill('input[name="username"]', user.username);
            await page.fill('input[name="password"]', state.password);
            try {
                await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login')), page.click('button:has-text("Log in")')]);
            } catch (error) {
                const shown = (await page.locator('body').innerText().catch(() => '')).slice(0, 300).replace(/\s+/g, ' ');
                throw new Error(`Sign-in as ${role} did not finish. Page: ${page.url()} | Text: ${shown} | ${error.message}`);
            }
            console.log(`[setup] signed in as ${role} in ${Math.round((Date.now() - started) / 1000)}s`);
            await context.storageState({ path: authFile(role) });
            await context.close();
        }
    } finally {
        await browser.close();
    }
}
