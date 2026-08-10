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
        Schema::create('activated_devices', function (Blueprint $table) {
            $table->id('activated_device_id');

            // Mirrors the columns shown in the Inactive Devices table (sourced from vehicle_ad).
            $table->uuid('vehicle_id')->unique();
            $table->uuid('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('model')->nullable();
            $table->boolean('has_gps_device')->default(false);

            // Activation form fields.
            $table->string('imei_number');
            $table->string('sim_number')->nullable();
            $table->string('device_category')->nullable();
            $table->string('payment_status')->default('not-Paid');
            $table->string('subscription_model')->nullable();
            $table->string('bank_invoice')->nullable();
            $table->string('bank_slip')->nullable();

            $table->string('status')->default('Not Activated');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activated_devices');
    }
};
