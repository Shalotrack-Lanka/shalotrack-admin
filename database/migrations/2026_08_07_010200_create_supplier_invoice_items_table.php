<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoice_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_invoice_id')
                  ->constrained('supplier_invoices')
                  ->cascadeOnDelete();

            $table->foreignId('product_id')
                  ->constrained('products')
                  // Deliberately restrict, not cascade — deleting a product
                  // that's already on a saved invoice should fail loudly,
                  // not silently erase invoice history.
                  ->restrictOnDelete();

            $table->string('type'); // 'SIM' or 'Device' — as picked on the line, independent of the product's own device_type_id link

            $table->integer('order_qty');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 5, 2)->default(0);   // percent
            $table->decimal('face_value', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_items');
    }
};