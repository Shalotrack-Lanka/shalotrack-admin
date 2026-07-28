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
        $search    = trim((string) $request->input('search'));
        $fromDate  = $request->input('from_date');
        $toDate    = $request->input('to_date');

        $vehicle = null;
        $currentLocation = null;
        $historyData = collect();
        $errorMessage = null;

        if ($search !== '') {
            // Auto-detect: a UUID has this exact dashed pattern, an IMEI is
            // 15 plain digits. No need to make the user pick which field to use.
            $isUuid = (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $search);

            $query = array_filter([
                'vehicleId' => $isUuid ? $search : null,
                'imei'      => $isUuid ? null : $search,
                'from'      => $fromDate,
                'to'        => $toDate,
            ]);

            $response = Http::timeout(15)
                ->retry(2, 1000, throw: false)
                ->withHeaders([
                    'X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key'),
                ])
                ->acceptJson()
                ->get(config('services.shalotrack_api.base_url') . '/api/internal/gps-tracking-sync', $query);

            if ($response->successful()) {
                $vehicle         = $response->json('vehicle');
                $currentLocation = $response->json('currentLocation');
                $historyData     = collect($response->json('trackingHistory') ?? []);
            } else {
                Log::error('GPS tracking fetch failed', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                $errorMessage = $response->status() === 404
                    ? ($response->json('message') ?? 'Vehicle or device not found.')
                    : 'Could not load tracking data (status ' . $response->status() . '). Please try again.';
            }
        }

        return view('admin.vehicles.gps_tracking', compact(
            'search', 'fromDate', 'toDate', 'vehicle', 'currentLocation', 'historyData', 'errorMessage'
        ));
    }
}