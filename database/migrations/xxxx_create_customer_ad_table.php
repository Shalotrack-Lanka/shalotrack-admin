<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Customer-ad', function (Blueprint $table) {
            $table->uuid('customer_id')->primary();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone_number')->nullable();
            $table->string('nic_number')->nullable();
            $table->text('address')->nullable();
            $table->text('profile_image')->nullable();
            $table->integer('vehicle_count')->default(0);
            $table->integer('source_account_status')->nullable();

            $table->string('imei_number')->nullable();
            $table->string('sim_number')->nullable();
            $table->string('payment_status')->default('not_paid');
            $table->string('device_type')->nullable();
            $table->string('subscription_period')->nullable();
            $table->date('subscription_start_date')->nullable();
            $table->date('subscription_end_date')->nullable();
            $table->text('bank_invoice_path')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Customer-ad');
    }
};