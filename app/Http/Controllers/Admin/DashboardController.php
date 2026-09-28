<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\Supplier;
use App\Models\Dealer;
use App\Models\Sim;
use App\Models\Stock;
use App\Models\CustomerAd;
use App\Models\VehicleAd;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $months = collect(range(5, 0))->map(fn($i) => now()->subMonths($i));

        $customerGrowthLabels = $months->map(fn($m) => $m->format('M'))->toArray();
        $customerGrowthData = $months->map(function ($m) {
            return CustomerAd::where('created_at', '<=', $m->copy()->endOfMonth())->count();
        })->toArray();

        $verifiedCustomers    = CustomerAd::where('cus_status', 'verified')->count();
        $notVerifiedCustomers = CustomerAd::where(function ($q) {
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

        try {
            $openComplaints = Cache::remember('dashboard_open_complaints', 30, function () {
                $response = Http::timeout(5)
                    ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                    ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

                if (!$response->successful()) {
                    return [];
                }

                $complaints = $response->json('data') ?? [];
                usort($complaints, fn($a, $b) => strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''));

                return $complaints;
            });
        } catch (\Throwable $e) {
            Log::warning('Dashboard: complaints fetch failed: ' . $e->getMessage());
        }

        $openComplaintsCount = count($openComplaints);
        $recentComplaints    = array_slice($openComplaints, 0, 5);

        $notActivatedDevices = SetupShalotrackDevice::where('status', 'Not Activated')->count();

        $stockUnitsAvailable = (int) Stock::sum('company_available_stock');

        $outOfStockCategories = Stock::where('company_available_stock', '<=', 0)->count();

        $resolvedComplaintsCount = 0;

        try {
            $resolvedComplaintsCount = Cache::remember('dashboard_resolved_complaints_count', 30, function () {
                $response = Http::timeout(5)
                    ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                    ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin/resolved');

                return $response->successful() ? count($response->json('data') ?? []) : 0;
            });
        } catch (\Throwable $e) {
            Log::warning('Dashboard: resolved complaints fetch failed: ' . $e->getMessage());
        }

        $activeDealers   = Dealer::where('status', 'active')->count();
        $activeSuppliers = Supplier::where('status', 'Active')->count();

        $totalSIMs        = Sim::count();
        $activatedSIMs    = Sim::where('sim_status', 'Activated')->count();

        $data = [
            'totalDevices'          => SetupShalotrackDevice::count(),
            'activatedDevices'      => SetupShalotrackDevice::where('status', 'Activated')->count(),
            'notActivatedDevices'   => $notActivatedDevices,
            'totalSuppliers'        => Supplier::count(),
            'activeSuppliers'       => $activeSuppliers,
            'totalDealers'          => Dealer::count(),
            'activeDealers'         => $activeDealers,
            'totalSIMs'             => $totalSIMs,
            'activatedSIMs'         => $activatedSIMs,
            'stockUnitsAvailable'   => $stockUnitsAvailable,
            'outOfStockCategories'  => $outOfStockCategories,
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

            'openComplaintsCount'      => $openComplaintsCount,
            'resolvedComplaintsCount'  => $resolvedComplaintsCount,
            'recentComplaints'         => $recentComplaints,
        ];

        return view('admin.dashboard', $data);
    }
}
