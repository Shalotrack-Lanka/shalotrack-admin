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
        Schema::create('stock_transfer_ledgers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained('stocks')->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();

            // Snapshot of the supplier's name at the time of the stock-in, so
            // the ledger row still reads correctly if the supplier record is
            // later renamed or removed.
            $table->string('supplier')->nullable();

            $table->integer('stock_in');
            $table->text('description')->nullable();
            $table->date('stocked_in_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_ledgers');
    }
};
