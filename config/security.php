<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security response headers
    |--------------------------------------------------------------------------
    |
    | Added to every response by App\Http\Middleware\SecurityHeaders (global middleware, so 404 / 500 pages
    | and JSON responses get them too). A controller that sets one of these headers itself keeps its value.
    |
    | SECURITY_HEADERS_ENABLED=false is the emergency switch for ALL of them (CSP included).
    |
    | HSTS (Strict-Transport-Security): once a browser has seen it, it refuses plain http for this host until the
    | max-age runs out, so ramp it up in steps and never skip ahead:
    |     86400 (1 day, the production default) -> 604800 (1 week) -> 2592000 (30 days) -> 31536000 (1 year)
    | Move to the next step only after a week with no sign-in / certificate problems. 0 = header not sent.
    | includeSubDomains and preload are deliberately NOT sent: other ShaloTrack sites share this domain.
    | The header is sent regardless of $request->isSecure(): behind Cloudflare + the ALB the app sees http,
    | and browsers ignore HSTS received over plain http anyway, so local development is unaffected.
    |
    */
    'headers' => [
        'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),

        'hsts_max_age' => (int) env('HSTS_MAX_AGE', env('APP_ENV', 'production') === 'production' ? 86400 : 0),

        // The admin portal is never shown inside another page (no iframes anywhere in resources/views).
        'x_frame_options' => 'DENY',

        'referrer_policy' => 'strict-origin-when-cross-origin',

        // Features the portal never uses are switched off, so injected script cannot ask for them.
        'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    | CSP_MODE:
    |   report-only (default)  the browser only REPORTS what the policy would have blocked (to /api/csp-report,
    |                          read with `php artisan csp:summary`); nothing is blocked, nothing can break.
    |   enforce                the browser BLOCKS violations. Do not use until csp:summary has been clean for a
    |                          week on every role's pages (see the rollout notes in the project docs).
    |   off                    no CSP header.
    | Any other value behaves like report-only.
    |
    | The policy below is the TARGET policy, written from what resources/views really load today. It has no
    | 'unsafe-inline' for scripts on purpose, so the reports list every inline <script> block and every
    | onclick="..." style handler that has to move to a nonce (<script nonce="{{ Vite::cspNonce() }}">) or an
    | addEventListener before enforcement is possible. '{nonce}' is replaced with the per-request nonce.
    | Inline style="" attributes are allowed (style-src-attr), because style injection is low risk next to script.
    |
    | KNOWN DEBT: Alpine's standard build compiles x-data / @click expressions with new Function(), which needs
    | 'unsafe-eval'. CSP_ALLOW_UNSAFE_EVAL=false drops it once Alpine is switched to the CSP build.
    |
    */
    'csp' => [
        'mode' => env('CSP_MODE', 'report-only'),

        'allow_unsafe_eval' => (bool) env('CSP_ALLOW_UNSAFE_EVAL', true),

        'report_uri' => '/api/csp-report',

        'directives' => [
            'default-src'     => ["'self'"],
            'script-src'      => ["'self'", "'nonce-{nonce}'", 'https://cdn.jsdelivr.net', 'https://unpkg.com', 'https://www.gstatic.com'],
            'style-src'       => ["'self'", "'nonce-{nonce}'", 'https://unpkg.com', 'https://fonts.bunny.net'],
            'style-src-attr'  => ["'unsafe-inline'"],
            'img-src'         => ["'self'", 'data:', 'blob:', 'https://unpkg.com', 'https://*.tile.openstreetmap.org', 'https://cdn-icons-png.flaticon.com'],
            'font-src'        => ["'self'", 'data:', 'https://fonts.bunny.net'],
            'media-src'       => ["'self'", 'https://assets.mixkit.co'],
            // Same-origin calls plus the Firebase Cloud Messaging endpoints the dealer dashboard registers with.
            'connect-src'     => ["'self'", 'https://fcmregistrations.googleapis.com', 'https://firebaseinstallations.googleapis.com', 'https://fcm.googleapis.com'],
            'worker-src'      => ["'self'"],
            'manifest-src'    => ["'self'"],
            'frame-src'       => ["'none'"],
            'object-src'      => ["'none'"],
            'base-uri'        => ["'self'"],
            'form-action'     => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ],

        /*
        | The report collector (POST /api/csp-report) is public by necessity (the browser sends reports without
        | cookies), so it is capped hard: a flood can fill neither the log nor the disk.
        |   per IP per minute is set on the route; these are the global caps (they matter because
        |   trustProxies('*') lets a client choose its own apparent IP).
        */
        'report_max_per_minute' => (int) env('CSP_REPORT_MAX_PER_MINUTE', 120),
        'report_max_per_day'    => (int) env('CSP_REPORT_MAX_PER_DAY', 20000),
    ],

];
