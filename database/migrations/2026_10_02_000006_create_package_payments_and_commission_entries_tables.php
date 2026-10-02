<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Commission proof, in two append-only tables.
     *
     * package_payments: one row each time a package is recorded as paid for a device (first payment,
     * renewal or reactivation). It freezes everything needed to explain the commission later: the
     * package, the price and the three margins as they were THEN, who the retailer (dealer) and the
     * distributor were THEN, the subscription period, the bank invoice and who recorded it. Editing the
     * price master afterwards never changes a past row. A payment is never deleted; if it is undone it is
     * marked reversed.
     *
     * commission_entries: the money lines. earned (+) when a payment is recorded, clawback (-) when it is
     * reversed. period_month is the Sri Lanka calendar month the line belongs to. payout_id is set when a
     * monthly statement that included the line is paid, which makes it impossible to pay twice.
     */
    public function up(): void
    {
        Schema::create('package_payments', function (Blueprint $table) {
            $table->id();
            // Stops the same payment being recorded twice. Cleared when the payment is reversed so a
            // corrected payment with identical details can be recorded again.
            $table->string('event_key', 64)->nullable()->unique();

            $table->unsignedBigInteger('activated_device_id')->nullable();
            $table->string('imei_number', 32);
            $table->string('vehicle_id')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('customer_id')->nullable();
            $table->string('customer_name')->nullable();

            $table->string('source', 20);                 // activation | admin_edit | reactivation
            $table->string('package_model', 20);
            $table->string('package_label', 40)->nullable();
            $table->unsignedSmallInteger('months')->nullable();
            $table->decimal('customer_price', 10, 2)->nullable();
            $table->decimal('company_margin', 10, 2)->nullable();
            $table->decimal('distributor_margin', 10, 2)->nullable();
            $table->decimal('retailer_margin', 10, 2)->nullable();

            $table->foreignId('retailer_dealer_id')->nullable()->constrained('dealers')->restrictOnDelete();
            $table->foreignId('distributor_dealer_id')->nullable()->constrained('dealers')->restrictOnDelete();

            $table->timestamp('subscription_start')->nullable();
            $table->timestamp('subscription_end')->nullable();
            $table->timestamp('previous_expiry')->nullable();
            $table->string('bank_invoice')->nullable();

            $table->string('recorded_by', 64)->nullable();
            $table->string('recorded_by_name', 100)->nullable();

            $table->string('status', 10)->default('valid'); // valid | reversed
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversed_by', 64)->nullable();
            $table->string('reversal_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['retailer_dealer_id', 'created_at']);
            $table->index(['imei_number', 'status']);
        });

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('package_payments')->restrictOnDelete();
            $table->foreignId('dealer_id')->constrained('dealers')->restrictOnDelete();
            $table->string('role', 12);                   // retailer | distributor
            $table->string('entry_type', 10);             // earned | clawback
            $table->decimal('amount', 12, 2);             // + earned, - clawback
            $table->string('period_month', 7);            // YYYY-MM, Sri Lanka time
            $table->timestamp('occurred_at');
            $table->foreignId('payout_id')->nullable()->constrained('dealer_commission_payouts')->restrictOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            // One earned and at most one clawback per payment, per person and role: a double click or a
            // retry can never create a second line.
            $table->unique(['payment_id', 'dealer_id', 'role', 'entry_type'], 'commission_entries_once');
            $table->index(['dealer_id', 'payout_id', 'occurred_at']);
            $table->index(['dealer_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('package_payments');
    }
};