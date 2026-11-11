<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per device: what still has to be sent to the API, how many times it failed, and a
 * fingerprint of what the API last accepted. Replaces "a failed push is only a yellow warning
 * plus a manual devices:backfill". Additive only; nothing reads it until devices:sync-outbox runs.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('device_sync_state', function (Blueprint $t) {
            $t->unsignedBigInteger('shdevice_id')->primary();
            $t->string('imei_number', 32)->index();
            $t->boolean('pending')->default(true);
            $t->timestamp('pending_since', 6)->nullable();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->string('last_error', 300)->nullable();
            $t->string('last_synced_hash', 40)->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();

            $t->index(['pending', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sync_state');
    }
};
