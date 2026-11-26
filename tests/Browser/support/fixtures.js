import { test as base, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { STATE_FILE } from './paths.js';

export { expect };
export const state = () => JSON.parse(readFileSync(STATE_FILE, 'utf8'));

const isLocal = (url) => ['127.0.0.1', 'localhost'].includes(new URL(url).hostname);

/**
 * `page` with a watcher on it. After the page has been used, `page.problems` lists everything that went wrong
 * in the browser: uncaught JavaScript errors, console errors, and server answers of 500 or more.
 *
 * No test ever talks to the internet: every request to another host is cancelled. A few screens load a library
 * from a CDN (maps, Firebase). When such a script was cancelled, the resulting "X is not defined" is expected
 * and is not reported; every other error still is.
 */
export const test = base.extend({
    page: async ({ page, context }, use) => {
        const problems = [];
        let cancelledScripts = 0;

        await context.route((url) => !isLocal(url.toString()), (route) => {
            if (route.request().resourceType() === 'script') cancelledScripts++;
            return route.abort();
        });

        const missingLibrary = (text) => cancelledScripts > 0 && /\b\w+ is not defined\b/.test(text);

        page.on('pageerror', (error) => {
            if (!missingLibrary(error.message)) problems.push(`JavaScript error: ${error.message}`);
        });
        page.on('console', (msg) => {
            if (msg.type() !== 'error') return;
            const text = msg.text();
            // Cancelled outside resources and plain resource failures are reported through `response` below.
            if (/Failed to load resource|net::ERR_/.test(text) || missingLibrary(text)) return;
            problems.push(`Console error: ${text}`);
        });
        page.on('response', (response) => {
            if (isLocal(response.url()) && response.status() >= 500) {
                problems.push(`Server answered ${response.status()} for ${response.request().method()} ${new URL(response.url()).pathname}`);
            }
        });

        page.problems = problems;
        await use(page);
    },
});

/** Open a page and give the page's own scripts a moment to run their first requests. */
export async function openAndSettle(page, path) {
    const response = await page.goto(path, { waitUntil: 'load' });
    await page.waitForTimeout(600);
    return response;
}
