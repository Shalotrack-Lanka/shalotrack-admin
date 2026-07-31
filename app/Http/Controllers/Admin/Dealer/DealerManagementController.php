<?php
// app/Http/Controllers/Admin/Dealer/DealerManagementController.php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DealerManagementController extends Controller
{
    public function index()
    {
        $dealers = Dealer::where('status', 'active')->latest()->get();
        $archivedDealers = Dealer::where('status', 'archived')->latest()->get();

        return view('admin.dealer.dealer_management', compact('dealers', 'archivedDealers'));
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

        // Capture the plain password BEFORE it gets hashed below — needed
        // again further down to create the system login with this same
        // password, not a separately generated random one.
        $dealerFormPassword = $validated['password'] ?? null;

        // This is the dealer's OWN login_id/password field on the Dealer form
        // itself (Step 2) — separate DB column, hashed here only if filled in.
        if (!empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $validated['status'] = 'active';
        $validated['created_by'] = auth()->user()->name ?? 'System';

        $generatedUsername = null;
        $generatedPassword = null;

        DB::transaction(function () use ($validated, $dealerFormPassword, &$generatedUsername, &$generatedPassword) {
            $dealer = Dealer::create($validated);
            $dealer->refresh(); // re-fetch from DB — needed if the dealer's
            // primary key is a DB-generated UUID (like Admins.admin_id is),
            // since Eloquent's normal lastInsertId() trick only works for
            // simple auto-incrementing integer keys, not UUID defaults.

            // --- Auto-create the real system login (Admins table) ---
            $generatedUsername = $this->generateUniqueUsername($dealer->full_name);
            // Use the exact password the admin typed on the form, if they
            // typed one — only generate a random one if that field was
            // left blank, so there's still always a working login either way.
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

        return redirect()->route('admin.dealer_management')->with('success', [
            'message'  => "Dealer '{$validated['full_name']}' saved successfully.",
            'username' => $generatedUsername,
            'password' => $generatedPassword,
        ]);
    }

    /**
     * Turns "Ranil Kumara" into "ranil_kumara", and if that's already
     * taken, "ranil_kumara2", "ranil_kumara3", etc.
     */
    private function generateUniqueUsername(string $fullName): string
    {
        $base = Str::of($fullName)->lower()->trim()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_');
        $username = (string) $base;
        $suffix = 1;

        while (Admin::where('username', $username)->exists()) {
            $suffix++;
            $username = "{$base}{$suffix}";
        }

        return $username;
    }
}