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
        Schema::create('expired_devices', function (Blueprint $table) {
            $table->id('expired_device_id');

            // Mirrors activated_devices — a row is moved here wholesale when its
            // subscription lapses, and moved back (reactivated) from here too.
            $table->uuid('vehicle_id')->unique();
            $table->uuid('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('model')->nullable();
            $table->boolean('has_gps_device')->default(false);

            $table->string('imei_number');
            $table->string('sim_number')->nullable();
            $table->string('device_category')->nullable();
            $table->string('payment_status')->default('not-Paid');
            $table->string('subscription_model')->nullable();
            $table->timestamp('subscription_start_date')->nullable();
            $table->timestamp('subscription_end_date')->nullable();
            $table->string('bank_invoice')->nullable();
            $table->string('bank_slip')->nullable();

            $table->string('status')->default('Expired');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expired_devices');
    }
};
