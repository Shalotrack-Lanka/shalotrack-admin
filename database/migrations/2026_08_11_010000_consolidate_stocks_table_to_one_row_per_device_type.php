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
        // Company Available Stock is moving from one row per (device_type_id,
        // supplier_id) pair to one row per device_type_id. Sum the existing
        // per-supplier rows into a single row before dropping supplier_id, so
        // no stock quantity is lost in the process.
        $deviceTypeIds = DB::table('stocks')->distinct()->pluck('device_type_id');

        foreach ($deviceTypeIds as $deviceTypeId) {
            $rows = DB::table('stocks')->where('device_type_id', $deviceTypeId)->orderBy('id')->get();
            $keep = $rows->first();
            $total = $rows->sum('company_available_stock');

            DB::table('stocks')->where('id', $keep->id)->update(['company_available_stock' => $total]);
            DB::table('stocks')->where('device_type_id', $deviceTypeId)->where('id', '!=', $keep->id)->delete();
        }

        Schema::table('stocks', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropColumn(['supplier_id', 'stock_in', 'dealer_transferred', 'description', 'sort_order', 'total_available']);
            $table->unique('device_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['device_type_id']);
            $table->foreignId('supplier_id')->nullable()->after('device_type_id')->constrained('suppliers')->nullOnDelete();
            $table->integer('stock_in')->default(0)->after('supplier_id');
            $table->integer('dealer_transferred')->default(0)->after('company_available_stock');
            $table->integer('total_available')->default(0)->after('dealer_transferred');
            $table->text('description')->nullable()->after('total_available');
            $table->unsignedBigInteger('sort_order')->nullable()->after('description');
        });
    }
};
