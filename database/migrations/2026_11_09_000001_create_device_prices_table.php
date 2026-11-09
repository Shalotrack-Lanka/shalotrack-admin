<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The DEVICE price chain, one row per device type (the renewal_packages table does the same job for
     * subscriptions). What is stored is only what a person decides:
     *
     *   landing cost + company margin      = distributor cost
     *   distributor cost + distributor margin = retailer price
     *   retailer price + retailer margin   = customer price
     *
     * Every other number (the three prices, the percentages, the tax) is calculated from these four by
     * App\Services\DevicePriceChain and is never stored, so a stored price can never disagree with its
     * parts. All amounts are EXCLUDING tax: tax is applied from the billing settings when shown or invoiced,
     * so a change in the tax rate never means editing a price. Money is decimal(14,2) LKR.
     *
     * No row for a device type = "no approved price yet" (it is never guessed).
     */
    public function up(): void
    {
        Schema::create('device_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_type_id')->unique()->constrained('device_types')->restrictOnDelete();
            $table->decimal('landing_cost', 14, 2);
            $table->decimal('company_margin', 14, 2);
            $table->decimal('distributor_margin', 14, 2);
            $table->decimal('retailer_margin', 14, 2);
            $table->date('effective_from');
            $table->string('updated_by', 64)->nullable();
            $table->timestamps();
        });

        // A price chain with a negative part or a zero landing cost is a data-entry mistake, not a price.
        // The application validates the same rules; this keeps a stray SQL edit from breaking them.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE device_prices ADD CONSTRAINT device_prices_amounts_chk CHECK (
                landing_cost > 0 AND company_margin >= 0 AND distributor_margin >= 0 AND retailer_margin >= 0
            )');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('device_prices');
    }
};
