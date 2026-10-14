<?php

namespace App\Support;

use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * "Type your own password again" before a sensitive action (adding an administrator, setting someone's
 * temporary password, changing your own password). A walked-away-from or hijacked session alone cannot
 * do these things. Limited to 5 wrong tries per 10 minutes per person; every wrong try is audited.
 */
final class ReauthGuard
{
    private const MAX_ATTEMPTS = 5;
    private const DECAY_SECONDS = 600;

    /**
     * @param string $field  request field holding the person's current password
     * @param string $bag    validation error bag to put the error in ('' = default bag)
     * @param string $what   short note for the audit entry
     *
     * @throws ValidationException
     */
    public static function confirm(Request $request, string $bag = '', string $what = 'sensitive action', string $field = 'current_password'): void
    {
        $user = $request->user();
        $key = 'reauth:' . ($user?->admin_id ?? 'guest');

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));
            self::fail($field, "Too many wrong passwords. Try again in {$minutes} minute(s).", $bag);
        }

        if (! $user || ! is_string($request->input($field)) || ! Hash::check($request->input($field), (string) $user->getAuthPassword())) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            Audit::record('auth.reauth_failed', 'admin', $user?->admin_id, $user?->username, [], ['for' => $what]);

            self::fail($field, 'That is not your current password.', $bag);
        }

        RateLimiter::clear($key);
    }

    private static function fail(string $field, string $message, string $bag): never
    {
        $e = ValidationException::withMessages([$field => $message]);
        if ($bag !== '') {
            $e->errorBag($bag);
        }

        throw $e;
    }
}