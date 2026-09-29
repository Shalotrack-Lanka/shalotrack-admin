<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsureRole
 *
 * Usage: ->middleware('role:ADMIN')  or  ->middleware('role:ADMIN,FINANCE')
 *
 * The portal previously relied on 'auth' alone, which means any logged-in
 * DEALER / SUPPLIER / TECHNICIAN could call an admin-only URL directly.
 * This checks Admins.role against the allowed list and returns 403 otherwise.
 * (Inactive accounts are already logged out by EnforceAdminStatus.)
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, true)) {
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}