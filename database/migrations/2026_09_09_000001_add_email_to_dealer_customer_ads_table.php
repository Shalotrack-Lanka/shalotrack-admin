<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds email to dealer_customer_ads.
 *
 * Email + phone together are the two identifiers used to soft-match a dealer
 * lead against a real CustomerAd record (synced from the C# API). The API
 * stores phone in E.164 (+94XXXXXXXXX) while the dealer form stores it as a
 * local 10-digit number — matching on EITHER field catches the real account
 * with certainty and avoids false positives from phone-format drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_customer_ads', function (Blueprint $table) {
            $table->string('email')->nullable()->after('contact');
        });
    }

    public function down(): void
    {
        Schema::table('dealer_customer_ads', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};