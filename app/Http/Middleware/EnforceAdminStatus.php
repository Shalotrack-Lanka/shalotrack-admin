<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnforceAdminStatus
 *
 * Runs on every authenticated web request. If the currently logged-in
 * user's Admin.status is no longer ACTIVE (i.e. an admin deactivated
 * their account while they were mid-session), they are immediately
 * logged out and redirected to the login page with an explanation.
 *
 * Without this, LoginRequest's ->where('status', 'ACTIVE') check only
 * fires at login time — a dealer or supplier already inside the portal
 * would stay there indefinitely even after being deactivated, because
 * Laravel sessions don't re-run the login guard on every request.
 *
 * This middleware closes that window completely.
 */
class EnforceAdminStatus
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Not logged in — let the standard 'auth' middleware handle it.
        if (! $user) {
            return $next($request);
        }

        // Account has been deactivated since this session was created.
        if ($user->status !== 'ACTIVE') {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->withErrors([
                    'username' => 'Your account has been deactivated. Please contact an administrator.',
                ]);
        }

        return $next($request);
    }
}