<?php

namespace App\Console\Commands;

use App\Models\CustomerAd;
use App\Models\VehicleAd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * customers:sync
 *
 * Pulls both customers AND vehicles from the ShaloTrack C# API in one
 * command run. Previously this only synced customers, and vehicles:sync
 * was never scheduled — leaving vehicle_ad permanently empty and causing
 * the dealer portal to show 0 vehicles for every customer.
 *
 * Root cause of 0 vehicles that required the C# fix:
 *   VehicleService.ToDto() called vehicle.Customer.FullName without a
 *   null-guard. Any orphaned vehicle row (Customer deleted after Vehicle
 *   created) threw NullReferenceException, which crashed the entire
 *   /api/internal/vehicles-sync response. The Laravel sync received a
 *   5xx, $response->successful() returned false, and vehicle_ad was
 *   never populated. The fix is vehicle.Customer?.FullName ?? string.Empty
 *   in VehicleService.cs.
 */
class SyncCustomersFromApi extends Command
{
    protected $signature   = 'customers:sync';
    protected $description = 'Pull customers AND vehicles from the ShaloTrack API and upsert both local mirror tables.';

    public function handle(): int
    {
        $customerResult = $this->syncCustomers();
        $vehicleResult  = $this->syncVehicles();

        return ($customerResult === self::SUCCESS && $vehicleResult === self::SUCCESS)
            ? self::SUCCESS
            : self::FAILURE;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function syncCustomers(): int
    {
        $response = Http::timeout(15)
            ->retry(3, 2000, throw: false)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->acceptJson()
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/customers-sync');

        if (! $response->successful()) {
            Log::error('Customer sync failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $this->error('Customer sync failed: HTTP ' . $response->status());
            $this->error('Response body: ' . $response->body());
            return self::FAILURE;
        }

        $customers = $response->json('data') ?? [];

        if (empty($customers)) {
            Log::warning('Customer sync returned 0 records — API may have returned empty data array.', [
                'response_keys' => array_keys($response->json() ?? []),
            ]);
            $this->warn('Customer sync returned 0 records.');
        }

        $synced = 0;

        foreach ($customers as $customer) {
            CustomerAd::updateOrCreate(
                ['customer_id' => $customer['customerId']],
                [
                    'full_name'             => $customer['fullName']      ?? null,
                    'email'                 => $customer['email']         ?? null,
                    'phone_number'          => $customer['phoneNumber']   ?? null,
                    'nic_number'            => $customer['nicNumber']     ?? null,
                    'address'               => $customer['address']       ?? null,
                    'profile_image'         => $customer['profileImage']  ?? null,
                    'vehicle_count'         => $customer['vehicleCount']  ?? 0,
                    'source_account_status' => $customer['accountStatus'] ?? null,
                    'last_synced_at'        => now(),
                ]
            );
            $synced++;
        }

        $this->info("Customers synced: {$synced}");
        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function syncVehicles(): int
    {
        $response = Http::timeout(15)
            ->retry(3, 2000, throw: false)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->acceptJson()
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/vehicles-sync');

        if (! $response->successful()) {
            Log::error('Vehicle sync failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $this->error('Vehicle sync failed: HTTP ' . $response->status());
            $this->error('Response body: ' . $response->body());
            return self::FAILURE;
        }

        $vehicles = $response->json('data') ?? [];

        if (empty($vehicles)) {
            Log::warning('Vehicle sync returned 0 records — API may have returned empty data array.', [
                'response_keys' => array_keys($response->json() ?? []),
            ]);
            $this->warn('Vehicle sync returned 0 records.');
        }

        $synced = 0;

        foreach ($vehicles as $vehicle) {
            VehicleAd::updateOrCreate(
                ['vehicle_id' => $vehicle['vehicleId']],
                [
                    'customer_id'    => $vehicle['customerId']    ?? null,
                    'customer_name'  => $vehicle['customerName']  ?? null,
                    'vehicle_number' => $vehicle['vehicleNumber'] ?? null,
                    'chassis_number' => $vehicle['chassisNumber'] ?? null,
                    'engine_number'  => $vehicle['engineNumber']  ?? null,
                    'make'           => $vehicle['make']          ?? null,
                    'model'          => $vehicle['model']         ?? null,
                    'year'           => $vehicle['year']          ?? null,
                    'color'          => $vehicle['color']         ?? null,
                    'vehicle_type'   => $vehicle['vehicleType']   ?? null,
                    'fuel_type'      => $vehicle['fuelType']      ?? null,
                    'has_gps_device' => $vehicle['hasGpsDevice']  ?? false,
                    'imei'           => $vehicle['imei']          ?? null,
                    'last_synced_at' => now(),
                ]
            );
            $synced++;
        }

        $this->info("Vehicles synced: {$synced}");
        return self::SUCCESS;
    }
}