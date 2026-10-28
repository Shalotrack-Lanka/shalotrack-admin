<?php

namespace App\Http\Middleware;

use App\Support\SecurityHeaderPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the browser-hardening response headers (config/security.php explains each one).
 *
 * Registered as GLOBAL middleware (not in the 'web' group) so that a 404 for an unknown URL, a 500 error page,
 * the health check and the JSON endpoints are covered as well. Headers a controller already set are left alone.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.headers.enabled')) {
            return $next($request);
        }

        // One nonce per request. @vite() puts it on its own tags; a view that needs an inline script writes
        // <script nonce="{{ Vite::cspNonce() }}"> and it is then allowed by the policy.
        $nonce = SecurityHeaderPolicy::nonce();
        Vite::useCspNonce($nonce);

        $response = $next($request);

        $this->apply($response, $nonce);

        return $response;
    }

    private function apply(Response $response, string $nonce): void
    {
        $headers = $response->headers;

        $set = static function (string $name, ?string $value) use ($headers): void {
            if ($value !== null && $value !== '' && ! $headers->has($name)) {
                $headers->set($name, $value);
            }
        };

        $set('X-Content-Type-Options', 'nosniff');
        $set('X-Frame-Options', config('security.headers.x_frame_options'));
        $set('Referrer-Policy', config('security.headers.referrer_policy'));
        $set('Permissions-Policy', config('security.headers.permissions_policy'));
        $set('Strict-Transport-Security', SecurityHeaderPolicy::hsts((int) config('security.headers.hsts_max_age')));

        // CSP only means something for pages. A response with no Content-Type yet (error pages are not
        // prepared at this point) is a page; JSON, PDFs and downloads are not.
        $type = strtolower((string) $headers->get('Content-Type', ''));

        if ($type !== '' && ! str_starts_with($type, 'text/html')) {
            return;
        }

        $mode = SecurityHeaderPolicy::mode(config('security.csp.mode'));

        if ($mode === SecurityHeaderPolicy::MODE_OFF) {
            return;
        }

        $policy = SecurityHeaderPolicy::csp(
            (array) config('security.csp.directives'),
            $nonce,
            (bool) config('security.csp.allow_unsafe_eval'),
            config('security.csp.report_uri'),
        );

        $set(
            $mode === SecurityHeaderPolicy::MODE_ENFORCE ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only',
            $policy,
        );
    }
}
