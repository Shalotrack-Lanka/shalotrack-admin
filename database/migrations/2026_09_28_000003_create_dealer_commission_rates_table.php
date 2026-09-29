<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Configurable commission rate per (dealer tier, device type) combo.
    // Both columns are nullable so a row can be a wildcard:
    //   dealer_status = 'distributor', device_type_id = NULL  -> that tier's
    //     rate across every device type not more specifically configured.
    //   dealer_status = NULL, device_type_id = 5               -> that
    //     device type's rate across every tier not more specifically
    //     configured.
    //   both NULL                                              -> the
    //     network-wide fallback rate.
    // DealerCommissionService::resolveRate() picks the most specific match.
    // No row at all yet is legitimate at first launch -- the service then
    // falls back to config('services.commission.default_rate') and callers
    // are told the rate was a fallback, not a configured one.
    public function up(): void
    {
        Schema::create('dealer_commission_rates', function (Blueprint $table) {
            $table->id();
            $table->string('dealer_status')->nullable();
            $table->foreignId('device_type_id')->nullable()->constrained('device_types')->nullOnDelete();
            $table->decimal('rate', 10, 2);
            $table->timestamps();

            // Prevents two rows silently competing for the same combo --
            // whichever was written last would otherwise win with no signal
            // that the other rate is now dead.
            $table->unique(['dealer_status', 'device_type_id'], 'dealer_commission_rates_combo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_commission_rates');
    }
};