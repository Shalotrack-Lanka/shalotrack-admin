<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use App\Services\AddressResolver;
use App\Models\VehicleAd;
use App\Models\ActivatedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;

class GpsTrackingController extends Controller
{
    private AddressResolver $addressResolver;

    public function __construct(AddressResolver $addressResolver)
    {
        $this->addressResolver = $addressResolver;
    }

    public function index(Request $request)
    {
        set_time_limit(180);

        $search    = trim((string) $request->input('search'));
        $fromDate  = $request->input('from_date');
        $toDate    = $request->input('to_date');

        $vehicle = null;
        $currentLocation = null;
        $historyData = collect();
        $errorMessage = null;

        // 1. Vehicle Search Dropdown Suggestions (API + Local DB Fallback)
        $vehicleNumbers = collect();
        try {
            $vehiclesResponse = Http::timeout(5)
                ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                ->acceptJson()
                ->get(config('services.shalotrack_api.base_url') . '/api/internal/vehicles-sync');

            if ($vehiclesResponse->successful()) {
                $vehicleNumbers = collect($vehiclesResponse->json('data') ?? [])
                    ->pluck('vehicleNumber')
                    ->filter();
            }
        } catch (\Throwable $e) {
            Log::warning('Vehicle API suggestion failed, loading from local DB: ' . $e->getMessage());
        }

        // API fail or no vehicles returned, fallback to local DB
        if ($vehicleNumbers->isEmpty()) {
            $dbVehicles = VehicleAd::pluck('vehicle_number');
            $activeVehicles = ActivatedDevice::pluck('vehicle_number');
            $vehicleNumbers = $dbVehicles->merge($activeVehicles)->filter()->unique()->values();
        } else {
            $vehicleNumbers = $vehicleNumbers->values();
        }

        // 2. Fetch Tracking Data on Search
        if ($search !== '') {
            $isImei = (bool) preg_match('/^\d{15}$/', $search);

            $result = $this->fetchTrackingData(
                $isImei ? null : $search,
                $isImei ? $search : null,
                $this->toDayStart($fromDate),
                $this->toDayEnd($toDate)
            );

            $vehicle         = $result['vehicle'];
            $currentLocation = $result['currentLocation'];
            $historyData     = $result['historyData'];
            $errorMessage    = $result['errorMessage'];
        }

        $trips = $this->segmentTrips($historyData);

        return view('admin.vehicles.gps_tracking', compact(
            'search', 'fromDate', 'toDate', 'vehicle', 'currentLocation', 'historyData', 'trips', 'errorMessage', 'vehicleNumbers'
        ));
    }

    public function resolveAddress(Request $request)
    {
        $validated = $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
        ]);

        return response()->json([
            'address' => $this->addressResolver->resolve(
                (float) $validated['lat'],
                (float) $validated['lng']
            ),
        ]);
    }

    private function toDayStart(?string $date): ?string
    {
        return $date ? $date . 'T00:00:00' : null;
    }

    private function toDayEnd(?string $date): ?string
    {
        return $date ? $date . 'T23:59:59' : null;
    }

    private function fetchTrackingData(?string $vehicleNumber, ?string $imei, ?string $fromDate, ?string $toDate): array
    {
        $vehicle = null;
        $currentLocation = null;
        $historyData = collect();
        $errorMessage = null;
        $gotAnySuccessfulPage = false;

        $page = 1;
        $pageSize = 500;
        $pagePoints = collect();

        do {
            $query = array_filter([
                'vehicleNumber' => $vehicleNumber,
                'imei'          => $imei,
                'from'          => $fromDate,
                'to'            => $toDate,
                'page'          => $page,
                'pageSize'      => $pageSize,
            ]);

            $response = Http::timeout(20)
                ->retry(2, 1000, throw: false)
                ->withHeaders([
                    'X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key'),
                ])
                ->acceptJson()
                ->get(config('services.shalotrack_api.base_url') . '/api/internal/gps-tracking-sync', $query);

            if (! $response->successful()) {
                if (! $gotAnySuccessfulPage) {
                    $errorMessage = $response->status() === 404
                        ? ($response->json('message') ?? 'Vehicle or device not found.')
                        : 'Could not load tracking data (status ' . $response->status() . '). Please try again.';
                }
                break;
            }

            $gotAnySuccessfulPage = true;

            if ($page === 1) {
                $vehicle         = $response->json('vehicle');
                $currentLocation = $response->json('currentLocation');
            }

            $pagePoints  = collect($response->json('trackingHistory') ?? []);
            $historyData = $historyData->concat($pagePoints);

            $page++;

        } while ($pagePoints->count() >= $pageSize && $page <= 20);

        return compact('vehicle', 'currentLocation', 'historyData', 'errorMessage');
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
        $durationMin = (int) round($startTime->diffInMinutes($endTime));

        return [
            'start_time'    => $startTime,
            'end_time'      => $endTime,
            'duration_min'  => $durationMin,
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

    /**
     * Generate & Download PDF Report safely without memory crashing
     */
   public function exportPdf(Request $request)
{
    ini_set('memory_limit', '512M');
    set_time_limit(300);

    $search    = trim((string) $request->input('search'));
    $fromDate  = $request->input('from_date');
    $toDate    = $request->input('to_date');

    $vehicle = null;
    $historyData = collect();

    if ($search !== '') {
        $isImei = (bool) preg_match('/^\d{15}$/', $search);

        $result = $this->fetchTrackingData(
            $isImei ? null : $search,
            $isImei ? $search : null,
            $this->toDayStart($fromDate),
            $this->toDayEnd($toDate)
        );

        $vehicle     = $result['vehicle'];
        $historyData = $result['historyData'];
    }

    // 1. Trip Data
    $trips = $this->segmentTrips($historyData);

    // 2. Start / End Coordinates 
    $tripsWithAddress = collect($trips)->map(function ($trip) {
        $trip['start_address'] = $this->addressResolver->resolve((float) $trip['start_lat'], (float) $trip['start_lng']);
        $trip['end_address']   = $this->addressResolver->resolve((float) $trip['end_lat'], (float) $trip['end_lng']);
        return $trip;
    });

    // 3. Logo Base64
    $logoPath = public_path('images/logo.png');
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $typeImg = pathinfo($logoPath, PATHINFO_EXTENSION);
        $dataImg = file_get_contents($logoPath);
        $logoBase64 = 'data:image/' . $typeImg . ';base64,' . base64_encode($dataImg);
    }

    $title = 'VEHICLE TRIP & ROUTE HISTORY REPORT';

    $pdf = Pdf::loadView('admin.vehicles.reports.gps_tracking_pdf', compact(
        'vehicle',
        'tripsWithAddress',
        'fromDate',
        'toDate',
        'title',
        'logoBase64'
    ))->setPaper('a4', 'landscape'); // landscape orientation for better width

    return $pdf->stream('vehicle_trips_report_' . now()->format('Y-m-d_His') . '.pdf');
}
}