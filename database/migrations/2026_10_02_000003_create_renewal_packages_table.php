<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The pricing master from the Shalotrack Renewal Plans & IT Implementation Guideline v1.0
     * (30 Sep 2026). Prices, channel margins and warranty length live here, not in code. Prices are
     * nullable on purpose: 3 Months has no approved price yet, and an unpriced package is never
     * offered to customers. `code` is the API's RenewalDuration name; `model` is the string stored in
     * activated_devices.subscription_model.
     */
    public function up(): void
    {
        Schema::create('renewal_packages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('model', 20)->unique();
            $table->string('label', 40);
            $table->string('positioning', 60)->nullable();
            $table->unsignedSmallInteger('months');
            $table->decimal('customer_price', 10, 2)->nullable();
            $table->decimal('company_margin', 10, 2)->nullable();
            $table->decimal('distributor_margin', 10, 2)->nullable();
            $table->decimal('retailer_margin', 10, 2)->nullable();
            // Months of warranty counted from the ORIGINAL activation date. 0 = no added warranty.
            $table->unsignedSmallInteger('warranty_months')->default(0);
            $table->boolean('is_active')->default(true);
            $table->date('effective_from')->nullable();
            $table->string('updated_by', 64)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $rows = [
            ['THREE_MONTHS', '3 Months', '3 Months', 'Trial plan', 3, null, null, null, null, 0, 1],
            ['SIX_MONTHS', '6 Months', '6 Months', 'Short-Term Plan', 6, 1999, 1499, 200, 300, 0, 2],
            ['ONE_YEAR', '1 Year', '1 Year', 'Standard Plan', 12, 2999, 2199, 300, 500, 12, 3],
            ['TWO_YEARS', '2 Year', '2 Years', 'Value Plan', 24, 4999, 3749, 450, 800, 18, 4],
            ['THREE_YEARS', '3 Year', '3 Years', 'Best Value Plan', 36, 6999, 5299, 700, 1000, 24, 5],
            ['SIX_YEARS', '6 Year', '6 Years', 'Long-Term Savings Plan', 72, 10999, 8499, 1000, 1500, 36, 6],
        ];
        foreach ($rows as [$code, $model, $label, $pos, $months, $price, $co, $di, $re, $war, $sort]) {
            DB::table('renewal_packages')->insert([
                'code' => $code, 'model' => $model, 'label' => $label, 'positioning' => $pos, 'months' => $months,
                'customer_price' => $price, 'company_margin' => $co, 'distributor_margin' => $di, 'retailer_margin' => $re,
                'warranty_months' => $war, 'is_active' => true, 'effective_from' => $price === null ? null : '2026-09-30',
                'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('renewal_packages');
    }
};