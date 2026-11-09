<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::create('stocks', function (Blueprint $table) {
            $table->id(); // Primary key (ID)
            $table->string('product_name'); // Product name (e.g., Letstrack Basic)
            $table->string('product_model'); // Model name (e.g., Letstrack Basic Series)
            $table->integer('stock_in')->default(0); // Total stock quantity
            $table->integer('company_available_stock')->default(0); // Quantity held by the company
            $table->integer('dealer_available_stock')->default(0); // Quantity held by dealers
            $table->integer('sold_to_customer')->default(0); // Quantity sold to customers
            $table->timestamps(); // created_at and updated_at (record date and time)
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
