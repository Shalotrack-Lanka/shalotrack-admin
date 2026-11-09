<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add a new column to the stocks table
        Schema::table('stocks', function (Blueprint $table) {
            $table->integer('dealer_transferred')->default(0)->after('company_available_stock');
        });

        // 2. Table that keeps the transfer history
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained('stocks')->onDelete('cascade');
            $table->foreignId('dealer_id')->constrained('dealers')->onDelete('cascade');
            $table->integer('quantity');
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn('dealer_transferred');
        });
    }
};