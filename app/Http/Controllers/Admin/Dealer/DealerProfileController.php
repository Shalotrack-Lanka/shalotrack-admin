<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Dealer;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;

class DealerProfileController extends Controller
{
    private const VALID_DEALER_STATUSES = [
        'lbc', 'distributor', 'retailer', 'dsa', 'ba', 'csa', 'lt_point', 'Authorized Dealer'
    ];

    private const VALID_REGIONS = [
        'central', 'colombo', 'eastern', 'gampaha',
        'north_central', 'north_western', 'northern',
        'sabaragamuwa', 'southern', 'uva', 'western',
        'Central', 'Colombo', 'Eastern', 'Gampaha',
        'North Central', 'North Western', 'Northern',
        'Sabaragamuwa', 'Southern', 'Uva', 'Western'
    ];

    public function show($id)
    {
        $dealer = Dealer::findOrFail($id);

        $assignedDevices = SetupShalotrackDevice::where('dealer_id', $id)
            ->latest('shdevice_id')
            ->get();

        $transfers = DealerTransferLedger::where('dealer_id', $id)
            ->latest()
            ->get();

        $totalDevicesTransferred = $transfers->sum('quantity');

        $recentActivity = $transfers->take(5)->map(function ($t) {
            return [
                'date' => $t->created_at,
                'text' => "Received {$t->quantity} × " . $t->device_category,
            ];
        })->values();

        return view('admin.dealer.profile_view', compact(
            'dealer', 'assignedDevices', 'transfers', 'totalDevicesTransferred', 'recentActivity'
        ));
    }

    public function update(Request $request, int $id)
    {
        $dealer = Dealer::findOrFail($id);

        $validated = $request->validate([
            'full_name'        => 'required|string|max:255',
            'address'          => 'nullable|string|max:500',
            'qualification'    => 'nullable|string|max:255',
            'dealer_status'    => ['required', 'string', 'in:' . implode(',', self::VALID_DEALER_STATUSES)],
            'region'           => ['required', 'string', 'in:' . implode(',', self::VALID_REGIONS)],
            'country'          => 'nullable|string|max:100',
            'pin_code'         => 'nullable|string|max:50',
            'contact_email'    => [
                'nullable', 'email', 'max:255',
                Rule::unique('dealers', 'contact_email')->ignore($dealer->id),
            ],
            'tax_pan'          => 'nullable|string|max:50',
            'cst_no'           => 'nullable|string|max:50',
            'vat_tin'          => 'nullable|string|max:50',
            'gst_pan'          => 'nullable|string|max:50',
            'security_deposit' => 'nullable|numeric|min:0|max:99999999.99',
            'deposit_date'     => 'nullable|date|before_or_equal:today',
            'network'          => 'nullable|string|max:100',
            'login_id'         => 'nullable|string|max:100',
            
            // Password fields
            'password'         => 'nullable|string|min:8|confirmed',
        ], [
            'dealer_status.in'             => 'Please select a valid dealer type.',
            'region.in'                    => 'Please select a valid region.',
            'contact_email.unique'         => 'This email is already registered to another dealer.',
            'deposit_date.before_or_equal' => 'Deposit date cannot be in the future.',
            'password.min'                 => 'Password must be at least 8 characters.',
            'password.confirmed'           => 'The password confirmation does not match.',
        ]);

        $passwordInput = $validated['password'] ?? null;
        unset($validated['password'], $validated['password_confirmation']);

        // 1. Update basic fields
        $dealer->update($validated);

        $passwordChanged = false;
        $hashedPassword = null;

        // 2. Handle Password Change (Fixed database column error)
        if ($request->filled('password')) {
            $hashedPassword = Hash::make($request->password);
            
            // Update Dealers Table
            $dealer->password = $hashedPassword;
            $dealer->save();

            // Update Users Table ONLY by email (Removed dealer_id check)
            if (class_exists(\App\Models\User::class)) {
                \App\Models\User::where('email', $dealer->contact_email)
                    ->update(['password' => $hashedPassword]);
            }
            
            $passwordChanged = true;
        }

        // 3. Update linked Admin table
        $linkedAdmin = Admin::where('dealer_id', $dealer->id)->first();
        if ($linkedAdmin) {
            $linkedAdmin->full_name = $validated['full_name'];
            if (!empty($validated['contact_email'])) {
                $linkedAdmin->email = $validated['contact_email'];
            }
            if ($passwordChanged) {
                $linkedAdmin->password = $hashedPassword;
            }
            $linkedAdmin->save();
        }

        if ($passwordChanged) {
            return back()->with('success', "Dealer '{$dealer->full_name}' profile and password updated successfully.");
        }

        return back()->with('success', "Dealer '{$dealer->full_name}' updated successfully.");
    }

    public function toggleStatus(Request $request, $id)
    {
        $dealer = Dealer::findOrFail($id);

        $dealer->status = $dealer->status === 'active' ? 'archived' : 'active';
        $dealer->save();

        $adminStatus = $dealer->status === 'active' ? 'ACTIVE' : 'INACTIVE';

        Admin::where('dealer_id', $dealer->id)
            ->update(['status' => $adminStatus]);

        $label = $dealer->status === 'active' ? 'activated' : 'deactivated';

        return back()->with('success', "Dealer account {$label}. Portal login is now " . ($dealer->status === 'active' ? 'enabled' : 'blocked') . '.');
    }
}