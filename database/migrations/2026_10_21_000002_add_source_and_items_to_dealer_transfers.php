<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Distributor step 2: a transfer now records WHO SENT it and WHICH devices it contained.
 *
 *  - dealer_transfer_ledgers.from_dealer_id  NULL = the company (admin) sent it; otherwise the distributor
 *    that passed devices on to one of its dealers. Existing rows stay NULL (they were all admin transfers).
 *  - dealer_transfer_ledgers.created_by_admin_id  the login that recorded the transfer.
 *  - dealer_transfer_items  one row per device per transfer. Until now the contents of a transfer lived only in
 *    setup_shalotrack_devices.transfer_id, which is overwritten when a device moves on, so the original
 *    contents were lost. Wholesale invoices (next step) bill from these rows. Existing transfers are
 *    back-filled from the devices that currently point at them.
 *
 * Purely additive: nothing existing is altered or dropped. RESTRICT everywhere, no cascades on history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_transfer_ledgers', function (Blueprint $table) {
            $table->foreignId('from_dealer_id')->nullable()->after('dealer_id')
                ->constrained('dealers')->restrictOnDelete();
            $table->string('created_by_admin_id', 64)->nullable()->after('quantity');
            $table->index('from_dealer_id');
        });

        Schema::create('dealer_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_id')->constrained('dealer_transfer_ledgers')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('setup_shalotrack_devices', 'shdevice_id')->restrictOnDelete();
            $table->string('imei_number')->nullable();
            $table->string('sim_number')->nullable();
            $table->timestamps();

            $table->unique(['ledger_id', 'device_id']);
            $table->index('device_id');
        });

        // Back-fill: every device that currently points at a transfer belongs to that transfer's contents.
        $rows = DB::table('setup_shalotrack_devices')
            ->whereNotNull('transfer_id')
            ->get(['shdevice_id', 'transfer_id', 'imei_number', 'sim_number', 'allocated_at']);

        foreach ($rows->chunk(500) as $chunk) {
            DB::table('dealer_transfer_items')->insert($chunk->map(fn ($r) => [
                'ledger_id'   => $r->transfer_id,
                'device_id'   => $r->shdevice_id,
                'imei_number' => $r->imei_number,
                'sim_number'  => $r->sim_number,
                'created_at'  => $r->allocated_at ?? now(),
                'updated_at'  => $r->allocated_at ?? now(),
            ])->all());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dealer_transfer_items');

        Schema::table('dealer_transfer_ledgers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('from_dealer_id');
            $table->dropColumn('created_by_admin_id');
        });
    }
};