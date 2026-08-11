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
        Schema::table('stocks', function (Blueprint $table) {
            $table->string('device_category_type')->nullable()->after('device_type_id');
        });

        // Backfill from the linked device_types row so existing stock rows
        // aren't left blank. Postgres needs UPDATE ... FROM for a join-based
        // update — Laravel's query builder join()->update() doesn't compile
        // to that on the pgsql grammar.
        DB::statement("
            UPDATE stocks
            SET device_category_type = CONCAT(device_types.device_category, ' with ', device_types.model)
            FROM device_types
            WHERE device_types.id = stocks.device_type_id
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('device_category_type');
        });
    }
};
