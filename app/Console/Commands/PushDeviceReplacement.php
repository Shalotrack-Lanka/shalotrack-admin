<?php

namespace App\Console\Commands;

use App\Models\SetupShalotrackDevice;
use App\Traits\PushesDeviceToApi;
use Illuminate\Console\Command;

/**
 * Retry path for a device replacement whose API update failed (the replacement itself is already
 * saved in the admin; only the mobile API side is missing). Safe to run more than once: the API
 * upserts devices by IMEI and the replacement call is idempotent.
 */
class PushDeviceReplacement extends Command
{
    use PushesDeviceToApi;

    protected $signature = 'devices:push-replacement {oldImei : IMEI of the replaced (faulty) device} {newImei : IMEI of the device that took over}';

    protected $description = 'Re-sends a device replacement to the mobile API so the vehicle moves from the old device to the new one.';

    public function handle(): int
    {
        $oldImei = trim($this->argument('oldImei'));
        $newImei = trim($this->argument('newImei'));

        $new = SetupShalotrackDevice::where('imei_number', $newImei)->first();
        if (! $new) {
            $this->error("New device {$newImei} is not in the admin device registry.");
            return self::FAILURE;
        }

        if (! $this->pushDeviceToApi($new)) {
            $this->error("Could not push device {$newImei} to the API. See storage/logs/laravel.log.");
            return self::FAILURE;
        }

        if (! $this->pushReplacementToApi($oldImei, $newImei)) {
            $this->error("The API refused or could not process the replacement {$oldImei} -> {$newImei}. See storage/logs/laravel.log.");
            return self::FAILURE;
        }

        $this->info("Replacement {$oldImei} -> {$newImei} sent to the API.");

        return self::SUCCESS;
    }
}