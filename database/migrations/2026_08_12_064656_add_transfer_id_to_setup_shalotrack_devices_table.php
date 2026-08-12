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
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            // nullOnDelete: deleting a ledger row must not touch dealer_id
            // (per business decision — a deleted history record leaves the
            // device's dealer assignment as-is), only unlink the reference.
            $table->foreignId('transfer_id')->nullable()->after('dealer_id')
                ->constrained('dealer_transfer_ledgers')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_id');
        });
    }
};
