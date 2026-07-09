<?php
// app/Http/Controllers/Admin/Dealer/AddDealerController.php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AddDealerController extends Controller
{
    public function index()
    {
        $dealers = Dealer::where('status', 'active')->latest()->get();
        $archivedDealers = Dealer::where('status', 'archived')->latest()->get();

        return view('admin.dealer.add_dealer', compact('dealers', 'archivedDealers'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name'        => 'required|string|max:255',
            'address'          => 'nullable|string',
            'qualification'    => 'nullable|string|max:255',

            'dealer_status'    => 'required|string',
            'region'           => 'required|string',
            'country'          => 'nullable|string',
            'pin_code'         => 'nullable|string|max:20',

            'contact_email'    => 'nullable|email|unique:dealers,contact_email',
            'tax_pan'          => 'nullable|string',
            'cst_no'           => 'nullable|string',
            'vat_tin'          => 'nullable|string',
            'gst_pan'          => 'nullable|string',

            'security_deposit' => 'nullable|numeric|min:0',
            'deposit_date'     => 'nullable|date',
            'network'          => 'nullable|string',
            'login_id'         => 'nullable|string',
            'password'         => 'nullable|string|min:6',

            'payment_modes'    => 'nullable|array',

            'profile_photo'    => 'nullable|image|max:2048',
            'copy_of_ma'       => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
            'passport_front'   => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
            'passport_last'    => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
        ]);

        foreach (['profile_photo', 'copy_of_ma', 'passport_front', 'passport_last'] as $field) {
            if ($request->hasFile($field)) {
                $validated[$field] = $request->file($field)->store('dealers', 'public');
            }
        }

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $validated['status'] = 'active';
        $validated['created_by'] = auth()->user()->name ?? 'System';

        Dealer::create($validated);

        return redirect()->route('admin.add-dealer')->with('success', 'Dealer saved successfully.');
    }
}