<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What has been PAID to each supplier, per purchase order. A new table only (nothing existing is touched).
     *
     * A payment is never edited or deleted: a wrong one is VOIDED with a reason (it stays on record, like the
     * billing credit notes). What is owed on an order = its total - the payments that are not voided.
     */
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('method', 12);                    // CASH, BANK, CHEQUE, OTHER
            $table->string('reference', 100)->nullable();    // cheque no. / bank transfer reference
            $table->string('note', 500)->nullable();
            $table->string('recorded_by', 64)->nullable();   // Admins.admin_id
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 200)->nullable();
            $table->string('voided_by', 64)->nullable();
            $table->timestamps();

            $table->index('supplier_invoice_id');
            $table->index('supplier_id');
        });

        // The application checks the same rules; these keep a stray SQL edit from breaking them.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_amount_chk CHECK (amount > 0)');
            DB::statement("ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_method_chk CHECK (method IN ('CASH','BANK','CHEQUE','OTHER'))");
            DB::statement('ALTER TABLE supplier_payments ADD CONSTRAINT supplier_payments_void_chk CHECK ((voided_at IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND void_reason IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};
