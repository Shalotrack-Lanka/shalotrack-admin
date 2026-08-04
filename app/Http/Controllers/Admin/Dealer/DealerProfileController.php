<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\StockTransfer;
use App\Models\SetupShalotrackDevice;
use Illuminate\Http\Request;

class DealerProfileController extends Controller
{
    /**
     * Admin-facing: view a SPECIFIC dealer's business profile by ID.
     * Not to be confused with DealerAccountController, which is the
     * self-service page a logged-in Dealer uses to edit their OWN profile.
     */
    public function show($id)
    {
        $dealer = Dealer::findOrFail($id);

        $assignedDevices = SetupShalotrackDevice::where('dealer_id', $id)
            ->latest('shdevice_id')
            ->get();

        $transfers = StockTransfer::with(['stock.deviceType'])
            ->where('dealer_id', $id)
            ->latest()
            ->get();

        $totalDevicesTransferred = $transfers->sum('quantity');

        $recentActivity = $transfers->take(5)->map(function ($t) {
            return [
                'date' => $t->created_at,
                'text' => "Received {$t->quantity} × " . ($t->stock->deviceType->model ?? 'device') . ($t->remarks ? " — {$t->remarks}" : ''),
            ];
        })->values();

        return view('admin.dealer.profile_view', compact(
            'dealer', 'assignedDevices', 'transfers', 'totalDevicesTransferred', 'recentActivity'
        ));
    }

    public function toggleStatus(Request $request, $id)
    {
        $dealer = Dealer::findOrFail($id);
        $dealer->status = $dealer->status === 'active' ? 'archived' : 'active';
        $dealer->save();

        return back()->with('success', "Dealer status updated to \"" . ucfirst($dealer->status) . "\".");
    }
}