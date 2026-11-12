<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\VehicleAd;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class VehicleDetailsController extends Controller
{
    public function index()
    {
        // Sync first, then read from the local mirror — Vehicles lives on
        // the other Supabase behind the API, never queried directly here.
        // If the app server cannot be reached the command throws; the page must still open with the vehicles
        // already saved here (the same way the GPS tracking page copes) instead of showing a 500 error.
        try {
            Artisan::call('vehicles:sync');
        } catch (\Throwable $e) {
            Log::warning('Vehicle details: sync skipped, showing the saved list: ' . $e->getMessage());
        }

        $vehicles = VehicleAd::orderBy('created_at', 'desc')->paginate(10);

        return view('admin.vehicles.vehicle_details', compact('vehicles'));
    }
}