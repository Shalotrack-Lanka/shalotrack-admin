<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The real visitor address for audit entries.
 *
 * Traffic reaches the portal as visitor -> Cloudflare -> load balancer -> app. Laravel's own
 * $request->ip() therefore returns a Cloudflare edge address (172.64.x.x, 104.x.x.x ...), which is
 * useless in an audit trail. Cloudflare puts the real visitor in CF-Connecting-IP, but that header
 * is only believable when the request really came through Cloudflare: anyone who calls the load
 * balancer directly can send any value in it.
 *
 * So the header is trusted only when the nearest PUBLIC hop in X-Forwarded-For (the address the
 * load balancer itself saw connecting) is one of Cloudflare's published ranges. A direct caller
 * shows up there with their own address, fails that test, and falls back to $request->ip().
 *
 * Ranges: https://www.cloudflare.com/ips/ . They change rarely; if one is missing the only effect
 * is the old behaviour (a Cloudflare address in the log), never a wrong or spoofed one.
 */
class ClientIp
{
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function resolve(?Request $request): ?string
    {
        if ($request === null) {
            return null;
        }

        $claimed = trim((string) $request->headers->get('CF-Connecting-IP', ''));
        if ($claimed !== '' && filter_var($claimed, FILTER_VALIDATE_IP) && self::arrivedViaCloudflare($request)) {
            return $claimed;
        }

        return $request->ip();
    }

    /** True when the nearest public address in X-Forwarded-For belongs to Cloudflare. */
    private static function arrivedViaCloudflare(Request $request): bool
    {
        $hops = array_reverse(array_values(array_filter(
            array_map('trim', explode(',', (string) $request->headers->get('X-Forwarded-For', ''))),
            fn ($hop) => $hop !== ''
        )));

        foreach ($hops as $hop) {
            if (! filter_var($hop, FILTER_VALIDATE_IP)) {
                return false;
            }
            // Our own load balancer or proxy (private / reserved address): look one hop further out.
            if (! filter_var($hop, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                continue;
            }

            return IpUtils::checkIp($hop, self::CLOUDFLARE_RANGES);
        }

        return false;
    }
}