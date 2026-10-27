<?php
// app/Http/Controllers/Admin/Dealer/DealerManagementController.php

namespace App\Http\Controllers\Admin\Dealer;

use App\Services\Audit;
use App\Models\DealerCustomerAd;
use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Admin;
use App\Services\CustomerLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

        // Distributors a new dealer can be attached to, and the parent shown under each dealer in the list.
        $distributorOptions = Dealer::where('dealer_status', 'distributor')->where('status', 'active')->orderBy('full_name')->get(['id', 'full_name']);
        $distributorNames   = Dealer::where('dealer_status', 'distributor')->pluck('full_name', 'id');

        return view('admin.dealer.dealer_management', compact('dealers', 'archivedDealers', 'distributorOptions', 'distributorNames'));
    }

    /**
     * Store a newly created dealer in storage and link user/admin profile.
     */
    public function store(Request $request)
    {
        // The page has one form for distributors and one for dealers. The distributor form cannot choose a
        // type (it is forced here), and the dealer form can never create a distributor, whatever is posted.
        $kind = $request->input('form_kind');
        abort_unless(in_array($kind, [null, 'distributor', 'dealer'], true), 422);
        if ($kind === 'distributor') {
            $request->merge(['dealer_status' => 'distributor', 'distributor_id' => null]);
        }
        // The dealer form has no type choice either: everyone it creates is a plain "Authorized Dealer"
        // (the type can still be changed later on the dealer's profile page).
        if ($kind === 'dealer') {
            $request->merge(['dealer_status' => 'Authorized Dealer']);
        }
        $allowedStatuses = self::VALID_DEALER_STATUSES;

        $validated = $request->validate([
            'full_name'        => 'required|string|max:255',
            'address'          => 'nullable|string|max:500',
            'qualification'    => 'nullable|string|max:255',
            'dealer_status'    => ['required', 'string', Rule::in($allowedStatuses)],
            // The distributor this dealer reports to: a real, active distributor, or nobody (direct).
            'distributor_id'   => ['nullable', 'integer', Rule::exists('dealers', 'id')->where('dealer_status', 'distributor')->where('status', 'active')],
            'region'           => ['required', 'string', 'max:100'],
            'country'          => 'nullable|string|max:100',
            'pin_code'         => 'nullable|string|max:50',
            'contact_email'    => 'required|email|max:255|unique:dealers,contact_email',
            'network'          => 'nullable|string|max:100',
            'password'         => ['nullable', 'string', 'max:' . \App\Support\PasswordPolicy::MAX_LENGTH, \App\Support\PasswordPolicy::rule()],
        ]);

        $dealerFormPassword = $validated['password'] ?? null;

        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $validated['status']     = 'active';
        $validated['created_by'] = auth()->user()->name ?? 'System';

        $generatedUsername = null;
        $generatedPassword = null;
        $createdDealerId = null;

        // A distributor signs in to its own portal (role DISTRIBUTOR); every other type is a dealer login.
        $loginRole = $validated['dealer_status'] === 'distributor' ? 'DISTRIBUTOR' : 'DEALER';

        DB::transaction(function () use ($validated, $dealerFormPassword, $loginRole, &$generatedUsername, &$generatedPassword, &$createdDealerId) {
            $dealer = Dealer::create($validated);
            $dealer->refresh();
            $createdDealerId = $dealer->id;

            $generatedUsername = $this->generateUniqueUsername($dealer->full_name);
            $generatedPassword = $dealerFormPassword ?: Str::password(10, symbols: false);

            if (class_exists(\App\Models\Admin::class)) {
                Admin::create([
                    'username'  => $generatedUsername,
                    'password'  => Hash::make($generatedPassword),
                    'full_name' => $dealer->full_name,
                    'email'     => $dealer->contact_email,
                    'role'      => $loginRole,
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

        Audit::record('dealer.created', 'dealer', $createdDealerId, $validated['full_name'], [], [
            'dealer_status'         => $validated['dealer_status'],
            'distributor_id'        => $validated['distributor_id'] ?? null,
            'region'                => $validated['region'],
            'login_username'        => $generatedUsername,
            'login_role'            => $loginRole,
            'password_set_by_admin' => $dealerFormPassword !== null,
        ]);

        $label = $validated['dealer_status'] === 'distributor' ? 'Distributor' : 'Dealer';

        return redirect()->back()
            ->with('success', "{$label} '{$validated['full_name']}' saved successfully.")
            ->with('saved_kind', $validated['dealer_status'] === 'distributor' ? 'distributor' : 'dealer');
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