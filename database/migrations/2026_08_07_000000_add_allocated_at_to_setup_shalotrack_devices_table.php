<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            // Deliberately separate from updated_at — updated_at will keep
            // moving every time status changes later (e.g. Not Activated ->
            // Activated), which would silently overwrite an "allocation
            // date" if we relied on it instead. Nullable because existing
            // rows allocated before this migration have no real value to
            // backfill honestly.
            $table->timestamp('allocated_at')->nullable()->after('dealer_id');
        });
    }

    public function down(): void
    {
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            $table->dropColumn('allocated_at');
        });
    }
};