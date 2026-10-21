<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PasswordResetRequest;
use App\Services\TemporaryPassword;
use App\Support\PasswordPolicy;
use App\Support\ReauthGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Developer account only (route middleware: role:ADMIN + developer, see Admin::isDeveloper()).
 * A directory of every login in the ERP (all roles) with search, and a way to set a temporary password
 * on any of them without waiting for a forgot-password request. The person must then choose their own
 * password at the next sign-in. Every reset asks for the developer's own password and is audited
 * (the event, never the password).
 */
class DeveloperAccountController extends Controller
{
    private const PER_PAGE = 25;
    private const ROLES = ['ADMIN', 'DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER'];

    public function index(Request $request)
    {
        $q = $this->text($request->query('q'));
        $role = $request->query('role');
        $status = $request->query('status');

        $query = Admin::query()->orderBy('role')->orderBy('full_name')->orderBy('username');

        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($q)) . '%';
            $query->where(function ($w) use ($like) {
                foreach (['username', 'full_name', 'email', 'phone_number'] as $col) {
                    $w->orWhereRaw("LOWER(COALESCE({$col}, '')) LIKE ? ESCAPE '\\'", [$like]);
                }
            });
        }
        if (is_string($role) && in_array($role, self::ROLES, true)) {
            $query->where('role', $role);
        } else {
            $role = null;
        }
        if (is_string($status) && in_array($status, ['ACTIVE', 'INACTIVE'], true)) {
            $query->where('status', $status);
        } else {
            $status = null;
        }

        $rows = $query->paginate(self::PER_PAGE)->withQueryString();

        $pending = PasswordResetRequest::where('status', PasswordResetRequest::PENDING)
            ->pluck('admin_id')->flip()->all();

        return view('admin.accounts.index', [
            'rows'    => $rows,
            'q'       => $q,
            'role'    => $role,
            'status'  => $status,
            'roles'   => self::ROLES,
            'pending' => $pending,
        ]);
    }

    public function reset(Request $request, string $adminId): RedirectResponse
    {
        $request->validateWithBag('resetAccount', [
            'current_password'   => ['required', 'string'],
            'temporary_password' => ['nullable', 'string', PasswordPolicy::rule()],
        ]);

        ReauthGuard::confirm($request, 'resetAccount', 'set temporary password (account directory)');

        $me = $request->user();
        $target = Admin::find($adminId);

        if (! $target) {
            abort(404);
        }
        if ($target->admin_id === $me->admin_id) {
            return back()->withErrors(['account' => 'This is your own account. Use "Change password" on your profile instead.'], 'resetAccount');
        }
        if ($target->status !== 'ACTIVE') {
            return back()->withErrors(['account' => "{$target->username} is not active, so no password was set."], 'resetAccount');
        }

        $result = TemporaryPassword::set(
            $target,
            $me,
            (string) $request->input('temporary_password'),
            'temporary password set by the developer account (account directory)',
        );

        return back()->with('temp_password', [
            'username'  => $target->username,
            'name'      => $target->full_name,
            'password'  => $result['password'],
            'generated' => $result['generated'],
        ]);
    }

    private function text(mixed $v): string
    {
        return is_string($v) ? mb_substr(trim($v), 0, 100) : '';
    }
}