<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Warranty = original activation date + the best warranty any package on this device has earned
     * (Renewal Plans guideline, section 3). Both values are permanent: a renewal never resets or
     * moves them, and warranty_months only ever goes up.
     *
     * Existing rows: the original activation is taken as the day the device was bound (created_at),
     * and warranty_months from the device's current package plus any package it lapsed on.
     */
    public function up(): void
    {
        Schema::table('activated_devices', function (Blueprint $table) {
            $table->timestamp('original_activated_at')->nullable()->after('status');
            $table->unsignedSmallInteger('warranty_months')->default(0)->after('original_activated_at');
        });

        DB::table('activated_devices')->whereNull('original_activated_at')->update([
            'original_activated_at' => DB::raw('created_at'),
        ]);

        $months = DB::table('renewal_packages')->pluck('warranty_months', 'model')->all();

        DB::table('activated_devices')->orderBy('activated_device_id')->each(function ($row) use ($months) {
            $models = [$row->subscription_model];
            if (! empty($row->imei_number)) {
                $models = array_merge($models, DB::table('expired_devices')
                    ->where('imei_number', $row->imei_number)->pluck('subscription_model')->all());
            }
            $best = 0;
            foreach ($models as $m) {
                $best = max($best, (int) ($months[$m] ?? 0));
            }
            if ($best > 0) {
                DB::table('activated_devices')->where('activated_device_id', $row->activated_device_id)
                    ->update(['warranty_months' => $best]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('activated_devices', function (Blueprint $table) {
            $table->dropColumn(['original_activated_at', 'warranty_months']);
        });
    }
};