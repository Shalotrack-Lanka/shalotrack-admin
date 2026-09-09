<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\Dealer;
use App\Models\Admin;

class DealerAccountController extends Controller
{
    /**
     * Display the logged-in dealer's OWN profile form (self-service).
     * Not to be confused with DealerProfileController, which is the
     * Admin-facing page for viewing/managing a SPECIFIC dealer by ID.
     */
    public function edit()
    {
        $admin = Auth::user();

        if ($admin->role !== 'DEALER' || empty($admin->dealer_id)) {
            abort(403, 'Unauthorized action. Dealer account not found.');
        }

        $dealer = Dealer::findOrFail($admin->dealer_id);

        return view('admin.dealer.profile', compact('admin', 'dealer'));
    }

    public function update(Request $request)
    {
        $admin = Auth::user();

        if ($admin->role !== 'DEALER' || empty($admin->dealer_id)) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'full_name'     => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',

            // Phone: optional, but must be a real phone number format if given.
            // Accepts +94 77 123 4567, 077-123-4567, (077) 1234567, etc.
            'phone_number' => [
                'nullable',
                'regex:/^[+]?[0-9\s\-\(\)]{7,20}$/',
            ],

            'address'       => 'nullable|string|max:500',
            'region'        => 'nullable|string|max:255',
            'country'       => 'nullable|string|max:255',
            'qualification' => 'nullable|string|max:255',

            // Password change is optional. If submitted it must be at least
            // 8 characters and confirmed via the confirmation field.
            'new_password'  => 'nullable|string|min:8|confirmed',
        ], [
            'phone_number.regex' => 'Phone number must contain only digits, spaces, hyphens, parentheses, or a leading +.',
            'new_password.min'   => 'New password must be at least 8 characters.',
        ]);

        $dealer = Dealer::findOrFail($admin->dealer_id);
        $dealer->full_name     = $validated['full_name'];
        $dealer->contact_email = $validated['contact_email'];
        $dealer->address       = $validated['address'] ?? $dealer->address;
        $dealer->region        = $validated['region'] ?? $dealer->region;
        $dealer->country       = $validated['country'] ?? $dealer->country;
        $dealer->qualification = $validated['qualification'] ?? $dealer->qualification;
        $dealer->save();

        $admin->full_name    = $validated['full_name'];
        $admin->email        = $validated['contact_email'];
        $admin->phone_number = $validated['phone_number'] ?? null;

        if ($request->filled('new_password')) {
            $admin->password = Hash::make($validated['new_password']);
        }

        $admin->save();

        return redirect()->back()->with('success', 'Your profile has been updated successfully.');
    }
}