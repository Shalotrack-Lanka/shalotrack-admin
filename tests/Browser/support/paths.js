import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export const STATE_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', '.state');
export const STATE_FILE = join(STATE_DIR, 'state.json');
export const authFile = (role) => join(STATE_DIR, `auth-${role}.json`);
