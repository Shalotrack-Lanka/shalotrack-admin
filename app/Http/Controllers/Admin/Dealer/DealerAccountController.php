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

        $request->validate([
            'full_name'     => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'phone_number'  => 'nullable|string|max:20',
            'address'       => 'nullable|string|max:500',
            'region'        => 'nullable|string|max:255',
            'country'       => 'nullable|string|max:255',
            'qualification' => 'nullable|string|max:255',
            'new_password'  => 'nullable|string|min:8|confirmed',
        ]);

        $dealer = Dealer::findOrFail($admin->dealer_id);
        $dealer->full_name     = $request->full_name;
        $dealer->contact_email = $request->contact_email;
        $dealer->address       = $request->address;
        $dealer->region        = $request->region;
        $dealer->country       = $request->country;
        $dealer->qualification = $request->qualification;
        $dealer->save();

        $admin->full_name    = $request->full_name;
        $admin->email        = $request->contact_email;
        $admin->phone_number = $request->phone_number;

        if ($request->filled('new_password')) {
            $admin->password = Hash::make($request->new_password);
        }

        $admin->save();

        return redirect()->back()->with('success', 'Your profile has been updated successfully.');
    }
}