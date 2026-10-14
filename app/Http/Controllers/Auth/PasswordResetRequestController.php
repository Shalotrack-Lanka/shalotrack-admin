<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PasswordResetRequest;
use App\Services\Audit;
use App\Support\ClientIp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * "Forgot your password?" on the login page. Nothing is emailed: the person gives their username, an
 * administrator is shown the request in the portal and sets a temporary password.
 *
 * The answer on screen is always the same, so the form cannot be used to find out which usernames exist.
 * A request row is created only for a real, active account, and only one can be waiting per account.
 */
class PasswordResetRequestController extends Controller
{
    private const MAX_PER_ADDRESS = 5;
    private const WINDOW_SECONDS = 900;

    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:100']]);

        $key = 'password-request:' . ClientIp::resolve($request);
        if (RateLimiter::tooManyAttempts($key, self::MAX_PER_ADDRESS)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($key) / 60));

            return back()->withInput()->withErrors(['username' => "Too many requests from this network. Try again in {$minutes} minute(s)."]);
        }
        RateLimiter::hit($key, self::WINDOW_SECONDS);

        $account = Admin::where('username', trim($data['username']))->where('status', 'ACTIVE')->first();

        if ($account && ! PasswordResetRequest::where('admin_id', $account->admin_id)->where('status', PasswordResetRequest::PENDING)->exists()) {
            PasswordResetRequest::create([
                'admin_id'     => $account->admin_id,
                'username'     => $account->username,
                'status'       => PasswordResetRequest::PENDING,
                'requested_ip' => mb_substr((string) ClientIp::resolve($request), 0, 45),
                'requested_at' => now(),
            ]);
            PasswordResetRequest::forgetPendingCount();

            // Not signed in, so the person is passed as the actor.
            Audit::record('auth.password_reset_requested', 'admin', $account->admin_id, $account->username, [], [], $account);
        }

        return back()->with('status', 'Request sent. If that username belongs to an active account, a ShaloTrack administrator has been told and will contact you with a temporary password. Nothing is sent by email.');
    }
}