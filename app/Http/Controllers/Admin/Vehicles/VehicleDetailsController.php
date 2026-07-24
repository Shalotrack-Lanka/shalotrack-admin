<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\VehicleAd;
use Illuminate\Support\Facades\Artisan;

class VehicleDetailsController extends Controller
{
    public function index()
    {
        // Sync first, then read from the local mirror — Vehicles lives on
        // the other Supabase behind the API, never queried directly here.
        Artisan::call('vehicles:sync');

        $vehicles = VehicleAd::orderBy('created_at', 'desc')->paginate(10);

        return view('admin.vehicles.vehicle_details', compact('vehicles'));
    }
}