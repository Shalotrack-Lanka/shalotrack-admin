<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GpsTrackingController extends Controller
{
    public function index(Request $request)
    {
        $vehicleId = $request->input('vehicle_id');
        $imei      = $request->input('imei');
        $fromDate  = $request->input('from_date');
        $toDate    = $request->input('to_date');

        $historyData = collect();
        $errorMessage = null;

        // Search by either Vehicle ID or IMEI — at least one is required.
        // IMEI is resolved to a DeviceId server-side on the API, not here.
        // No local table, no sync command: GPS history is high-volume
        // telemetry, unlike Customers/Vehicles — this is a live proxy only.
        if ($vehicleId || $imei) {
            $response = Http::timeout(15)
                ->retry(2, 1000, throw: false)
                ->withHeaders([
                    'X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key'),
                ])
                ->acceptJson()
                ->get(config('services.shalotrack_api.base_url') . '/api/internal/gps-tracking-sync', array_filter([
                    'vehicleId' => $vehicleId,
                    'imei'      => $imei,
                    'from'      => $fromDate,
                    'to'        => $toDate,
                ]));

            if ($response->successful()) {
                $historyData = collect($response->json('data') ?? []);
            } else {
                Log::error('GPS tracking fetch failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                $errorMessage = 'Could not load tracking data (status ' . $response->status() . '). Please try again.';
            }
        }

        return view('admin.vehicles.gps_tracking', compact(
            'historyData', 'vehicleId', 'imei', 'fromDate', 'toDate', 'errorMessage'
        ));
    }
}