<?php

namespace App\Console\Commands;

use App\Models\ActivatedDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * subscriptions:sync-to-api
 *
 * Pushes current subscription status for every activated device to the
 * C# API, so the API (which serves live tracking + tracking history to
 * the Android app) can gate those features without needing direct DB
 * access to this app's database -- the two are genuinely separate
 * Supabase projects (confirmed 2026-09-29), so this is the only way the
 * API can know "does this device's subscription need renewal".
 *
 * Mirrors the exact pattern SyncCustomersFromApi/SyncVehiclesFromApi
 * already use, just in the opposite direction (this app calls INTO the
 * API, the same direction app/Http/Controllers/Admin/Complaints/
 * AdminComplaintController.php and the existing setup-devices-sync
 * endpoint already use) -- same shared secret (X-Admin-Sync-Key /
 * SHALOTRACK_SYNC_KEY), same Http client shape, same timeout/retry
 * conventions.
 *
 * Full sync every run, not incremental -- matches how customers:sync
 * already behaves, and the payload size here is one row per activated
 * device, not a high-volume table.
 *
 * isActive is derived from payment_status alone (not a separate flag)
 * because CustomerDeviceManagementController::subscriptionFields() and
 * ExpireDeviceSubscriptions already guarantee payment_status = 'Paid'
 * if and only if subscription_start_date/end_date are set and current --
 * see those two files for why that invariant holds.
 */
class SyncSubscriptionStatusToApi extends Command
{
    protected $signature = 'subscriptions:sync-to-api';

    protected $description = 'Pushes activated_devices subscription status (by IMEI) to the C# API so it can gate live tracking / tracking history for devices requiring renewal.';

    public function handle(): int
    {
        $devices = ActivatedDevice::whereNotNull('imei_number')->get([
            'imei_number',
            'payment_status',
            'subscription_end_date',
        ]);

        if ($devices->isEmpty()) {
            $this->info('No activated devices to sync.');
            return self::SUCCESS;
        }

        $payload = $devices->map(function (ActivatedDevice $device) {
            return [
                'imei'      => $device->imei_number,
                'isActive'  => $device->payment_status === 'Paid',
                'expiresAt' => optional($device->subscription_end_date)->toIso8601String(),
            ];
        })->values()->all();

        $response = Http::timeout(15)
            ->retry(3, 2000, throw: false)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->acceptJson()
            ->post(config('services.shalotrack_api.base_url') . '/api/internal/subscription-status-sync', [
                'devices' => $payload,
            ]);

        if (! $response->successful()) {
            Log::error('Subscription status sync failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            $this->error('Subscription status sync failed: HTTP ' . $response->status());
            $this->error('Response body: ' . $response->body());
            return self::FAILURE;
        }

        $this->info('Subscription status synced for ' . count($payload) . ' device(s).');

        return self::SUCCESS;
    }
}