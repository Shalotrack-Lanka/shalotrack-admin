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
        $admin = auth()->user();
        $supplier = $admin->supplier; // null if this account was never linked

        return view('admin.supplier.profile', compact('admin', 'supplier'));
    }

    public function update(Request $request)
    {
        $admin = auth()->user();
        $supplier = $admin->supplier;

        if (!$supplier) {
            return back()->withErrors(['supplier' => 'Your account isn\'t linked to a supplier record — contact an administrator.']);
        }

        // --- Company details (real Supplier fields only) ---
        $validated = $request->validate([
            'full_name'    => 'required|string|max:255', // the login's display name (Admins.full_name)
            'name'         => 'required|string|max:255', // company name (Suppliers.name)
            'email'        => 'nullable|email|max:255',
            'phone_number' => 'nullable|string|max:20',
            'website'      => 'nullable|string|max:255',
            'address'      => 'nullable|string',
            'country'      => 'nullable|string|max:100',
            'state'        => 'nullable|string|max:100',
            'gstin_number' => 'nullable|string|max:50',
        ]);

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

        $admin->update([
            'full_name' => $validated['full_name'],
        ]);

        // --- Password change (optional — only if they filled it in) ---
        if ($request->filled('new_password')) {
            $request->validate([
                'current_password' => 'required|string',
                'new_password'     => 'required|string|min:6|confirmed',
            ]);

            if (!Hash::check($request->current_password, $admin->password)) {
                return back()->withErrors(['current_password' => 'Your current password is incorrect.'])->withInput();
            }

            $admin->update([
                'password' => Hash::make($request->new_password),
            ]);
        }

        return back()->with('success', 'Profile updated successfully.');
    }
}