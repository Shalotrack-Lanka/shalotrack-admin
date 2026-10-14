<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\PasswordResetRequest;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AdminProfileController extends Controller
{
    public function show(Request $request): View
    {
        $resetRequests = collect();

        // The administration section (add admin, password reset requests) exists for ADMIN only.
        // Wrapped so the page still opens if the new table has not been migrated yet.
        if ($request->user()->role === 'ADMIN') {
            try {
                $resetRequests = PasswordResetRequest::with('admin:admin_id,full_name,role')
                    ->where('status', PasswordResetRequest::PENDING)
                    ->orderBy('requested_at')
                    ->get();
            } catch (Throwable) {
                $resetRequests = collect();
            }
        }

        return view('admin.profile', ['resetRequests' => $resetRequests]);
    }

    /** The signed-in person edits their own name, email and phone. Username, role and status stay locked. */
    public function update(Request $request): RedirectResponse
    {
        /** @var Admin $me */
        $me = $request->user();

        $data = $request->validateWithBag('profile', [
            'full_name'    => ['required', 'string', 'max:150'],
            'email'        => ['required', 'email:rfc', 'max:190'],
            'phone_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9+()\s-]{7,20}$/'],
        ], [
            'phone_number.regex' => 'Enter a phone number using digits, spaces, + ( ) and - only.',
        ]);

        $data['full_name'] = trim($data['full_name']);
        $data['email'] = trim($data['email']);
        $data['phone_number'] = isset($data['phone_number']) && trim($data['phone_number']) !== '' ? trim($data['phone_number']) : null;

        // Only an email that is being CHANGED must be free (an older duplicate must not block saving the rest).
        if (mb_strtolower($data['email']) !== mb_strtolower((string) $me->email)
            && Admin::whereRaw('lower(email) = ?', [mb_strtolower($data['email'])])->where('admin_id', '!=', $me->admin_id)->exists()) {
            return back()->withInput()->withErrors(['email' => 'Another account already uses this email.'], 'profile');
        }

        $before = $me->only(['full_name', 'email', 'phone_number']);
        $me->fill($data)->save();

        $changes = Audit::diff($before, $data, ['full_name', 'email', 'phone_number']);
        if ($changes !== []) {
            Audit::record('account.profile_updated', 'admin', $me->admin_id, $me->username, $changes);
        }

        return back()->with('success', $changes === [] ? 'Nothing was changed.' : 'Your details were saved.');
    }
}