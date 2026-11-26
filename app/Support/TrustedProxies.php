<?php

namespace App\Support;

/**
 * Which senders the app believes when they say "the real visitor is X" (the X-Forwarded-For header).
 *
 * Before: every sender was believed. Anyone who could reach the app could send a new made-up address
 * on every request, so every limit that counts requests per address (the login lockout of 5 wrong
 * passwords, every throttle:N,1 route) could be walked around by changing the header each time.
 *
 * Now: only private addresses are believed. In production the only thing that connects to the app is the
 * load balancer and the container network, which are private (10.x, 172.16-31.x, 192.168.x). A direct
 * caller from the internet has a public address, so the header they send is ignored.
 *
 * TRUSTED_PROXIES in .env (or the container environment) can narrow this to the exact VPC range, for
 * example "10.0.0.0/16", or list several ranges separated by commas. A lone "*" brings the old
 * "believe everyone" behaviour back; it is the roll-back switch and must be typed on purpose.
 */
final class TrustedProxies
{
    public const DEFAULT = ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '127.0.0.1', '::1'];

    /** @return array<int,string>|string  A list of addresses or ranges, or '*' only when '*' was written. */
    public static function fromEnv(?string $value): array|string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return self::DEFAULT;
        }

        if ($value === '*') {
            return '*';
        }

        // A wildcard mixed into a list is never allowed to open everything up; it is simply dropped.
        $list = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $entry) => $entry !== '' && ! str_contains($entry, '*')
        ));

        return $list === [] ? self::DEFAULT : $list;
    }
}
