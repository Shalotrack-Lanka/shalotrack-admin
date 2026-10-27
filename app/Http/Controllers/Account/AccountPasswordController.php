<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Audit;
use App\Support\PasswordPolicy;
use App\Support\ReauthGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * A signed-in person choosing a new password for themselves. Used by every role:
 *  - the "you must choose a new password" screen (new account, or temporary password from an administrator),
 *  - the "Change password" card on the profile page.
 */
class AccountPasswordController extends Controller
{
    public function edit(Request $request): View
    {
        return view('auth.force-password-change', [
            'forced' => (bool) $request->user()->must_change_password,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $wasForced = (bool) $user->must_change_password;
        $bag = $wasForced ? 'default' : 'changePassword';

        $request->validateWithBag($bag, [
            'current_password' => ['required', 'string', 'max:255'],
            'password'         => ['required', 'string', 'max:' . PasswordPolicy::MAX_LENGTH, 'confirmed', PasswordPolicy::rule(), 'different:current_password'],
        ], [
            'password.different' => 'Choose a password that is different from the current one.',
        ]);

        ReauthGuard::confirm($request, $bag, 'change own password');

        $new = (string) $request->input('password');

        $user->forceFill([
            'password'             => Hash::make($new),
            'must_change_password' => false,
            'password_changed_at'  => now(),
            'remember_token'       => Str::random(60),
        ])->save();

        // Every OTHER browser this person is signed in on is signed out (uses the AuthenticateSession
        // middleware); this one stays signed in with a fresh session id.
        Auth::logoutOtherDevices($new);
        $request->session()->regenerate();

        Audit::record('account.password_changed', 'admin', $user->admin_id, $user->username, [], [
            'forced_change'        => $wasForced,
            'other_browsers'       => 'signed out',
        ]);

        if ($wasForced) {
            return redirect()->route($user->homeRouteName())->with('success', 'Your new password is set.');
        }

        return back()->with('success', 'Your password was changed. Any other browser you were signed in on has been signed out.');
    }
}