<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Customer-ad', function (Blueprint $table) {
            $table->string('cus_status')->default('not_verified')->after('source_account_status');
        });

        Schema::table('Customer-ad', function (Blueprint $table) {
            $table->dropColumn([
                'imei_number',
                'sim_number',
                'payment_status',
                'device_type',
                'subscription_period',
                'subscription_start_date',
                'subscription_end_date',
                'bank_invoice_path',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('Customer-ad', function (Blueprint $table) {
            $table->dropColumn('cus_status');

            $table->string('imei_number')->nullable();
            $table->string('sim_number')->nullable();
            $table->string('payment_status')->default('not_paid');
            $table->string('device_type')->nullable();
            $table->string('subscription_period')->nullable();
            $table->date('subscription_start_date')->nullable();
            $table->date('subscription_end_date')->nullable();
            $table->text('bank_invoice_path')->nullable();
        });
    }
};
