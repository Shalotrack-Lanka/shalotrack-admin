<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PasswordResetRequest;
use App\Services\Audit;
use App\Services\TemporaryPassword;
use App\Support\PasswordPolicy;
use App\Support\ReauthGuard;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ADMIN only (route middleware), reached from the hidden "Administration" section of the profile page:
 *  - add a new administrator,
 *  - answer a "forgot password" request by setting a temporary password,
 *  - dismiss a request.
 * Every action that creates access or sets someone's password asks for the signed-in administrator's own
 * password again, and is written to the audit log.
 */
class AdminUserController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validateWithBag('addAdmin', [
            'full_name'        => ['required', 'string', 'max:150'],
            'username'         => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{4,40}$/', $this->notTaken('username', 'That username is already taken.')],
            'email'            => ['required', 'email:rfc', 'max:190', $this->notTaken('email', 'Another account already uses this email.')],
            'phone_number'     => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\s-]{7,20}$/'],
            'password'         => ['required', 'string', 'confirmed', PasswordPolicy::rule()],
            'current_password' => ['required', 'string'],
        ], [
            'username.regex'     => 'Use 4 to 40 letters, numbers, dots, dashes or underscores (no spaces).',
            'phone_number.regex' => 'Enter a phone number using digits, spaces, + ( ) and - only.',
        ]);

        ReauthGuard::confirm($request, 'addAdmin', 'add administrator');

        $phone = trim((string) $request->input('phone_number'));

        $admin = new Admin();
        $admin->forceFill([
            'admin_id'             => (string) Str::uuid(),
            'username'             => trim($request->input('username')),
            'password'             => Hash::make($request->input('password')),
            'full_name'            => trim($request->input('full_name')),
            'email'                => trim($request->input('email')),
            'phone_number'         => $phone !== '' ? $phone : null,
            'role'                 => 'ADMIN',
            'status'               => 'ACTIVE',
            'must_change_password' => true,
        ])->save();

        Audit::record('admin.created', 'admin', $admin->admin_id, $admin->username, [], [
            'full_name'            => $admin->full_name,
            'email'                => $admin->email,
            'role'                 => 'ADMIN',
            'must_change_password' => true,
        ]);

        return redirect()->route('admin.profile')->with('success', "Administrator '{$admin->username}' was added. They must choose their own password the first time they sign in.");
    }

    /** Sets a temporary password for the person who asked, closes the request, shows the password ONCE. */
    public function resolveReset(Request $request, int $requestId): RedirectResponse
    {
        $request->validateWithBag('resetRequest', [
            'current_password'   => ['required', 'string'],
            'temporary_password' => ['nullable', 'string', PasswordPolicy::rule()],
        ]);

        ReauthGuard::confirm($request, 'resetRequest', 'set temporary password');

        $me = $request->user();
        $req = PasswordResetRequest::where('status', PasswordResetRequest::PENDING)->findOrFail($requestId);
        $target = Admin::find($req->admin_id);

        if (! $target || $target->status !== 'ACTIVE') {
            $this->close($req, PasswordResetRequest::DISMISSED, $me->admin_id);

            return back()->withErrors(['request' => 'That account no longer exists or is not active, so the request was closed.'], 'resetRequest');
        }

        if ($target->admin_id === $me->admin_id) {
            return back()->withErrors(['request' => 'This is your own account. Use "Change password" above instead.'], 'resetRequest');
        }

        $result = TemporaryPassword::set(
            $target,
            $me,
            (string) $request->input('temporary_password'),
            'temporary password set by an administrator',
            ['request_id' => $req->id],
        );
        // (set() has also closed this request: every waiting request for that login is marked DONE)

        return redirect()->route('admin.profile')->with('temp_password', [
            'username'  => $target->username,
            'name'      => $target->full_name,
            'password'  => $result['password'],
            'generated' => $result['generated'],
        ]);
    }

    public function dismissReset(Request $request, int $requestId): RedirectResponse
    {
        $req = PasswordResetRequest::where('status', PasswordResetRequest::PENDING)->findOrFail($requestId);
        $this->close($req, PasswordResetRequest::DISMISSED, $request->user()->admin_id);

        Audit::record('account.password_reset_dismissed', 'admin', $req->admin_id, $req->username, [], ['request_id' => $req->id]);

        return redirect()->route('admin.profile')->with('success', 'The request was dismissed.');
    }

    private function close(PasswordResetRequest $req, string $status, string $byAdminId): void
    {
        $req->update(['status' => $status, 'resolved_by' => $byAdminId, 'resolved_at' => now()]);
        PasswordResetRequest::forgetPendingCount();
    }

    /** Case-insensitive "not used by any account" rule: "Admin01" and "admin01" count as the same name. */
    private function notTaken(string $column, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($column, $message) {
            if (is_string($value) && Admin::whereRaw("lower({$column}) = ?", [mb_strtolower(trim($value))])->exists()) {
                $fail($message);
            }
        };
    }
}