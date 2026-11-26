import { defineConfig, devices } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';

/**
 * BROWSER TESTS for the Admin Portal (real Chromium clicking through real pages).
 *
 *   npm run build            (once, and after changing resources/js or resources/css)
 *   npm run test:browser
 *
 * They use the SAME database settings as the PHPUnit Pgsql suite (.env.testing, isolated "autotest" schema).
 * The safety check below runs before anything else and stops the whole run unless the connection is the
 * autotest schema with an autotest_user login. Everything that could reach the outside world (the C# API,
 * the gateway, mail, queues) is switched off or pointed at a dead address for the test server.
 */

const PORT = 8765;
const BASE = `http://127.0.0.1:${PORT}`;
const STUB_PORT = 8766;
const STUB_URL = `http://127.0.0.1:${STUB_PORT}`;

const guard = spawnSync('php', ['tests/Browser/support/browser-db.php', 'check'], { encoding: 'utf8' });
if (guard.status !== 0) {
    throw new Error(
        'BROWSER TESTS STOPPED (safety check).\n' + (guard.stderr || guard.stdout || guard.error?.message || '') +
        '\nThey only run on the isolated autotest schema (.env.testing).'
    );
}

if (!existsSync('public/build/manifest.json')) {
    throw new Error('The front-end files are not built yet. Run:  npm run build   then try again.');
}

export default defineConfig({
    testDir: './tests/Browser',
    testMatch: '**/*.spec.js',
    globalSetup: './tests/Browser/support/global-setup.js',
    globalTeardown: './tests/Browser/support/global-teardown.js',
    // One worker: the test server is PHP's built-in server and the tests share one seeded database.
    workers: 1,
    fullyParallel: false,
    retries: 0,
    // Generous limits: they are only upper bounds. On Windows PHP's test server answers one request at a time and the
    // test database is remote, so a single page can take many seconds. A fast machine never reaches these limits.
    timeout: 240_000,
    expect: { timeout: 30_000 },
    reporter: [['list'], ['html', { open: 'never', outputFolder: 'tests/Browser/.report' }]],
    outputDir: 'tests/Browser/.results',
    use: {
        baseURL: BASE,
        navigationTimeout: 120_000,
        actionTimeout: 60_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        locale: 'en-US',
        timezoneId: 'Asia/Colombo',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: [
        {
            // Local stand-in for the mobile API and the gateway: answers instantly with 503 (see stub-server.js).
            command: 'node tests/Browser/support/stub-server.js',
            port: STUB_PORT,
            reuseExistingServer: false,
            stdout: 'ignore',
            stderr: 'pipe',
            timeout: 30_000,
        },
        {
            command: 'php -d max_execution_time=300 -S 127.0.0.1:' + PORT + ' -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php',
            cwd: 'public',
            url: `${BASE}/login`,
            reuseExistingServer: false,
            stdout: 'ignore',
            stderr: 'pipe',
            timeout: 60_000,
            env: {
                ...process.env,
                APP_ENV: 'testing',
                APP_DEBUG: 'false',
                APP_URL: BASE,
                SESSION_DRIVER: 'file',
                SESSION_SECURE_COOKIE: 'false',
                CACHE_STORE: 'file',
                QUEUE_CONNECTION: 'sync',
                MAIL_MAILER: 'array',
                BROADCAST_CONNECTION: 'null',
                BCRYPT_ROUNDS: '4',
                // Nothing in a browser test may reach the real mobile API, gateway or any paid service.
                SHALOTRACK_API_BASE_URL: STUB_URL,
                SHALOTRACK_API_TOKEN: '',
                SHALOTRACK_SYNC_KEY: 'browser-test-not-a-real-key',
                GATEWAY_COMMAND_URL: STUB_URL,
                GATEWAY_COMMAND_API_KEY: '',
                GATEWAY_PUBLIC_PROBE: 'false',
                SUPPLIER_DELIVERIES_ENABLED: 'false',
                PHP_CLI_SERVER_WORKERS: '4',
            },
        },
    ],
});
