<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use App\Services\AddressResolver;
use App\Models\VehicleAd;
use App\Models\DealerCustomerAd;
use App\Models\CustomerAd;
use App\Models\ActivatedDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
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

        // 1. Vehicle Search Dropdown Suggestions
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

    /**
     * Dealer-specific GPS Tracking View
     * Leverages the exact same fetchTrackingData mechanism used in Admin View
     */
    public function dealerIndex(Request $request)
    {
        $user = auth()->user();
        $dealer = $user->dealer ?? (\App\Models\Dealer::find($user->dealer_id) ?? null);

        if (!$dealer) {
            return redirect()->back()->with('error', 'Dealer profile not found.');
        }

        // 1. Dealer Customer Leads
        $dealerLeads = DealerCustomerAd::where('dealer_id', $dealer->id)->get();

        $emails = $dealerLeads->pluck('email')->filter()->map(fn($e) => strtolower(trim($e)))->toArray();
        $phones = $dealerLeads->pluck('contact')->filter()->map(function($p) {
            $digits = preg_replace('/[^0-9]/', '', $p);
            return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
        })->toArray();

        // 2. Customer Account IDs
        $customerIds = CustomerAd::query()
            ->where(function ($q) use ($emails, $phones) {
                if (!empty($emails)) {
                    $q->whereIn(DB::raw('LOWER(email)'), $emails);
                }
                foreach ($phones as $phone) {
                    $q->orWhere('phone_number', 'LIKE', '%' . $phone);
                }
            })
            ->pluck('customer_id')
            ->toArray();

        // 3. Vehicles with GPS belonging to this Dealer
        $vehicles = VehicleAd::whereIn('customer_id', $customerIds)
            ->whereNotNull('imei')
            ->where('imei', '!=', '')
            ->orderBy('vehicle_number')
            ->get();

        $selectedVehicleId = $request->input('vehicle_id');
        $selectedVehicle   = null;

        if ($selectedVehicleId) {
            $selectedVehicle = $vehicles->firstWhere('vehicle_id', $selectedVehicleId);
        }

        if (!$selectedVehicle && $vehicles->isNotEmpty()) {
            $selectedVehicle = $vehicles->first();
        }

        // 4. Fetch Exact Location via C# Sync API (Same as Admin)
        $locationData = [
            'latitude'    => null,
            'longitude'   => null,
            'speed'       => 0,
            'heading'     => 0,
            'timestamp'   => null,
            'is_online'   => false,
        ];
        $resolvedAddress = 'Location data unavailable';

        if ($selectedVehicle) {
            // C# API endpoint call via fetchTrackingData
            $trackingResult = $this->fetchTrackingData(
                $selectedVehicle->vehicle_number,
                $selectedVehicle->imei,
                null,
                null
            );

            $currentLoc = $trackingResult['currentLocation'] ?? null;

            if ($currentLoc && !empty($currentLoc['latitude']) && !empty($currentLoc['longitude'])) {
                $locationData['latitude']  = (float) $currentLoc['latitude'];
                $locationData['longitude'] = (float) $currentLoc['longitude'];
                $locationData['speed']     = $currentLoc['speed'] ?? 0;
                $locationData['heading']   = $currentLoc['heading'] ?? 0;
                $locationData['timestamp'] = $currentLoc['updatedAt'] ?? $currentLoc['timestamp'] ?? null;
                $locationData['is_online'] = true;

                // Resolve real address
                $resolvedAddress = $this->addressResolver->resolve(
                    $locationData['latitude'],
                    $locationData['longitude']
                );
            } else {
                // If API returns no current location, fallback to VehicleAd model coordinates if available
                if (!empty($selectedVehicle->latitude) && !empty($selectedVehicle->longitude)) {
                    $locationData['latitude']  = (float) $selectedVehicle->latitude;
                    $locationData['longitude'] = (float) $selectedVehicle->longitude;
                    $locationData['is_online'] = true;

                    $resolvedAddress = $this->addressResolver->resolve(
                        $locationData['latitude'],
                        $locationData['longitude']
                    );
                } else {
                    $resolvedAddress = "No active GPS signal or location updated for this vehicle.";
                }
            }
        }

        return view('dealer.gps_tracking', compact('vehicles', 'selectedVehicle', 'locationData', 'resolvedAddress'));
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

        $trips = $this->segmentTrips($historyData);

        $tripsWithAddress = collect($trips)->map(function ($trip) {
            $trip['start_address'] = $this->addressResolver->resolve((float) $trip['start_lat'], (float) $trip['start_lng']);
            $trip['end_address']   = $this->addressResolver->resolve((float) $trip['end_lat'], (float) $trip['end_lng']);
            return $trip;
        });

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
        ))->setPaper('a4', 'landscape');

        return $pdf->stream('vehicle_trips_report_' . now()->format('Y-m-d_His') . '.pdf');
    }
}