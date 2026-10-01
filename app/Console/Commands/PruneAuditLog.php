<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes audit entries older than N months (default 12). Uses the query builder on purpose: the
 * model refuses deletes so nothing in the web app can erase the trail. A floor of 3 months stops a
 * typo (--months=0) from wiping the table.
 */
class PruneAuditLog extends Command
{
    protected $signature = 'audit:prune {--months=12 : Keep this many months (minimum 3)}';

    protected $description = 'Delete audit-log entries older than the retention period';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        if ($months < 3) {
            $this->error('Refusing to keep fewer than 3 months of audit log.');

            return self::FAILURE;
        }

        $cutoff = now()->subMonthsNoOverflow($months);
        $deleted = DB::table('audit_logs')->where('occurred_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} audit entries older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}