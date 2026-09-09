<?php
// app/Http/Controllers/Admin/Dealer/DealerManagementController.php

namespace App\Http\Controllers\Admin\Dealer;

use App\Models\DealerCustomerAd;
use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Admin;
use App\Services\CustomerLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DealerManagementController extends Controller
{
    private const VALID_DEALER_STATUSES = [
        'lbc', 'distributor', 'retailer', 'dsa', 'ba', 'csa', 'lt_point',
    ];

    private const VALID_REGIONS = [
        'central', 'colombo', 'eastern', 'gampaha',
        'north_central', 'north_western', 'northern',
        'sabaragamuwa', 'southern', 'uva', 'western',
    ];

    public function index()
    {
        $dealers         = Dealer::where('status', 'active')->latest()->get();
        $archivedDealers = Dealer::where('status', 'archived')->latest()->get();

        return view('admin.dealer.dealer_management', compact('dealers', 'archivedDealers'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name'      => 'required|string|max:255',
            'address'        => 'nullable|string|max:500',
            'qualification'  => 'nullable|string|max:255',
            'dealer_status'  => ['required', 'string', 'in:' . implode(',', self::VALID_DEALER_STATUSES)],
            'region'         => ['required', 'string', 'in:' . implode(',', self::VALID_REGIONS)],
            'country'        => 'nullable|string|max:100',
            'pin_code'       => 'nullable|digits_between:4,10',
            'contact_email'  => 'nullable|email|max:255|unique:dealers,contact_email',
            'tax_pan'        => 'nullable|string|max:50',
            'cst_no'         => 'nullable|string|max:50',
            'vat_tin'        => 'nullable|string|max:50',
            'gst_pan'        => 'nullable|string|max:50',
            'security_deposit' => 'nullable|numeric|min:0|max:99999999.99',
            'deposit_date'   => 'nullable|date|before_or_equal:today',
            'network'        => 'nullable|string|max:100',
            'login_id'       => 'nullable|string|max:100',
            'password'       => 'nullable|string|min:8|max:72',
            'payment_modes'  => 'nullable|array',
            'profile_photo'  => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'copy_of_ma'     => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
            'passport_front' => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
            'passport_last'  => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
        ], [
            'dealer_status.in'             => 'Please select a valid dealer type.',
            'region.in'                    => 'Please select a valid region.',
            'pin_code.digits_between'      => 'Pin code must be 4–10 digits.',
            'password.min'                 => 'Password must be at least 8 characters.',
            'deposit_date.before_or_equal' => 'Deposit date cannot be in the future.',
        ]);

        foreach (['profile_photo', 'copy_of_ma', 'passport_front', 'passport_last'] as $field) {
            if ($request->hasFile($field)) {
                $validated[$field] = $request->file($field)->store('dealers', 'public');
            }
        }

        $dealerFormPassword = $validated['password'] ?? null;

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $validated['status']     = 'active';
        $validated['created_by'] = auth()->user()->name ?? 'System';

        $generatedUsername = null;
        $generatedPassword = null;

        DB::transaction(function () use ($validated, $dealerFormPassword, &$generatedUsername, &$generatedPassword) {
            $dealer = Dealer::create($validated);
            $dealer->refresh();

            $generatedUsername = $this->generateUniqueUsername($dealer->full_name);
            $generatedPassword = $dealerFormPassword ?: Str::password(10, symbols: false);

            Admin::create([
                'username'  => $generatedUsername,
                'password'  => Hash::make($generatedPassword),
                'full_name' => $dealer->full_name,
                'email'     => $dealer->contact_email,
                'role'      => 'DEALER',
                'status'    => 'ACTIVE',
                'dealer_id' => $dealer->id,
            ]);
        });

        return redirect()->route('admin.dealer-management')->with('success', [
            'message'  => "Dealer '{$validated['full_name']}' saved successfully.",
            'username' => $generatedUsername,
            'password' => $generatedPassword,
        ]);
    }

    private function generateUniqueUsername(string $fullName): string
    {
        $base     = Str::of($fullName)->lower()->trim()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
        $username = (string) $base;
        $suffix   = 1;

        while (Admin::where('username', $username)->exists()) {
            $suffix++;
            $username = "{$base}{$suffix}";
        }

        return $username;
    }

    /**
     * Admin view: all customers added by any dealer, enriched with their
     * real app accounts and vehicles so admin can see who is actually live
     * on the platform versus who is just a recorded lead.
     */
    public function dealerCustomers()
    {
        $customerAds = DealerCustomerAd::with('dealer')->latest()->get();

        // Enrich with matched app accounts and vehicles — 2 queries, never N+1
        $customerAds = CustomerLinkService::enrichLeads($customerAds);

        return view('admin.dealer.dealer_customers', compact('customerAds'));
    }
}