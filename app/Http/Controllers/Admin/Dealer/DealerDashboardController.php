<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\SetupShalotrackDevice;

class DealerDashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $dealer = $user->dealer; // null if this account was never linked

        if (!$dealer) {
            return view('dealer.dashboard', [
                'dealer' => null,
            ]);
        }

        $allDevices = SetupShalotrackDevice::where('dealer_id', $dealer->id)->get();
        $availableDevices = $allDevices->where('status', 'Not Activated');

        $transfers = StockTransfer::with(['stock.deviceType'])
            ->where('dealer_id', $dealer->id)
            ->latest()
            ->get();

        // Low stock threshold — pick a sensible default, adjust if the
        // client wants a different number per device type later.
        $lowStockThreshold = 5;
        $lowStock = $availableDevices->count() < $lowStockThreshold;

        // Recent activity feed — built from real transfer + device data,
        // same pattern as the admin-side dealer profile page.
        $recentActivity = $transfers->take(5)->map(function ($t) {
            return [
                'date' => $t->created_at,
                'text' => "Received {$t->quantity} × " . ($t->stock->deviceType->model ?? 'device') . ($t->remarks ? " — {$t->remarks}" : ''),
            ];
        })->values();

        return view('dealer.dashboard', compact(
            'dealer', 'allDevices', 'availableDevices', 'transfers', 'lowStock', 'lowStockThreshold', 'recentActivity'
        ));
    }
}