<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class GpsTrackingController extends Controller
{
    public function index(Request $request)
    {
        // Get search parameters from the request
        $imei = $request->input('imei');
        $vehicle_id = $request->input('vehicle_id');
        $customer_id = $request->input('customer_id');
        $from_date = $request->input('from_date');
        $to_date = $request->input('to_date');

        // Here you will write the query to fetch GPS history from your specific tracker database table
        // Example (You need to replace 'GpsHistory' with your actual table):
        // $historyData = DB::table('GpsHistory')
        //     ->when($imei, function($query) use ($imei) { return $query->where('IMEI', $imei); })
        //     ->when($from_date, function($query) use ($from_date, $to_date) { 
        //         return $query->whereBetween('Timestamp', [$from_date, $to_date]); 
        //     })->get();

        $historyData = []; // Replace with actual queried data

        return view('admin.vehicles.gps_tracking', compact('historyData', 'imei', 'vehicle_id', 'customer_id', 'from_date', 'to_date'));
    }
}