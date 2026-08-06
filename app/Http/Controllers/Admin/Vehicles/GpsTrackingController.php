<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

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
            // Auto-detect: a 15-digit number is an IMEI, anything else is
            // treated as a Vehicle Number. No more raw UUID search — both
            // IMEI and Vehicle Number are resolved SERVER-SIDE on the API,
            // since that's the only place the real Vehicles table lives.
            $isImei = (bool) preg_match('/^\d{15}$/', $search);

            $query = array_filter([
                'vehicleNumber' => $isImei ? null : $search,
                'imei'          => $isImei ? $search : null,
                'from'          => $fromDate,
                'to'            => $toDate,
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

        $trips = $this->segmentTrips($historyData);

        return view('admin.vehicles.gps_tracking', compact(
            'search', 'fromDate', 'toDate', 'vehicle', 'currentLocation', 'historyData', 'trips', 'errorMessage'
        ));
    }

    private function segmentTrips($points, float $stopSpeedThreshold = 2.0, int $stopMinutes = 5): array
    {
        $ordered = $points->reverse()->values();
        $trips = [];
        $current = [];

        $i = 0;
        $count = $ordered->count();

        while ($i < $count) {
            $point = $ordered[$i];
            $current[] = $point;

            $speed = (float) ($point['speed'] ?? 0);

            if ($speed <= $stopSpeedThreshold) {
                $stopStartTime = \Carbon\Carbon::parse($point['eventTime']);
                $j = $i + 1;

                while ($j < $count && (float) ($ordered[$j]['speed'] ?? 0) <= $stopSpeedThreshold) {
                    $j++;
                }

                $stopEndTime = $j > $i ? \Carbon\Carbon::parse($ordered[$j - 1]['eventTime']) : $stopStartTime;

                if ($stopStartTime->diffInMinutes($stopEndTime) >= $stopMinutes && count($current) > 1) {
                    $trips[] = $this->buildTripSummary($current);
                    $current = [];
                    $i = $j;
                    continue;
                }
            }

            $i++;
        }

        if (count($current) > 1) {
            $trips[] = $this->buildTripSummary($current);
        }

        return array_reverse($trips);
    }

    private function buildTripSummary(array $points): array
    {
        $start = $points[0];
        $end = $points[count($points) - 1];

        $distanceKm = 0;
        for ($i = 1; $i < count($points); $i++) {
            $distanceKm += $this->haversineKm(
                (float) $points[$i - 1]['latitude'], (float) $points[$i - 1]['longitude'],
                (float) $points[$i]['latitude'], (float) $points[$i]['longitude']
            );
        }

        $startTime = \Carbon\Carbon::parse($start['eventTime']);
        $endTime = \Carbon\Carbon::parse($end['eventTime']);

        return [
            'start_time'    => $startTime,
            'end_time'      => $endTime,
            'duration_min'  => $startTime->diffInMinutes($endTime),
            'start_lat'     => $start['latitude'],
            'start_lng'     => $start['longitude'],
            'end_lat'       => $end['latitude'],
            'end_lng'       => $end['longitude'],
            'distance_km'   => round($distanceKm, 1),
            'points'        => array_reverse($points),
        ];
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    public function exportPdf(Request $request)
    {
        $search    = trim((string) $request->input('search'));
        $fromDate  = $request->input('from_date');
        $toDate    = $request->input('to_date');

        $vehicle = null;
        $historyData = collect();

        if ($search !== '') {
            $isImei = (bool) preg_match('/^\d{15}$/', $search);

            $query = array_filter([
                'vehicleNumber' => $isImei ? null : $search,
                'imei'          => $isImei ? $search : null,
                'from'          => $fromDate,
                'to'            => $toDate,
            ]);

            $response = Http::timeout(15)
                ->retry(2, 1000, throw: false)
                ->withHeaders([
                    'X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key'),
                ])
                ->acceptJson()
                ->get(config('services.shalotrack_api.base_url') . '/api/internal/gps-tracking-sync', $query);

            if ($response->successful()) {
                $vehicle     = $response->json('vehicle');
                $historyData = collect($response->json('trackingHistory') ?? []);
            }
        }

        $pdf = Pdf::loadView('admin.vehicles.reports.gps_tracking_pdf', compact('vehicle', 'historyData', 'fromDate', 'toDate'));

        return $pdf->download('gps_tracking_' . now()->format('Y-m-d_His') . '.pdf');
    }
}