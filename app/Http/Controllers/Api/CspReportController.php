<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CspReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Receives Content-Security-Policy violation reports from browsers (POST /api/csp-report).
 *
 * Public on purpose: browsers send reports without cookies, so anyone can post here. That makes it a place
 * an attacker could try to fill the log or the disk, hence the layers below, cheapest check first:
 *   1. per-IP limit on the route (throttle middleware),
 *   2. a global per-minute cap and a global per-day cap (the per-IP limit alone is not enough because the
 *      client IP is taken from X-Forwarded-For and trustProxies('*') lets a client pick it),
 *   3. identical reports (same directive, blocked URL, page and script) are logged once per hour,
 *   4. CspReport keeps only a fixed set of short, sanitised fields.
 * It always answers 204: the browser does not read the answer and a prober learns nothing.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $noContent = response('', 204);

        if (! RateLimiter::attempt('csp-report:minute', (int) config('security.csp.report_max_per_minute', 120), fn () => true, 60)) {
            return $noContent;
        }

        foreach (CspReport::parse($request->getContent()) as $row) {
            $fingerprint = 'csp-report:seen:' . md5(implode('|', [$row['directive'], $row['blocked'], $row['document'], $row['source'], $row['line']]));

            // add() is false when the same report was already logged in the last hour.
            if (! Cache::add($fingerprint, 1, 3600)) {
                continue;
            }

            if (! RateLimiter::attempt('csp-report:day', (int) config('security.csp.report_max_per_day', 20000), fn () => true, 86400)) {
                break;
            }

            Log::channel('csp')->warning('csp-violation', $row);
        }

        return $noContent;
    }
}
