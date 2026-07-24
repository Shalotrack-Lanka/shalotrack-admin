<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_ad', function (Blueprint $table) {
            $table->uuid('vehicle_id')->primary();
            $table->uuid('customer_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('chassis_number')->nullable();
            $table->string('engine_number')->nullable();
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->integer('year')->nullable();
            $table->string('color')->nullable();
            $table->string('vehicle_type')->nullable();
            $table->string('fuel_type')->nullable();
            $table->boolean('has_gps_device')->default(false);
            $table->string('imei')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_ad');
    }
};