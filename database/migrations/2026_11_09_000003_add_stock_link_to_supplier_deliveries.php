<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties a received device delivery to the stock-in it became, so the stock count can never drift from the
     * scanned units: a delivery can be added to stock exactly once (stocked_in_at / stock_ledger_id are set in
     * the same transaction as the stock increase). Only touches supplier_deliveries, a table created one
     * migration earlier; no older table is changed.
     */
    public function up(): void
    {
        Schema::table('supplier_deliveries', function (Blueprint $table) {
            $table->timestamp('stocked_in_at')->nullable();
            $table->foreignId('stock_ledger_id')->nullable()->constrained('stock_transfer_ledgers')->restrictOnDelete();
            $table->index(['supplier_id', 'kind', 'stocked_in_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_deliveries ADD CONSTRAINT supplier_deliveries_stocked_chk CHECK (
                (stocked_in_at IS NULL) = (stock_ledger_id IS NULL)
            )');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_deliveries DROP CONSTRAINT IF EXISTS supplier_deliveries_stocked_chk');
        }

        Schema::table('supplier_deliveries', function (Blueprint $table) {
            $table->dropIndex(['supplier_id', 'kind', 'stocked_in_at']);
            $table->dropConstrainedForeignId('stock_ledger_id');
            $table->dropColumn('stocked_in_at');
        });
    }
};
