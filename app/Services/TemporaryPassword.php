<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\PasswordResetRequest;
use App\Support\PasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sets a TEMPORARY password on someone else's login: the one place that does it, used by both the
 * forgot-password queue and the developer's account directory.
 *
 *  - the new password is stored only as a hash; the caller shows it ONCE on screen and it is never logged,
 *  - the person is forced to choose their own at their next sign-in (must_change_password),
 *  - their other sessions end (the stored password hash no longer matches, see AuthenticateSession),
 *  - any waiting forgot-password request for that login is closed,
 *  - an audit entry records who did it, to whom, and how (never the password).
 */
final class TemporaryPassword
{
    /**
     * @param string|null          $typed  a password the administrator typed (already validated), or null to generate one
     * @param array<string,mixed>  $meta   extra audit details, e.g. ['request_id' => 12]
     * @return array{password:string,generated:bool}
     */
    public static function set(Admin $target, Admin $by, ?string $typed, string $how, array $meta = []): array
    {
        $typed = $typed !== null ? trim($typed) : '';
        $generated = $typed === '';
        $temporary = $generated ? PasswordPolicy::generate() : $typed;

        DB::transaction(function () use ($target, $by, $temporary) {
            $target->forceFill([
                'password'             => Hash::make($temporary),
                'must_change_password' => true,
                'password_changed_at'  => null,
                'remember_token'       => Str::random(60),
            ])->save();

            PasswordResetRequest::where('admin_id', $target->admin_id)
                ->where('status', PasswordResetRequest::PENDING)
                ->update(['status' => PasswordResetRequest::DONE, 'resolved_by' => $by->admin_id, 'resolved_at' => now(), 'updated_at' => now()]);
        });
        PasswordResetRequest::forgetPendingCount();

        Audit::record('account.password_reset', 'admin', $target->admin_id, $target->username, [], array_merge($meta, [
            'via'                => $how,
            'set_by'             => $by->username,
            'target_role'        => $target->role,
            'password_generated' => $generated,
        ]));

        return ['password' => $temporary, 'generated' => $generated];
    }
}