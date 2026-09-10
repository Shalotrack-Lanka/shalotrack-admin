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

    public function edit($id)
    {
        $dealer = Dealer::findOrFail($id);
        return view('admin.dealer.edit', compact('dealer'));
    }

    /**
     * Update an existing dealer and FORCE change password across all tables
     */
    public function update(Request $request, $id)
    {
        $dealer = Dealer::findOrFail($id);

        $validated = $request->validate([
            'full_name'        => 'required|string|max:255',
            'contact_email'    => 'required|email|max:255|unique:dealers,contact_email,' . $dealer->id,
            'dealer_status'    => 'nullable|string',
            'region'           => 'nullable|string',
            'country'          => 'nullable|string|max:100',
            'pin_code'         => 'nullable|string|max:50',
            'address'          => 'nullable|string|max:500',
            'network'          => 'nullable|string|max:100',
            
            // Password change fields
            'password'         => 'nullable|string|min:8|confirmed', 
        ]);

        // 1. Update basic info
        $dealer->full_name     = $validated['full_name'];
        $dealer->contact_email = $validated['contact_email'];
        if (isset($validated['dealer_status'])) $dealer->dealer_status = $validated['dealer_status'];
        if (isset($validated['region']))        $dealer->region        = $validated['region'];
        if (isset($validated['country']))       $dealer->country       = $validated['country'];
        if (isset($validated['pin_code']))      $dealer->pin_code      = $validated['pin_code'];
        if (isset($validated['address']))       $dealer->address       = $validated['address'];
        if (isset($validated['network']))       $dealer->network       = $validated['network'];

        $passwordChanged = false;

        // 2. FORCE Password Update across ALL tables if a new password is typed
        if ($request->filled('password')) {
            // Hash the plain-text password typed by the admin
            $hashedPassword = Hash::make($request->password);
            
            // Update Dealers Table
            $dealer->password = $hashedPassword;

            // DIRECT UPDATE query for Users Table (crucial for Login)
            if (class_exists(\App\Models\User::class)) {
                \App\Models\User::where('dealer_id', $dealer->id)
                    ->orWhere('email', $dealer->contact_email)
                    ->update(['password' => $hashedPassword]);
            }

            // DIRECT UPDATE query for Admins Table (if applicable)
            if (class_exists(\App\Models\Admin::class)) {
                \App\Models\Admin::where('dealer_id', $dealer->id)
                    ->orWhere('email', $dealer->contact_email)
                    ->update(['password' => $hashedPassword]);
            }
            
            $passwordChanged = true;
        }

        $dealer->save();

        $message = $passwordChanged 
            ? 'Dealer profile and password updated successfully!' 
            : 'Dealer profile updated successfully!';

        return redirect()->back()->with('success', $message);
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