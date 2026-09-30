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
        'lbc', 'distributor', 'retailer', 'dsa', 'ba', 'csa', 'lt_point', 'Authorized Dealer'
    ];

    private const VALID_REGIONS = [
        'central', 'colombo', 'eastern', 'gampaha',
        'north_central', 'north_western', 'northern',
        'sabaragamuwa', 'southern', 'uva', 'western', 'Western', 'Northern', 'Eastern'
    ];

    public function index()
    {
        $dealers         = Dealer::where('status', 'active')->latest()->get();
        $archivedDealers = Dealer::where('status', 'archived')->latest()->get();

        return view('admin.dealer.dealer_management', compact('dealers', 'archivedDealers'));
    }

    /**
     * Store a newly created dealer in storage and link user/admin profile.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name'        => 'required|string|max:255',
            'address'          => 'nullable|string|max:500',
            'qualification'    => 'nullable|string|max:255',
            'dealer_status'    => ['required', 'string'],
            'region'           => ['required', 'string'],
            'country'          => 'nullable|string|max:100',
            'pin_code'         => 'nullable|string|max:50',
            'contact_email'    => 'required|email|max:255|unique:dealers,contact_email',
            'network'          => 'nullable|string|max:100',
            'password'         => 'nullable|string|min:8|max:72',
        ]);

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

            if (class_exists(\App\Models\Admin::class)) {
                Admin::create([
                    'username'  => $generatedUsername,
                    'password'  => Hash::make($generatedPassword),
                    'full_name' => $dealer->full_name,
                    'email'     => $dealer->contact_email,
                    'role'      => 'DEALER',
                    'status'    => 'ACTIVE',
                    'dealer_id' => $dealer->id,
                ]);
            }

            if (class_exists(\App\Models\User::class)) {
                $user = \App\Models\User::where('email', $dealer->contact_email)->first();

                if (!$user) {
                    $user = \App\Models\User::create([
                        'name'      => $dealer->full_name,
                        'email'     => $dealer->contact_email ?? ($generatedUsername . '@shalotrack.com'),
                        'password'  => Hash::make($generatedPassword),
                        'dealer_id' => $dealer->id,
                    ]);
                } else {
                    $user->dealer_id = $dealer->id;
                    $user->save();
                }
            }
        });

        return redirect()->back()->with('success', "Dealer '{$validated['full_name']}' saved successfully.");
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

    public function dealerCustomers()
    {
        $customerAds = DealerCustomerAd::with('dealer')->latest()->get();
        $customerAds = CustomerLinkService::enrichLeads($customerAds);

        return view('admin.dealer.dealer_customers', compact('customerAds'));
    }
}