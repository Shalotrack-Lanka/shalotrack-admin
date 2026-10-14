<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a signed-in person on the "choose a new password" screen while Admins.must_change_password is
 * set (a new account, or a temporary password set by an administrator). Only that screen and Logout work
 * until the password is changed. Applies to every role.
 */
class EnforcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || ! $user->must_change_password || $request->routeIs('account.password.*', 'logout')) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Choose a new password first.'], 423);
        }

        return redirect()->route('account.password.edit');
    }
}