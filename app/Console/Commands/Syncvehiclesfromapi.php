<?php

namespace App\Console\Commands;

use App\Models\VehicleAd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncVehiclesFromApi extends Command
{
    protected $signature = 'vehicles:sync';
    protected $description = 'Pull vehicles from the ShaloTrack API and upsert into vehicle_ad';

    public function handle(): int
    {
        $response = Http::timeout(15)
            ->retry(3, 2000, throw: false)
            ->withHeaders([
                'X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')
            ])
            ->acceptJson()
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/vehicles-sync');

        if (! $response->successful()) {
            Log::error('Vehicle sync failed', ['status' => $response->status(), 'body' => $response->body()]);
            $this->error('API call failed: ' . $response->status());
            return self::FAILURE;
        }

        $vehicles = $response->json('data') ?? [];
        $synced = 0;

        foreach ($vehicles as $vehicle) {
            VehicleAd::updateOrCreate(
                ['vehicle_id' => $vehicle['vehicleId']],
                [
                    'customer_id'    => $vehicle['customerId'] ?? null,
                    'customer_name'  => $vehicle['customerName'] ?? null,
                    'vehicle_number' => $vehicle['vehicleNumber'] ?? null,
                    'chassis_number' => $vehicle['chassisNumber'] ?? null,
                    'engine_number'  => $vehicle['engineNumber'] ?? null,
                    'make'           => $vehicle['make'] ?? null,
                    'model'          => $vehicle['model'] ?? null,
                    'year'           => $vehicle['year'] ?? null,
                    'color'          => $vehicle['color'] ?? null,
                    'vehicle_type'   => $vehicle['vehicleType'] ?? null,
                    'fuel_type'      => $vehicle['fuelType'] ?? null,
                    'has_gps_device' => $vehicle['hasGpsDevice'] ?? false,
                    'imei'           => $vehicle['imei'] ?? null,
                    'last_synced_at' => now(),
                ]
            );
            $synced++;
        }

        $this->info("Synced {$synced} vehicles.");
        return self::SUCCESS;
    }
}