<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stock_transfer_ledgers', function (Blueprint $table) {
            $table->string('device_category_type')->nullable()->after('stock_id');
        });

        // Backfill from the linked stocks row so existing ledger rows aren't
        // left blank. Postgres needs UPDATE ... FROM for a join-based update.
        DB::statement('
            UPDATE stock_transfer_ledgers
            SET device_category_type = stocks.device_category_type
            FROM stocks
            WHERE stocks.id = stock_transfer_ledgers.stock_id
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_transfer_ledgers', function (Blueprint $table) {
            $table->dropColumn('device_category_type');
        });
    }
};
