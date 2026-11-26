import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync, rmSync } from 'node:fs';
import { STATE_DIR, STATE_FILE } from './paths.js';

/** Remove every row the seed created (by id), even when tests failed. */
export default async function globalTeardown() {
    // The saved sign-in sessions are useless once their accounts are gone.
    if (existsSync(STATE_DIR)) {
        for (const file of readdirSync(STATE_DIR)) {
            if (file.startsWith('auth-')) rmSync(`${STATE_DIR}/${file}`, { force: true });
        }
    }
    if (!existsSync(STATE_FILE)) return;
    const out = spawnSync('php', ['tests/Browser/support/browser-db.php', 'teardown', STATE_FILE], { encoding: 'utf8' });
    if (out.status !== 0) {
        process.exitCode = 1;
        console.error('BROWSER TEST CLEANUP FAILED. Rows named "autotest-browser-*" may remain in the autotest schema:\n' + (out.stderr || out.stdout));
    }
}
