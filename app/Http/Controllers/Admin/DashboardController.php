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
        // 1. Customer Growth Chart Data (මාස 6 ක ලියාපදිංචි වර්ධනය)
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i));

        $customerGrowthLabels = $months->map(fn ($m) => $m->format('M'))->toArray();
        $customerGrowthData = $months->map(function ($m) {
            return CustomerAd::where('created_at', '<=', $m->copy()->endOfMonth())->count();
        })->toArray();

        // 2. Customer Status Counts for Donut Chart
        $verifiedCustomers    = CustomerAd::where('cus_status', 'verified')->count();
        $notVerifiedCustomers = CustomerAd::where(function($q) {
            $q->where('cus_status', 'not_verified')
              ->orWhereNull('cus_status');
        })->count();

        // 3. Recent Customers Table Data
        $recentCustomers = CustomerAd::latest('created_at')
                            ->take(6)
                            ->get([
                                'customer_id',
                                'full_name',
                                'email',
                                'phone_number',
                                'nic_number',
                                'address',
                                'cus_status'
                            ]);

        $data = [
            'totalDevices'         => SetupShalotrackDevice::count(),
            'activatedDevices'     => SetupShalotrackDevice::where('status', 'Activated')->count(),
            'totalSuppliers'       => Supplier::count(),
            'totalDealers'         => Dealer::count(),
            'totalSIMs'            => Sim::count(),
            'totalStocks'          => Stock::count(),
            'totalCustomers'       => CustomerAd::count(),

            'verifiedCustomers'    => $verifiedCustomers,
            'notVerifiedCustomers' => $notVerifiedCustomers,

            'recentCustomers'      => $recentCustomers,

            'customerGrowthLabels' => $customerGrowthLabels,
            'customerGrowthData'   => $customerGrowthData,
        ];

        return view('admin.dashboard', $data);
    }
}