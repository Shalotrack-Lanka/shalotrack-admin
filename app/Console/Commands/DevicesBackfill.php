<?php

namespace App\Console\Commands;

use App\Models\SetupShalotrackDevice;
use Illuminate\Console\Command;

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
        return \App\Services\DeviceSync::send($device);
    }
}
