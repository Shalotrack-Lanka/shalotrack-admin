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
        Schema::table('activated_devices', function (Blueprint $table) {
            $table->timestamp('subscription_start_date')->nullable()->after('subscription_model');
            $table->timestamp('subscription_end_date')->nullable()->after('subscription_start_date');
            $table->unique('bank_invoice');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activated_devices', function (Blueprint $table) {
            $table->dropUnique(['bank_invoice']);
            $table->dropColumn(['subscription_start_date', 'subscription_end_date']);
        });
    }
};
