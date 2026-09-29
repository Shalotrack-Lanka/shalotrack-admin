<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\Supplier;
use App\Models\Dealer;
use App\Models\Sim;
use App\Models\Stock;
use App\Models\CustomerAd;
use App\Models\VehicleAd;
use App\Models\DashboardMetricSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i));

        $customerGrowthLabels = $months->map(fn ($m) => $m->format('M'))->toArray();
        $customerGrowthData = $months->map(function ($m) {
            return CustomerAd::where('created_at', '<=', $m->copy()->endOfMonth())->count();
        })->toArray();

        $verifiedCustomers    = CustomerAd::where('cus_status', 'verified')->count();
        $notVerifiedCustomers = CustomerAd::where(function($q) {
            $q->where('cus_status', 'not_verified')
              ->orWhereNull('cus_status');
        })->count();

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

        $gatewayOnline    = false;
        $devicesOnlineNow = 0;

        try {
            $gwResponse = Http::timeout(2)
                ->get(config('services.gateway.command_url', 'http://gateway.shalotrack.internal:8001') . '/devices');

            if ($gwResponse->successful()) {
                $gatewayOnline    = true;
                $devicesOnlineNow = count($gwResponse->json('connected_devices') ?? []);
            }
        } catch (\Throwable $e) {
            Log::warning('Dashboard: gateway /devices unreachable: ' . $e->getMessage());
        }

        $totalTrackedVehicles = VehicleAd::whereNotNull('imei')->where('imei', '!=', '')->count();
        $devicesOfflineNow    = max($totalTrackedVehicles - $devicesOnlineNow, 0);

        $openComplaints = [];
        // True when the complaints service could not be reached: the dashboard
        // then says so, instead of showing a reassuring (and false) "0".
        $complaintsUnavailable = false;

        try {
            $openComplaints = Cache::remember('dashboard_open_complaints', 30, function () {
                $response = Http::timeout(5)
                    ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                    ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

                if (!$response->successful()) {
                    // Throwing (not returning []) so a failure is neither cached
                    // for 30s nor mistaken for "no complaints".
                    throw new \RuntimeException('Complaints API returned HTTP ' . $response->status());
                }

                $complaints = $response->json('data') ?? [];
                usort($complaints, fn ($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

                return $complaints;
            });
        } catch (\Throwable $e) {
            $complaintsUnavailable = true;
            Log::warning('Dashboard: complaints fetch failed: ' . $e->getMessage());
        }

        $openComplaintsCount = count($openComplaints);
        $recentComplaints    = array_slice($openComplaints, 0, 5);

        $topDealerName          = null;
        $topDealerComplaintCount = 0;

        if ($openComplaintsCount > 0) {
            $byDealerDesc = collect($openComplaints)
                ->map(fn ($c) => $c['dealerName'] ?? $c['DealerName'] ?? null)
                ->map(fn ($name) => $name && trim($name) !== '' ? $name : 'Unassigned')
                ->countBy()
                ->sortDesc();

            if ($byDealerDesc->isNotEmpty()) {
                $topDealerName           = $byDealerDesc->keys()->first();
                $topDealerComplaintCount = $byDealerDesc->first();
            }
        }

        $notActivatedDevices = SetupShalotrackDevice::where('status', DeviceStatus::NotActivated->value)->count();

        $stockUnitsAvailable = (int) Stock::sum('company_available_stock');

        $outOfStockCategoryNames = Stock::where('company_available_stock', '<=', 0)
            ->pluck('device_category_type')
            ->filter()
            ->values()
            ->toArray();
        $outOfStockCategories = count($outOfStockCategoryNames);

        $resolvedComplaints = [];

        try {
            $resolvedComplaints = Cache::remember('dashboard_resolved_complaints', 30, function () {
                $response = Http::timeout(5)
                    ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                    ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin/resolved');

                if (!$response->successful()) {
                    throw new \RuntimeException('Resolved complaints API returned HTTP ' . $response->status());
                }

                return $response->json('data') ?? [];
            });
        } catch (\Throwable $e) {
            $complaintsUnavailable = true;
            Log::warning('Dashboard: resolved complaints fetch failed: ' . $e->getMessage());
        }

        $resolvedComplaintsCount = count($resolvedComplaints);

        $avgResolutionHours = null;

        $resolutionDurations = collect($resolvedComplaints)
            ->map(function ($c) {
                $createdAt  = $c['createdAt'] ?? $c['CreatedAt'] ?? null;
                $resolvedAt = $c['resolvedAt'] ?? $c['ResolvedAt'] ?? null;

                if (!$createdAt || !$resolvedAt) {
                    return null;
                }

                try {
                    return Carbon::parse($resolvedAt)->diffInMinutes(Carbon::parse($createdAt));
                } catch (\Throwable $e) {
                    return null;
                }
            })
            ->filter(fn ($minutes) => $minutes !== null && $minutes >= 0);

        if ($resolutionDurations->isNotEmpty()) {
            $avgResolutionHours = round($resolutionDurations->avg() / 60, 1);
        }

        $activeDealers   = Dealer::where('status', 'active')->count();
        $activeSuppliers = Supplier::where('status', 'Active')->count();

        $totalSIMs        = Sim::count();
        $activatedSIMs    = Sim::where('sim_status', 'Activated')->count();

        $staleSummary = app(\App\Services\StaleDeviceService::class)->summarize(
            VehicleAd::whereNotNull('imei')->where('imei', '!=', '')->pluck('imei')
        );
        $staleDevicesCount       = $staleSummary['stale'];
        $staleDevicesUnavailable = $staleSummary['unavailable'];

        $trendSnapshot = DashboardMetricSnapshot::where('snapshot_date', '<=', now()->subDays(7)->toDateString())
            ->orderByDesc('snapshot_date')
            ->first();

        $trends = [
            'openComplaints' => $trendSnapshot ? $openComplaintsCount - $trendSnapshot->open_complaints_count : null,
            'devicesOnline'  => $trendSnapshot ? $devicesOnlineNow - $trendSnapshot->devices_online : null,
            'stockUnits'     => $trendSnapshot ? $stockUnitsAvailable - $trendSnapshot->stock_units_available : null,
        ];
        $trendSnapshotDate = $trendSnapshot?->snapshot_date?->format('M j');

        $data = [
            'totalDevices'          => SetupShalotrackDevice::count(),
            'activatedDevices'      => SetupShalotrackDevice::where('status', DeviceStatus::Activated->value)->count(),
            'notActivatedDevices'   => $notActivatedDevices,
            'totalSuppliers'        => Supplier::count(),
            'activeSuppliers'       => $activeSuppliers,
            'totalDealers'          => Dealer::count(),
            'activeDealers'         => $activeDealers,
            'totalSIMs'             => $totalSIMs,
            'activatedSIMs'         => $activatedSIMs,
            'stockUnitsAvailable'   => $stockUnitsAvailable,
            'outOfStockCategories'      => $outOfStockCategories,
            'outOfStockCategoryNames'   => $outOfStockCategoryNames,
            'totalCustomers'        => CustomerAd::count(),

            'verifiedCustomers'     => $verifiedCustomers,
            'notVerifiedCustomers'  => $notVerifiedCustomers,

            'recentCustomers'       => $recentCustomers,

            'customerGrowthLabels'  => $customerGrowthLabels,
            'customerGrowthData'    => $customerGrowthData,

            'gatewayOnline'         => $gatewayOnline,
            'devicesOnlineNow'      => $devicesOnlineNow,
            'devicesOfflineNow'     => $devicesOfflineNow,
            'totalTrackedVehicles'  => $totalTrackedVehicles,
            'staleDevicesCount'     => $staleDevicesCount,
            'staleDevicesUnavailable' => $staleDevicesUnavailable,

            'openComplaintsCount'      => $openComplaintsCount,
            'complaintsUnavailable'    => $complaintsUnavailable,
            'resolvedComplaintsCount'  => $resolvedComplaintsCount,
            'recentComplaints'         => $recentComplaints,
            'avgResolutionHours'       => $avgResolutionHours,

            'topDealerName'            => $topDealerName,
            'topDealerComplaintCount'  => $topDealerComplaintCount,

            'trends'             => $trends,
            'trendSnapshotDate'  => $trendSnapshotDate,
        ];

        return view('admin.dashboard', $data);
    }
}