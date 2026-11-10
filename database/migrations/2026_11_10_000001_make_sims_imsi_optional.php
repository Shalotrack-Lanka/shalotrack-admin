<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The mobile operator does not give us IMSIs, so a SIM received on a supplier delivery has none.
// sims.imsi was NOT NULL; it becomes optional (the UNIQUE index stays: PostgreSQL allows many NULLs,
// and two SIMs can still never share a real IMSI). Existing rows are untouched.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sims') || ! Schema::hasColumn('sims', 'imsi')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sims ALTER COLUMN imsi DROP NOT NULL');
        } else {
            Schema::table('sims', function (Blueprint $table) {
                $table->string('imsi', 15)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sims') || ! Schema::hasColumn('sims', 'imsi')) {
            return;
        }
        // Only safe while no SIM is without an IMSI.
        if (DB::table('sims')->whereNull('imsi')->exists()) {
            throw new RuntimeException('Cannot make sims.imsi required again: some SIMs have no IMSI.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sims ALTER COLUMN imsi SET NOT NULL');
        }
    }
};
