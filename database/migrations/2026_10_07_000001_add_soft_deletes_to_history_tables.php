<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History and lead tables stop being hard-deleted.
 *
 * Deleting a stock ledger row, a dealer transfer ledger row or a dealer's customer lead used to
 * erase the row for good. These tables now carry deleted_at, so a "delete" in the UI hides the row
 * but keeps it recoverable (and the audit log records who did it and a snapshot of the row).
 */
return new class extends Migration
{
    private const TABLES = [
        'stock_transfer_ledgers',
        'dealer_transfer_ledgers',
        'dealer_customer_ads',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};