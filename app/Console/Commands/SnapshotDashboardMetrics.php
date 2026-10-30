<?php

namespace App\Console\Commands;

use App\Models\DashboardMetricSnapshot;
use App\Models\Stock;
use App\Models\VehicleAd;
use App\Services\GatewayStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SnapshotDashboardMetrics extends Command
{
    protected $signature = 'dashboard:snapshot';

    protected $description = 'Records today\'s key dashboard metrics (open complaints, devices online/offline, stock units) '
        . 'so the dashboard can show week-over-week trend deltas instead of raw, context-free numbers. '
        . 'Upserts by date, so re-running the same day just overwrites today\'s row -- safe to run more than once.';

    public function handle(): int
    {
        $openComplaintsCount = 0;

        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

            if ($response->successful()) {
                $openComplaintsCount = count($response->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::warning('dashboard:snapshot - complaints fetch failed: ' . $e->getMessage());
        }

        $gateway       = GatewayStatus::connectedDevices();
        $devicesOnline = $gateway['devices']->count();

        $totalTrackedVehicles = VehicleAd::whereNotNull('imei')->where('imei', '!=', '')->count();
        $devicesOffline = max($totalTrackedVehicles - $devicesOnline, 0);

        $stockUnitsAvailable = (int) Stock::sum('company_available_stock');

        DashboardMetricSnapshot::updateOrCreate(
            ['snapshot_date' => now()->toDateString()],
            [
                'open_complaints_count'  => $openComplaintsCount,
                'devices_online'         => $devicesOnline,
                'devices_offline'        => $devicesOffline,
                'stock_units_available'  => $stockUnitsAvailable,
            ]
        );

        $this->info("Dashboard snapshot recorded for " . now()->toDateString()
            . ": {$openComplaintsCount} open complaints, {$devicesOnline} devices online, "
            . "{$stockUnitsAvailable} stock units.");

        return self::SUCCESS;
    }
}