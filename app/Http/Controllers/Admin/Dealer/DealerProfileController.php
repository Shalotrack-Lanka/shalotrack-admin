<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\Dealer;
use App\Models\Admin; 

class DealerProfileController extends Controller
{
    /**
     * Display the dealer's profile form.
     */
    public function edit()
    {
        // දැනට ලොගින් වී සිටින admin/dealer ගේ විස්තර ලබා ගැනීම
        $admin = Auth::user(); // හෝ Auth::guard('admin')->user() ඔබගේ guard එක අනුව

        // Role එක DEALER ද සහ dealer_id එකක් තිබේදැයි පරීක්ෂා කිරීම
        if ($admin->role !== 'DEALER' || empty($admin->dealer_id)) {
            abort(403, 'Unauthorized action. Dealer account not found.');
        }

        // Dealers table එකෙන් අදාළ dealer ගේ විස්තර ලබා ගැනීම
        $dealer = Dealer::findOrFail($admin->dealer_id);

        return view('admin.dealer.profile', compact('admin', 'dealer'));
    }

    /**
     * Update the dealer's profile information.
     */
    public function update(Request $request)
    {
        $admin = Auth::user();

        if ($admin->role !== 'DEALER' || empty($admin->dealer_id)) {
            abort(403, 'Unauthorized action.');
        }

        // Data Validation (අවශ්‍ය දත්ත නිවැරදිව ලැබී ඇත්දැයි පරීක්ෂා කිරීම)
        $request->validate([
            'full_name'     => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'phone_number'  => 'nullable|string|max:20',
            'address'       => 'nullable|string|max:500',
            'region'        => 'nullable|string|max:255',
            'country'       => 'nullable|string|max:255',
            'qualification' => 'nullable|string|max:255',
            'new_password'  => 'nullable|string|min:8|confirmed', // 'new_password_confirmation' field එකත් form එකේ තිබිය යුතුයි
        ]);

        // 1. Dealers Table එක Update කිරීම
        $dealer = Dealer::findOrFail($admin->dealer_id);
        $dealer->full_name     = $request->full_name;
        $dealer->contact_email = $request->contact_email;
        $dealer->address       = $request->address;
        $dealer->region        = $request->region;
        $dealer->country       = $request->country;
        $dealer->qualification = $request->qualification;
        $dealer->save();

        // 2. Admins Table එක Update කිරීම (සහ Password Hashing)
        /* Admin table එකෙත් full_name, email තියෙන නිසා ඒවා sync කර තබා ගැනීම වඩාත් සුදුසුයි */
        $admin->full_name    = $request->full_name;
        $admin->email        = $request->contact_email;
        $admin->phone_number = $request->phone_number;

        // අලුත් Password එකක් දීලා තියෙනවා නම් විතරක් එය Hash කර Admins table එකට save කිරීම
        if ($request->filled('new_password')) {
            $admin->password = Hash::make($request->new_password);
        }
        
        $admin->save();

        return redirect()->back()->with('success', 'Your profile has been updated successfully.');
    }
}