<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Protects endpoints called BY the C# API (the reverse direction of the
 * existing sync_key usage, where THIS app calls INTO the API's own
 * /api/internal endpoints). Reuses the exact same shared secret --
 * SHALOTRACK_SYNC_KEY here, AdminSync:Key on the API side -- rather than
 * introducing a second one, since both systems already agree on this
 * value today.
 *
 * hash_equals() for the comparison, matching the timing-attack-resistant
 * standard the API side already holds itself to via
 * CryptographicOperations.FixedTimeEquals.
 */
class VerifyAdminSyncKey
{
    public function handle(Request $request, Closure $next)
    {
        $expected = config('services.shalotrack_api.sync_key');
        $provided = $request->header('X-Admin-Sync-Key', '');

        if (empty($expected) || !hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        return $next($request);
    }
}