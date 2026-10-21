<?php

namespace App\Console\Commands;

use App\Models\Admin;
use App\Services\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Distributors that existed before the DISTRIBUTOR role still sign in as DEALER. This lists them and, only
 * with --apply, switches their login to the distributor portal. It never runs by itself: moving a login
 * changes what that person can do (they lose the dealer portal), so a person decides when.
 *
 *   php artisan distributors:sync-logins            (dry run: only lists)
 *   php artisan distributors:sync-logins --apply    (changes the role, audited)
 */
class SyncDistributorLogins extends Command
{
    protected $signature = 'distributors:sync-logins {--apply : actually change the login role}';

    protected $description = 'List (or with --apply, convert) DEALER logins whose dealer record is of type distributor';

    public function handle(): int
    {
        $logins = Admin::query()
            ->where('role', 'DEALER')
            ->whereIn('dealer_id', DB::table('dealers')->where('dealer_status', 'distributor')->select('id'))
            ->orderBy('username')
            ->get();

        if ($logins->isEmpty()) {
            $this->info('Nothing to do: no DEALER login points at a distributor-type dealer.');

            return self::SUCCESS;
        }

        $this->table(['username', 'name', 'dealer_id', 'status'], $logins->map(fn ($l) => [$l->username, $l->full_name, $l->dealer_id, $l->status])->all());

        if (! $this->option('apply')) {
            $this->warn('Dry run. Re-run with --apply to switch these logins to DISTRIBUTOR.');

            return self::SUCCESS;
        }

        foreach ($logins as $login) {
            $login->role = 'DISTRIBUTOR';
            $login->save();

            Audit::record('account.role_changed', 'admin', $login->admin_id, $login->username,
                ['role' => ['from' => 'DEALER', 'to' => 'DISTRIBUTOR']],
                ['reason' => 'distributors:sync-logins', 'dealer_id' => $login->dealer_id]);
        }

        $this->info("Converted {$logins->count()} login(s) to DISTRIBUTOR.");

        return self::SUCCESS;
    }
}