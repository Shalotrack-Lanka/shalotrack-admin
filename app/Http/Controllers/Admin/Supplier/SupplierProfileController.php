<?php

namespace App\Http\Controllers\Admin\Supplier;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SupplierProfileController extends Controller
{
    public function edit()
    {
        $admin    = auth()->user();
        $supplier = $admin->supplier; // null if account was never linked to a supplier record

        return view('admin.supplier.profile', compact('admin', 'supplier'));
    }

    public function update(Request $request)
    {
        $admin    = auth()->user();
        $supplier = $admin->supplier;

        if (!$supplier) {
            return back()->withErrors([
                'supplier' => "Your account isn't linked to a supplier record — contact an administrator.",
            ]);
        }

        // ── Company & personal details ────────────────────────────────────────
        //
        // SECURITY: 'status' is deliberately NOT in these rules.
        // A supplier must not be able to deactivate or reactivate their own
        // account — that action belongs exclusively to an admin via
        // SupplierManagementController::toggleStatus(). Including 'status'
        // here was a privilege-escalation vulnerability: a supplier could POST
        // status=Inactive to lock themselves out, or status=Active to bypass
        // an admin-imposed deactivation.
        $validated = $request->validate([
            'full_name' => 'required|string|max:255', // Admins.full_name — login display name
            'name'      => 'required|string|max:255', // Suppliers.name — company name

            // Phone: must be a real phone number format if provided.
            'phone_number' => [
                'nullable',
                'regex:/^[+]?[0-9\s\-\(\)]{7,20}$/',
            ],

            // Email uniqueness: allow the supplier to keep their existing
            // email (Rule::ignore their own suppliers row), but block
            // taking an email already used by a different supplier.
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('suppliers', 'email')->ignore($supplier->id),
            ],

            // Website must be a real URL if provided.
            'website' => 'nullable|url|max:255',

            'address'      => 'nullable|string|max:500',
            'country'      => 'nullable|string|max:100',
            'state'        => 'nullable|string|max:100',
            'gstin_number' => 'nullable|string|max:50',
        ], [
            'phone_number.regex' => 'Phone number must contain only digits, spaces, hyphens, parentheses, or a leading +.',
            'email.unique'       => 'This email address is already registered to another supplier.',
            'website.url'        => 'Website must be a valid URL (e.g. https://example.com).',
        ]);

        // Update the supplier record — status is intentionally excluded.
        $supplier->update([
            'name'         => $validated['name'],
            'email'        => $validated['email'] ?? null,
            'phone_number' => $validated['phone_number'] ?? null,
            'website'      => $validated['website'] ?? null,
            'address'      => $validated['address'] ?? null,
            'country'      => $validated['country'] ?? null,
            'state'        => $validated['state'] ?? null,
            'gstin_number' => $validated['gstin_number'] ?? null,
        ]);

        // Update the admin login's display name.
        $admin->update([
            'full_name' => $validated['full_name'],
        ]);

        // ── Password change (optional) ────────────────────────────────────────
        // Only triggered if the supplier actually typed in the password fields.
        // Validated in a second pass so the error messages are scoped to those
        // fields and don't interfere with the company-details section above.
        if ($request->filled('new_password')) {
            $request->validate([
                'current_password' => 'required|string',
                'new_password'     => 'required|string|min:8|confirmed',
            ], [
                'new_password.min' => 'New password must be at least 8 characters.',
            ]);

            if (!Hash::check($request->current_password, $admin->password)) {
                return back()
                    ->withErrors(['current_password' => 'Your current password is incorrect.'])
                    ->withInput();
            }

            $admin->update([
                'password' => Hash::make($request->new_password),
            ]);
        }

        return back()->with('success', 'Profile updated successfully.');
    }
}