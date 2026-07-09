<?php

namespace App\Console\Commands;

use App\Models\CustomerAd;
use Illuminate\Console\Command;

class ExpireCustomerSubscriptions extends Command
{
    protected $signature = 'customers:expire-subscriptions';
    protected $description = 'Move customers whose subscription end date has passed back to Not Paid / Inactive';

    public function handle(): int
    {
        $count = CustomerAd::expireOverdueSubscriptions();
        $this->info("Expired {$count} customer subscription(s).");
        return self::SUCCESS;
    }
}