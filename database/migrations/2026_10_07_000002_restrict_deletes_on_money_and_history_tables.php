<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money and history rows must never vanish as a side effect of deleting a parent row.
 *
 * These foreign keys were created with ON DELETE CASCADE, so removing a dealer, a stock row, a
 * supplier or a device (from a SQL console, tinker, or a future careless code path) would silently
 * wipe commission, transfer and invoice history with it. They become RESTRICT instead: the parent
 * delete is refused until someone deliberately deals with the children.
 *
 * Nothing in the application deletes these parents today, so behaviour in the portal is unchanged.
 * (The newer package_payments / commission_entries / payout tables already use RESTRICT.)
 */
return new class extends Migration
{
    /** table => [column, referenced table, referenced column] */
    private const KEYS = [
        ['dealer_transfer_ledgers', 'dealer_id',   'dealers',                'id'],
        ['stock_transfer_ledgers',  'stock_id',    'stocks',                 'id'],
        ['dealer_commission_ledger', 'dealer_id',  'dealers',                'id'],
        ['dealer_commission_ledger', 'shdevice_id', 'setup_shalotrack_devices', 'shdevice_id'],
        ['dealer_customer_ads',     'dealer_id',   'dealers',                'id'],
        ['supplier_invoices',       'supplier_id', 'suppliers',              'id'],
    ];

    public function up(): void
    {
        $this->rebuild('restrict');
    }

    public function down(): void
    {
        $this->rebuild('cascade');
    }

    private function rebuild(string $onDelete): void
    {
        foreach (self::KEYS as [$table, $column, $refTable, $refColumn]) {
            if (! Schema::hasTable($table) || ! Schema::hasTable($refTable)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($column) {
                $t->dropForeign([$column]);
            });

            Schema::table($table, function (Blueprint $t) use ($column, $refTable, $refColumn, $onDelete) {
                $fk = $t->foreign($column)->references($refColumn)->on($refTable);
                $onDelete === 'restrict' ? $fk->restrictOnDelete() : $fk->cascadeOnDelete();
            });
        }
    }
};