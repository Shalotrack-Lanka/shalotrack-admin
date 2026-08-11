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
    Schema::table('dealer_customer_ads', function (Blueprint $table) {
        $table->json('imei_numbers')->nullable()->after('no_of_devices');
    });
}

public function down(): void
{
    Schema::table('dealer_customer_ads', function (Blueprint $table) {
        $table->dropColumn('imei_numbers');
    });
}
};
