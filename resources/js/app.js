import './bootstrap';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

import './country-state-select.js';

// Alpine and its plugin are bundled from the lock file (no third-party CDN script runs in the admin portal).
Alpine.plugin(collapse);

window.Alpine = Alpine;

Alpine.start();
