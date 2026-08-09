<?php

namespace App\Console\Commands;

use App\Models\SetupShalotrackDevice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DevicesBackfill extends Command
{
    protected $signature = 'devices:backfill';

    protected $description = 'Pushes every existing SetupShalotrackDevice to the API. Safe to run more than once — the API endpoint upserts by IMEI, so re-running just re-confirms existing devices instead of creating duplicates.';

    public function handle(): int
    {
        $total = SetupShalotrackDevice::count();

        if ($total === 0) {
            $this->info('No devices found — nothing to backfill.');
            return self::SUCCESS;
        }

        $this->info("Pushing {$total} device(s) to the API...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $succeeded = 0;
        $failed = 0;

        SetupShalotrackDevice::chunk(50, function ($devices) use (&$succeeded, &$failed, $bar) {
            foreach ($devices as $device) {
                $ok = $this->pushDeviceToApi($device);
                $ok ? $succeeded++ : $failed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info("Done. Succeeded: {$succeeded}, Failed: {$failed}.");

        if ($failed > 0) {
            $this->warn('Check storage/logs/laravel.log for details on the failed pushes.');
        }

        return self::SUCCESS;
    }

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
                Log::error('Backfill: device push to API failed', [
                    'imei'   => $device->imei_number,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Backfill: device push to API threw an exception', [
                'imei'  => $device->imei_number,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}