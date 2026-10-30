<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Gateway status history: one row per CONFIRMED state change (ok / degraded / down), not one
     * per check. A few rows per incident, so the table stays tiny and never needs pruning. It backs
     * the dashboard's "recent incidents" list and the 30-day availability figure
     * (see App\Services\GatewayStatus::history).
     *
     * Durable table rather than cache on purpose: cache can be flushed or evicted, and an
     * availability number that silently resets itself is worse than none.
     *
     * The dashboard tolerates this table not existing yet, so the code can be deployed before
     * (or without) this migration. /start.sh runs `migrate --force` on every container boot.
     */
    public function up(): void
    {
        if (Schema::hasTable('gateway_status_events')) {
            return;
        }

        Schema::create('gateway_status_events', function (Blueprint $table) {
            $table->id();
            $table->string('state', 10);                       // ok | degraded | down
            $table->jsonb('reasons')->nullable();              // list of short strings from the gateway
            $table->unsignedInteger('devices_connected')->nullable();
            $table->boolean('public_path_ok')->nullable();     // null = public probe was off
            $table->timestamp('recorded_at')->index();         // when the new state was first seen
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "ALTER TABLE gateway_status_events ADD CONSTRAINT gateway_status_events_state_check "
                . "CHECK (state IN ('ok', 'degraded', 'down'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_status_events');
    }
};