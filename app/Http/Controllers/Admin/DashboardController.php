<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\Supplier;
use App\Models\Dealer;
use App\Models\Sim;
use App\Models\Stock;

class DashboardController extends Controller
{
    public function index()
    {
        $data = [
            'totalDevices'      => SetupShalotrackDevice::count(),
            'activatedDevices'  => SetupShalotrackDevice::where('status','Activated')->count(),
            'pendingDevices'    => SetupShalotrackDevice::where('status','Not Activated')->count(),
            'stoppedDevices'    => SetupShalotrackDevice::where('status','Temporarily Stopped')->count(),

            'totalSuppliers'    => Supplier::count(),
            'totalDealers'      => Dealer::count(),
            'totalSIMs'         => Sim::count(),
            'totalStocks'       => Stock::count(),

            'recentDevices' => SetupShalotrackDevice::latest()
                                ->take(5)
                                ->get(),
        ];

        return view('admin.dashboard',$data);
    }
}