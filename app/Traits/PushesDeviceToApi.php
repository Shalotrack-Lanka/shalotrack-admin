<?php

namespace App\Traits;

use App\Models\SetupShalotrackDevice;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait PushesDeviceToApi
{
    /**
     * Pushes a device's current state to the API's setup-devices-sync
     * endpoint, so the mobile side knows about it for activation purposes.
     *
     * Deliberately non-fatal: the device is already saved locally in
     * Admin's own database, so a push failure must not block or undo the
     * Admin user's work. It IS reported back (return value) so the caller
     * can tell the user -- previously a failure was only written to the log
     * and the screen still said "success".
     *
     * @return bool true if the API accepted the device, false otherwise
     */
    private function pushDeviceToApi(SetupShalotrackDevice $device): bool
    {
        return \App\Services\DeviceSync::send($device);
    }

    /**
     * Tells the API to move the vehicle from the replaced device to the new one
     * (POST /api/internal/device-replacement-sync). The new device must already have been pushed
     * as Activated, or the API refuses with 409. The API call is idempotent, so retrying is safe.
     *
     * Non-fatal like pushDeviceToApi(): the replacement is already saved here. The result is
     * returned so the caller can warn the user instead of saying "success".
     */
    private function pushReplacementToApi(string $oldImei, string $newImei): bool
    {
        try {
            $response = Http::timeout(15)
                ->retry(3, 1000, throw: false)
                ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                ->acceptJson()
                ->post(config('services.shalotrack_api.base_url') . '/api/internal/device-replacement-sync', [
                    'oldImei' => $oldImei,
                    'newImei' => $newImei,
                ]);

            if (! $response->successful()) {
                Log::error('Device replacement push to API failed', [
                    'old_imei' => $oldImei,
                    'new_imei' => $newImei,
                    'status'   => $response->status(),
                    'body'     => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Device replacement push to API threw an exception', [
                'old_imei' => $oldImei,
                'new_imei' => $newImei,
                'error'    => $e->getMessage(),
            ]);

            return false;
        }
    }
}