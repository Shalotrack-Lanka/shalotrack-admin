<?php

namespace App\Console\Commands;

use App\Services\RenewalPackagesApi;
use Illuminate\Console\Command;

/**
 * Sends the renewal price list to the mobile API. Run it once after deploying the API change that adds
 * the price list, and any time a save on the Renewal Packages page reports that the push failed.
 * Safe to run more than once.
 */
class PushRenewalPackages extends Command
{
    protected $signature = 'renewal-packages:push';

    protected $description = 'Sends the renewal price list (prices and warranty, never margins) to the mobile API.';

    public function handle(RenewalPackagesApi $api): int
    {
        $result = $api->push();
        $result['ok'] ? $this->info($result['message']) : $this->error($result['message'] . ' See storage/logs/laravel.log.');

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}