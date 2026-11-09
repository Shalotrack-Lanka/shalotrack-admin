<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ties purchasing together (the "three-way match": purchase order -> scanned delivery -> stock-in):
     *
     *   supplier_invoices.status       ORDERED or CANCELLED. A purchase order is an ORDER: it never changes stock.
     *                                  It can be edited while ORDERED and nothing has been received against it,
     *                                  and cancelled (with a reason) instead of deleted.
     *   supplier_deliveries.supplier_invoice_id
     *                                  the purchase order a delivery was received against (optional). Once a
     *                                  delivery points at an order, that order is locked.
     *   supplier_delivery_units.sim_number
     *                                  the 10-digit SIM number scanned with each ICCID on a SIM delivery, checked
     *                                  again when the SIM is registered (Add SIM / Setup Device).
     *
     * ADDITIVE ONLY: new nullable columns or columns with a default, so every existing row stays valid and
     * rolling back is dropping them. Existing purchase orders become ORDERED.
     */
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->string('status', 10)->default('ORDERED');
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 200)->nullable();
            $table->string('updated_by', 64)->nullable();     // Admins.admin_id of the last edit or the cancel
        });

        Schema::table('supplier_deliveries', function (Blueprint $table) {
            $table->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->restrictOnDelete();
            $table->index('supplier_invoice_id');
        });

        Schema::table('supplier_delivery_units', function (Blueprint $table) {
            $table->string('sim_number', 10)->nullable()->unique();
        });

        // The application checks the same rules; these keep a stray SQL edit from breaking them.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE supplier_invoices ADD CONSTRAINT supplier_invoices_status_chk CHECK (
                (status = 'ORDERED' AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL)
            )");
            DB::statement("ALTER TABLE supplier_delivery_units ADD CONSTRAINT supplier_delivery_units_sim_number_chk CHECK (
                sim_number IS NULL OR (kind = 'SIM' AND sim_number ~ '^[0-9]{10}$')
            )");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_delivery_units DROP CONSTRAINT IF EXISTS supplier_delivery_units_sim_number_chk');
            DB::statement('ALTER TABLE supplier_invoices DROP CONSTRAINT IF EXISTS supplier_invoices_status_chk');
        }

        Schema::table('supplier_delivery_units', function (Blueprint $table) {
            $table->dropUnique(['sim_number']);
            $table->dropColumn('sim_number');
        });

        Schema::table('supplier_deliveries', function (Blueprint $table) {
            $table->dropIndex(['supplier_invoice_id']);
            $table->dropConstrainedForeignId('supplier_invoice_id');
        });

        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn(['status', 'cancelled_at', 'cancel_reason', 'updated_by']);
        });
    }
};
