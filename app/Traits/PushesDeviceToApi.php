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
        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                ->acceptJson()
                ->post(config('services.shalotrack_api.base_url') . '/api/internal/setup-devices-sync', [
                    'id'             => $device->shdevice_id,
                    'deviceCategory' => $device->device_category,
                    'imeiNumber'     => $device->imei_number,
                    'simNumber'      => $device->sim_number,
                    'status'         => $device->status,
                    'cancelReason'   => $device->cancel_reason,
                    'canceledDate'   => $device->canceled_date,
                    'dealerId'       => $device->dealer_id,
                    'deviceTypeId'   => $device->device_type_id,
                    'createdAt'      => $device->created_at,
                    'updatedAt'      => $device->updated_at,
                ]);

            if (!$response->successful()) {
                Log::error('Device push to API failed', [
                    'imei'   => $device->imei_number,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Device push to API threw an exception', [
                'imei'  => $device->imei_number,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
