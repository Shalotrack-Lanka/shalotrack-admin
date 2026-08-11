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
        // The IMEI-based, stock-linked design is being replaced wholesale by
        // IMSI/ICCID-based SIM registration — old rows don't map onto the new
        // shape, so the table is rebuilt rather than altered column-by-column.
        Schema::dropIfExists('sims');

        Schema::create('sims', function (Blueprint $table) {
            $table->id();
            $table->string('sim_number', 10)->unique();
            $table->string('sim_type');
            $table->string('imsi', 15)->unique();
            $table->string('iccid', 20)->unique();
            $table->boolean('activation_required')->default(false);
            $table->string('sim_status')->default('Not Activated');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sims');

        Schema::create('sims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained('stocks')->onDelete('cascade');
            $table->string('sim_number')->unique();
            $table->string('sim_type');
            $table->string('provider')->nullable();
            $table->string('imei_number')->nullable();
            $table->string('status')->default('Available');
            $table->boolean('activation_required')->default(false);
            $table->text('description')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('sim_status')->default('Not Activated');
            $table->timestamps();
        });
    }
};
