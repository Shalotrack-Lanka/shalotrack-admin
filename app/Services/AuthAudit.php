<?php

namespace App\Services;

use App\Models\Admin;
use App\Support\ClientIp;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign-in trail: who signed in, who signed out, who failed to, and who got locked out.
 *
 * Registered with Event::subscribe() in AppServiceProvider (deliberately NOT in app/Listeners, which
 * Laravel scans automatically, so nothing can register twice).
 *
 * Rules:
 *  - A failure here must never block a sign-in or sign-out: every handler swallows its own errors.
 *  - Failed attempts are rate-limited per username + address and per address, so someone hammering
 *    the login form cannot flood the (append-only) table.
 *  - The username typed on a failed attempt is stored ONLY when it matches a real account. A person
 *    who types their password into the username box, or an attacker sending junk, never gets that
 *    text copied into the log.
 */
class AuthAudit
{
    private const FAILED_PER_ADDRESS_PER_HOUR = 30;

    /** @return array<class-string,string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class   => 'onLogin',
            Logout::class  => 'onLogout',
            Lockout::class => 'onLockout',
        ];
    }

    public function onLogin(Login $event): void
    {
        try {
            $user = $event->user;
            $request = app()->bound('request') ? request() : null;
            $viaForm = $request !== null && $request->isMethod('POST') && $request->is('login');

            // The guard stores the user only AFTER it fires this event, so the actor is passed in.
            Audit::record('auth.login', 'admin', $user->admin_id ?? null, $user->username ?? null, [], [
                'how' => $viaForm ? 'username and password' : 'remember-me cookie (new browser session)',
            ], $user);
        } catch (Throwable $e) {
            Log::warning('Sign-in audit failed', ['error' => $e->getMessage()]);
        }
    }

    public function onLogout(Logout $event): void
    {
        try {
            $user = $event->user;
            if (! $user) {
                return;
            }

            $request = app()->bound('request') ? request() : null;
            $why = $request?->attributes->get('audit.logout_reason');

            Audit::record('auth.logout', 'admin', $user->admin_id ?? null, $user->username ?? null, [], [
                'how' => is_string($why) ? $why : 'signed out by the user',
            ], $user);
        } catch (Throwable $e) {
            Log::warning('Sign-out audit failed', ['error' => $e->getMessage()]);
        }
    }

    /** Fired by the login form on EVERY blocked attempt, so only the first per 5 minutes is written. */
    public function onLockout(Lockout $event): void
    {
        try {
            $request = $event->request;
            $typed = is_string($request->input('username')) ? $request->input('username') : '';
            $address = ClientIp::resolve($request) ?? 'unknown';

            if (! Cache::add('audit:lockout:' . sha1(Str::lower($typed) . '|' . $address), 1, 300)) {
                return;
            }

            $account = $typed !== '' ? Admin::where('username', $typed)->first() : null;

            Audit::record('auth.lockout', 'admin', $account?->admin_id, $account?->username ?? '(no such account)', [], [
                'why' => 'too many failed attempts in a row; sign-in blocked for a few minutes',
            ]);
        } catch (Throwable $e) {
            Log::warning('Lockout audit failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A refused sign-in. Called by LoginRequest (it checks the password itself, so Laravel's own
     * "Failed" event never fires for this form).
     */
    public static function failed(Request $request, ?Admin $account, string $why): void
    {
        try {
            $typed = is_string($request->input('username')) ? Str::lower($request->input('username')) : '';
            $address = ClientIp::resolve($request) ?? 'unknown';

            // One row per username + address per minute.
            if (! Cache::add('audit:failed:' . sha1($typed . '|' . $address), 1, 60)) {
                return;
            }

            // And a ceiling per address, with a single marker row when it is reached.
            $key = 'audit:failed-address:' . sha1($address);
            Cache::add($key, 0, 3600);
            $count = (int) Cache::increment($key);
            if ($count > self::FAILED_PER_ADDRESS_PER_HOUR + 1) {
                return;
            }
            if ($count === self::FAILED_PER_ADDRESS_PER_HOUR + 1) {
                Audit::record('auth.login_failed', 'admin', null, '(many accounts)', [], [
                    'why' => 'more than ' . self::FAILED_PER_ADDRESS_PER_HOUR . ' failed attempts from this address in an hour; further ones are not recorded until it passes',
                ]);

                return;
            }

            Audit::record('auth.login_failed', 'admin', $account?->admin_id, $account?->username ?? '(no such account)', [], [
                'why' => $why,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed-sign-in audit failed', ['error' => $e->getMessage()]);
        }
    }
}