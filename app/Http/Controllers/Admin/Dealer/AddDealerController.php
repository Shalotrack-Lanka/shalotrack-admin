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
            'dealer_status'   => 'required|string',
            'upper_channel'   => 'nullable|string',
            'company_name'    => 'required|string|max:255',
            'contact_person'  => 'required|string|max:255',
            'mobile_no'       => 'required|string|max:20',
            'address'         => 'nullable|string',
            'district'        => 'nullable|string',
            'country'         => 'nullable|string',
            'state'           => 'nullable|string',
            'pin_code'        => 'nullable|string|max:20',

            'commencement_date' => 'nullable|date',
            'email'           => 'nullable|email|unique:dealers,email',
            'tax_pan'         => 'nullable|string',
            'cst_no'          => 'nullable|string',
            'vat_tin'         => 'nullable|string',
            'gst_pan'         => 'nullable|string',
            'region'          => 'required|string',
            'area'            => 'nullable|string',
            'sales_person'    => 'nullable|string',
            'price_group'     => 'nullable|string',
            'commission_type' => 'nullable|string',
            'commission_group'=> 'nullable|string',

            'credit_amount'   => 'nullable|numeric|min:0',
            'credit_days'     => 'nullable|integer|min:0',
            'security_deposit'=> 'nullable|numeric|min:0',
            'deposit_date'    => 'nullable|date',
            'network'         => 'nullable|string',
            'login_id'        => 'nullable|string',
            'password'        => 'nullable|string|min:6',

            'business_entity' => 'nullable|string',
            'full_details_of' => 'nullable|string',
            'owner_name'      => 'nullable|string',
            'home_address'    => 'nullable|string',
            'qualification'   => 'nullable|string',
            'ownership'       => 'nullable|string',
            'involvement'     => 'nullable|string',

            'payment_modes'   => 'nullable|array',

            'profile_photo'   => 'nullable|image|max:2048',
            'copy_of_ma'      => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
            'passport_front'  => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
            'passport_last'   => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
        ]);

        foreach (['profile_photo', 'copy_of_ma', 'passport_front', 'passport_last'] as $field) {
            if ($request->hasFile($field)) {
                $validated[$field] = $request->file($field)->store('dealers', 'public');
            }
        }

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $validated['deliver_to_customer'] = $request->boolean('deliver_to_customer');
        $validated['status'] = 'active';
        $validated['created_by'] = auth()->user()->name ?? 'System';

        Dealer::create($validated);

        return redirect()->route('admin.add-dealer')->with('success', 'Dealer saved successfully.');
    }
}