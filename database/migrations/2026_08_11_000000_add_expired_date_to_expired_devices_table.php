<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('expired_devices', function (Blueprint $table) {
            // The activated_devices.subscription_end_date at the moment the
            // device lapsed and was moved here, so the original expiry date
            // stays visible even after subscription_end_date is cleared.
            $table->timestamp('expired_date')->nullable()->after('subscription_end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('expired_devices', function (Blueprint $table) {
            $table->dropColumn('expired_date');
        });
    }
};
