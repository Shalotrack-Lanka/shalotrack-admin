<?php

namespace App\Console\Commands;

use App\Services\DeviceSync;
use Illuminate\Console\Command;

class SyncDeviceOutbox extends Command
{
    protected $signature = 'devices:sync-outbox {--scan : first compare every device with what the API last accepted and queue the differences}';

    protected $description = 'Sends devices whose data changed (or is not yet confirmed by the API) to the API, retrying failures with a growing delay.';

    public function handle(): int
    {
        if (! DeviceSync::enabled()) {
            $this->warn('device_sync_state table is missing; run php artisan migrate.');

            return self::SUCCESS;
        }

        if ($this->option('scan')) {
            $this->info('Queued by drift scan: ' . DeviceSync::scanDrift());
        }

        $r = DeviceSync::processDue();
        $this->info("Sent {$r['sent']}, failed {$r['failed']}, still waiting {$r['remaining']}.");

        return self::SUCCESS;
    }
}
