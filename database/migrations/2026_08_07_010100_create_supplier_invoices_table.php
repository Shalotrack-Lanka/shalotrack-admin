<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();

            // Auto-generated (PO-2026-0001 style) — never entered by hand,
            // so it's safe to enforce uniqueness at the DB level too.
            $table->string('invoice_number')->unique();

            $table->foreignId('supplier_id')
                  ->constrained('suppliers')
                  ->cascadeOnDelete();

            $table->date('invoice_date');

            $table->decimal('grand_total', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
    }
};