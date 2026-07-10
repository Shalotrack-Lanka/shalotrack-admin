<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // If you already added the same device type twice while testing the
        // old ledger behavior, merge those rows before the unique constraint
        // goes on — otherwise this migration fails with a duplicate-key error.
        $duplicateTypeIds = DB::table('stocks')
            ->select('device_type_id')
            ->groupBy('device_type_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('device_type_id');

        foreach ($duplicateTypeIds as $deviceTypeId) {
            $rows = DB::table('stocks')->where('device_type_id', $deviceTypeId)->orderBy('id')->get();

            $stockIn = (int) $rows->sum('stock_in');
            $companyAvail = (int) $rows->sum('company_available_stock');
            $keepId = $rows->first()->id;

            DB::table('stocks')->where('id', $keepId)->update([
                'stock_in' => $stockIn,
                'company_available_stock' => $companyAvail,
                'total_available' => $stockIn + $companyAvail,
                'updated_at' => now(),
            ]);

            DB::table('stocks')
                ->where('device_type_id', $deviceTypeId)
                ->where('id', '!=', $keepId)
                ->delete();
        }

        Schema::table('stocks', function (Blueprint $table) {
            $table->unique('device_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['device_type_id']);
        });
    }
};