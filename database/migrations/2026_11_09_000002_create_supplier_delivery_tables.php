<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Goods receipt for supplied devices and SIMs: which supplier delivered which IMEIs / ICCIDs, and when.
     *
     *   supplier_deliveries       one row per delivery we receive (supplier, kind, device type, delivery note)
     *   supplier_delivery_units   one row per scanned IMEI (DEVICE) or ICCID (SIM)
     *
     * ADDITIVE ONLY: no existing table is changed, so nothing already working can break and rolling back is
     * just dropping these two tables. The identifier is UNIQUE across all suppliers, so one unit can never be
     * received twice (or claimed by two suppliers). Units are kept as history: nothing cascades or deletes.
     * registered_at / registered_ref are filled by the Setup Device / SIM registration step (a later stage)
     * and say which device or SIM row the unit became.
     */
    public function up(): void
    {
        Schema::create('supplier_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('kind', 6);                         // DEVICE | SIM
            $table->foreignId('device_type_id')->nullable()->constrained('device_types')->restrictOnDelete();
            $table->string('reference', 100)->nullable();      // the supplier's delivery note / invoice number
            $table->date('received_on');
            $table->string('note', 500)->nullable();
            $table->string('received_by', 64)->nullable();     // Admins.admin_id of who scanned it in
            $table->timestamps();

            $table->index(['supplier_id', 'received_on']);
        });

        Schema::create('supplier_delivery_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_delivery_id')->constrained('supplier_deliveries')->restrictOnDelete();
            $table->string('kind', 6);                         // same as the delivery's kind (kept for fast lookup)
            $table->string('identifier', 20)->unique();        // IMEI (15 digits) or ICCID (19-20 digits)
            $table->timestamp('registered_at')->nullable();
            $table->string('registered_ref', 64)->nullable();
            $table->timestamps();

            $table->index('supplier_delivery_id');
        });

        // The application validates the same rules; these keep a stray SQL edit from breaking them.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE supplier_deliveries ADD CONSTRAINT supplier_deliveries_kind_chk CHECK (
                (kind = 'DEVICE' AND device_type_id IS NOT NULL) OR (kind = 'SIM' AND device_type_id IS NULL)
            )");
            DB::statement("ALTER TABLE supplier_delivery_units ADD CONSTRAINT supplier_delivery_units_id_chk CHECK (
                (kind = 'DEVICE' AND identifier ~ '^[0-9]{15}$') OR (kind = 'SIM' AND identifier ~ '^[0-9]{19,20}$')
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_delivery_units');
        Schema::dropIfExists('supplier_deliveries');
    }
};
