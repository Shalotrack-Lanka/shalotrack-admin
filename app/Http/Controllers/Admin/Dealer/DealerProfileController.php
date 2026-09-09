<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Dealer;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealerProfileController extends Controller
{
    private const VALID_DEALER_STATUSES = [
        'lbc', 'distributor', 'retailer', 'dsa', 'ba', 'csa', 'lt_point',
    ];

    private const VALID_REGIONS = [
        'central', 'colombo', 'eastern', 'gampaha',
        'north_central', 'north_western', 'northern',
        'sabaragamuwa', 'southern', 'uva', 'western',
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
            'pin_code'         => 'nullable|digits_between:4,10',
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
        ], [
            'dealer_status.in'             => 'Please select a valid dealer type.',
            'region.in'                    => 'Please select a valid region.',
            'pin_code.digits_between'      => 'Pin code must be 4–10 digits.',
            'contact_email.unique'         => 'This email is already registered to another dealer.',
            'deposit_date.before_or_equal' => 'Deposit date cannot be in the future.',
        ]);

        $dealer->update($validated);

        $linkedAdmin = Admin::where('dealer_id', $dealer->id)->first();
        if ($linkedAdmin) {
            $linkedAdmin->full_name = $validated['full_name'];
            if (!empty($validated['contact_email'])) {
                $linkedAdmin->email = $validated['contact_email'];
            }
            $linkedAdmin->save();
        }

        return back()->with('success', "Dealer '{$dealer->full_name}' updated successfully.");
    }

    /**
     * Toggle the dealer's active / archived status.
     *
     * This must also mirror the change onto the linked Admin login row
     * (Admin.status = ACTIVE / INACTIVE), because LoginRequest gates access
     * using Admin.status. Without this, toggling the Dealer row had zero
     * effect on whether the dealer could actually log in.
     *
     * If the dealer is currently logged in, their session is immediately
     * invalidated by the EnforceAdminStatus middleware on the next request —
     * they cannot keep browsing after deactivation.
     */
    public function toggleStatus(Request $request, $id)
    {
        $dealer = Dealer::findOrFail($id);

        // Toggle the dealer business record.
        $dealer->status = $dealer->status === 'active' ? 'archived' : 'active';
        $dealer->save();

        // Mirror the change on the linked Admin login so the login gate
        // (LoginRequest: ->where('status', 'ACTIVE')) actually blocks them.
        $adminStatus = $dealer->status === 'active' ? 'ACTIVE' : 'INACTIVE';

        Admin::where('dealer_id', $dealer->id)
            ->update(['status' => $adminStatus]);

        $label = $dealer->status === 'active' ? 'activated' : 'deactivated';

        return back()->with('success', "Dealer account {$label}. Portal login is now " . ($dealer->status === 'active' ? 'enabled' : 'blocked') . '.');
    }
}