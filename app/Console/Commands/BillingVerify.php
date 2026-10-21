<?php

namespace App\Console\Commands;

use App\Services\Billing\Billing;
use Illuminate\Console\Command;

/** Re-computes the billing hash chain. Exit code 1 when anything was altered, removed or numbered out of order. */
class BillingVerify extends Command
{
    protected $signature = 'billing:verify';

    protected $description = 'Verify every invoice, credit note and payment receipt against its tamper-evident chain';

    public function handle(Billing $billing): int
    {
        $problems = $billing->verify();

        if ($problems === []) {
            $this->info('Billing records are intact.');

            return self::SUCCESS;
        }

        $this->error(count($problems) . ' problem(s) found:');
        foreach ($problems as $p) {
            $this->line(' - ' . $p);
        }

        return self::FAILURE;
    }
}
