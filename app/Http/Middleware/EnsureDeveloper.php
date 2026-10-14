<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware(['role:ADMIN', 'developer'])
 *
 * Only the company's developer login (see config/erp.php) gets through; every other account, other
 * administrators included, receives 403.
 */
class EnsureDeveloper
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'isDeveloper') || ! $user->isDeveloper()) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}