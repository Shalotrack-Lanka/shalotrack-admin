<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // One row = one monthly commission statement that staff have actually PAID to a dealer.
    //
    // Statements themselves are never stored: they are computed from the append-only
    // dealer_commission_ledger, so there is nothing to drift. A payout row exists only once money
    // has gone out, and it "claims" the ledger rows it paid by setting dealer_commission_ledger.payout_id.
    // A ledger row with a payout_id can never appear on another statement, which is what stops the
    // same device being paid twice.
    //
    // unique(dealer_id, period): at most one paid statement per dealer per month, so a double click or
    // two staff members cannot both pay the same month.
    public function up(): void
    {
        Schema::create('dealer_commission_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained('dealers')->restrictOnDelete();

            // 'YYYY-MM' in Sri Lanka time. period_end is the exclusive UTC cut-off the rules were applied at.
            $table->string('period', 7);
            $table->timestamp('period_end');

            // Snapshot of the rule in force, so an old statement can always be explained.
            $table->unsignedSmallInteger('payable_after_days');

            $table->decimal('gross_amount', 12, 2);     // sum of payable sales
            $table->decimal('clawback_amount', 12, 2);  // negative or zero: reversals of sales already paid out
            $table->decimal('net_amount', 12, 2);       // what was paid
            $table->unsignedInteger('line_count');

            $table->string('reference', 100);           // bank transfer / cheque reference
            $table->date('paid_on');
            $table->string('paid_by_admin_id');
            $table->string('paid_by_name');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['dealer_id', 'period']);
        });

        Schema::table('dealer_commission_ledger', function (Blueprint $table) {
            $table->foreignId('payout_id')->nullable()->constrained('dealer_commission_payouts')->restrictOnDelete();
            $table->index('payout_id');
        });
    }

    public function down(): void
    {
        Schema::table('dealer_commission_ledger', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payout_id');
        });

        Schema::dropIfExists('dealer_commission_payouts');
    }
};