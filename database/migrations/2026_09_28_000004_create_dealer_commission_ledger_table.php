<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Replaces the old "commission = live device COUNT() * 1000" formula.
    // That approach had no memory: reassigning, breaking, or unassigning a
    // device silently changed a dealer's PAST earnings, because the number
    // was recalculated from current device state on every page load rather
    // than recorded at the moment it was actually earned.
    //
    // This table is an append-only event log. A device being sold writes
    // one 'earned' row with the rate locked in at that moment (so a later
    // rate-table change never rewrites history). A device later coming
    // back (broken, unassigned) writes a separate 'reversed' row rather
    // than deleting or editing the earned row -- the earned row itself
    // gets its own reversed_at stamped, so it's never reversed twice, but
    // the original row stays exactly as it was for audit purposes.
    //
    // Current commission for a dealer = SUM(amount) across all their rows.
    // Historical commission for any past date = SUM(amount) where
    // created_at <= that date.
    public function up(): void
    {
        Schema::create('dealer_commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dealer_id')->constrained('dealers')->cascadeOnDelete();
            $table->foreignId('shdevice_id')->constrained('setup_shalotrack_devices', 'shdevice_id')->cascadeOnDelete();

            // Snapshots, not live foreign lookups -- both are allowed to
            // change in the future (dealer moves tiers, device type gets
            // renamed) without rewriting what was true when this was earned.
            $table->foreignId('device_type_id')->nullable()->constrained('device_types')->nullOnDelete();
            $table->string('dealer_status_at_time')->nullable();

            $table->decimal('rate_applied', 10, 2);
            // Positive for an 'earned' row, negative for a 'reversed' row --
            // this is what SUM() adds up, so no CASE/WHEN needed to total it.
            $table->decimal('amount', 10, 2);
            $table->enum('entry_type', ['earned', 'reversed']);
            $table->string('reason')->nullable();

            // Set only on an 'earned' row once it has been reversed. Lets
            // recordReversed() find "still-active" earned rows via
            // whereNull('reversed_at') and guarantees a row is never
            // reversed twice for the same device.
            $table->timestamp('reversed_at')->nullable();

            $table->timestamps();

            $table->index(['dealer_id', 'entry_type']);
            $table->index(['shdevice_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_commission_ledger');
    }
};