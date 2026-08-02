<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\Supplier;
use App\Models\Dealer;
use App\Models\Sim;
use App\Models\Stock;
use App\Models\CustomerAd;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        // --- Customer Growth: real cumulative count over the last 6 months ---
        // Uses CustomerAd's own created_at (when first synced into this local
        // mirror) — the API doesn't currently expose a true original signup
        // date, so this reflects "known to Admin since," not the customer's
        // actual registration date on the API side. Close enough for a trend
        // line, but worth knowing if the exact wording matters later.
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i));

        $customerGrowthLabels = $months->map(fn ($m) => $m->format('M'))->toArray();
        $customerGrowthData = $months->map(function ($m) {
            return CustomerAd::where('created_at', '<=', $m->copy()->endOfMonth())->count();
        })->toArray();

        $data = [
            'totalDevices'      => SetupShalotrackDevice::count(),
            'activatedDevices'  => SetupShalotrackDevice::where('status','Activated')->count(),
            'pendingDevices'    => SetupShalotrackDevice::where('status','Not Activated')->count(),
            'stoppedDevices'    => SetupShalotrackDevice::where('status','Temporarily Stopped')->count(),

            'totalSuppliers'    => Supplier::count(),
            'totalDealers'      => Dealer::count(),
            'totalSIMs'         => Sim::count(),
            'totalStocks'       => Stock::count(),
            'totalCustomers'    => CustomerAd::count(),

            'recentDevices' => SetupShalotrackDevice::latest()
                                ->take(5)
                                ->get(),

            'recentCustomers' => CustomerAd::latest()
                                ->take(5)
                                ->get(),

            'customerGrowthLabels' => $customerGrowthLabels,
            'customerGrowthData'   => $customerGrowthData,
        ];

        return view('admin.dashboard',$data);
    }
}