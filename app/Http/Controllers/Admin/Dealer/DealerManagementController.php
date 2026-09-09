<?php
// app/Http/Controllers/Admin/Dealer/DealerManagementController.php

namespace App\Http\Controllers\Admin\Dealer;

use App\Models\DealerCustomerAd;
use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DealerManagementController extends Controller
{
    // -------------------------------------------------------------------------
    // Valid values that the form select elements can submit.
    // Keeping these as constants on the controller means the blade, the
    // validation, and any future edit form all share one authoritative list —
    // no drift between what the form shows and what the backend accepts.
    // -------------------------------------------------------------------------

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
        $dealers = Dealer::where('status', 'active')->latest()->get();
        $archivedDealers = Dealer::where('status', 'archived')->latest()->get();

        return view('admin.dealer.dealer_management', compact('dealers', 'archivedDealers'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            // ── Step 1: Basic info ────────────────────────────────────────────
            'full_name'     => 'required|string|max:255',
            'address'       => 'nullable|string|max:500',
            'qualification' => 'nullable|string|max:255',

            // dealer_status and region are selects — lock them to exactly
            // what the form offers so a crafted POST can't inject garbage.
            'dealer_status' => ['required', 'string', 'in:' . implode(',', self::VALID_DEALER_STATUSES)],
            'region'        => ['required', 'string', 'in:' . implode(',', self::VALID_REGIONS)],

            // Only Sri Lanka currently, but kept nullable for future expansion.
            'country'       => 'nullable|string|max:100',
            'pin_code'      => 'nullable|digits_between:4,10',

            // ── Step 2: Business / financial ─────────────────────────────────
            'contact_email' => 'nullable|email|max:255|unique:dealers,contact_email',
            'tax_pan'       => 'nullable|string|max:50',
            'cst_no'        => 'nullable|string|max:50',
            'vat_tin'       => 'nullable|string|max:50',
            'gst_pan'       => 'nullable|string|max:50',

            'security_deposit' => 'nullable|numeric|min:0|max:99999999.99',
            'deposit_date'     => 'nullable|date|before_or_equal:today',
            'network'          => 'nullable|string|max:100',

            // login_id is a human-readable reference field stored on the
            // dealers row itself — it is NOT the system-login username
            // (that gets auto-generated in Admin::create below).
            'login_id'     => 'nullable|string|max:100',

            // min:8 for any password we create or store — min:6 was too weak
            // for a portal login. This field is optional; if omitted, a
            // random 10-char password is generated automatically.
            'password'     => 'nullable|string|min:8|max:72',

            // ── Step 3: Payments & documents ─────────────────────────────────
            'payment_modes' => 'nullable|array',

            // profile_photo: images only, max 2 MB
            'profile_photo'  => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            // Document uploads: images or PDF, max 4 MB each
            'copy_of_ma'     => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
            'passport_front' => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
            'passport_last'  => 'nullable|mimes:jpg,jpeg,png,pdf|max:4096',
        ], [
            'dealer_status.in' => 'Please select a valid dealer type.',
            'region.in'        => 'Please select a valid region.',
            'pin_code.digits_between' => 'Pin code must be 4–10 digits.',
            'password.min'     => 'Password must be at least 8 characters.',
            'deposit_date.before_or_equal' => 'Deposit date cannot be in the future.',
            'security_deposit.max' => 'Security deposit value seems unrealistically large — please check.',
        ]);

        // Store file uploads; overwrite the field value with its storage path.
        foreach (['profile_photo', 'copy_of_ma', 'passport_front', 'passport_last'] as $field) {
            if ($request->hasFile($field)) {
                $validated[$field] = $request->file($field)->store('dealers', 'public');
            }
        }

        // Capture the plain-text password BEFORE hashing — it is needed
        // later for the system Admin account and for the one-time display
        // in the success flash. Once hashed, the plain text is gone.
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

            // Auto-generate the system portal login for this dealer.
            // If the admin supplied a password on the form, reuse it;
            // otherwise generate a secure random one.
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

    /**
     * Turns "Ranil Kumara" into "ranil_kumara". If that username already
     * exists, appends an incrementing suffix: "ranil_kumara2", "ranil_kumara3".
     */
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

        return view('admin.dealer.dealer_customers', compact('customerAds'));
    }
}