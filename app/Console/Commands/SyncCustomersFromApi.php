<?php

namespace App\Console\Commands;

use App\Models\CustomerAd;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncCustomersFromApi extends Command
{
    protected $signature = 'customers:sync';
    protected $description = 'Pull customers from the ShaloTrack API and upsert into Customer-ad (sync fields only, never touches admin fields)';

    public function handle(): int
    {
        $response = Http::timeout(15)
        ->retry(3, 2000)
        ->get(config('services.shalotrack_api.base_url') . '/api/Customers');

        if (! $response->successful()) {
            Log::error('Customer sync failed', ['status' => $response->status(), 'body' => $response->body()]);
            $this->error('API call failed: ' . $response->status());
            return self::FAILURE;
        }

        $customers = $response->json('data') ?? [];
        $synced = 0;

        foreach ($customers as $customer) {
            CustomerAd::updateOrCreate(
                ['customer_id' => $customer['customerId']],
                [
                    'full_name'             => $customer['fullName'] ?? null,
                    'email'                 => $customer['email'] ?? null,
                    'phone_number'          => $customer['phoneNumber'] ?? null,
                    'nic_number'            => $customer['nicNumber'] ?? null,
                    'address'               => $customer['address'] ?? null,
                    'profile_image'         => $customer['profileImage'] ?? null,
                    'vehicle_count'         => $customer['vehicleCount'] ?? 0,
                    'source_account_status' => $customer['accountStatus'] ?? null,
                    'last_synced_at'        => now(),
                ]
            );
            $synced++;
        }

        $this->info("Synced {$synced} customers.");
        return self::SUCCESS;
    }
}