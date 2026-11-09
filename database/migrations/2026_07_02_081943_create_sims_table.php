<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sims', function (Blueprint $table) {
            $table->id();
            
            // 🔗 Foreign key linking to the stocks table
            $table->foreignId('stock_id')
                  ->constrained('stocks')
                  ->onDelete('cascade');

            $table->string('sim_number')->unique(); // SIM card number (unique)
            $table->string('sim_type');            // SIM Type (e.g., Dialog, Mobitel, Hutch)
            $table->string('provider')->nullable(); // Provider/Network Name
            $table->string('imei_number')->nullable(); // Linked IMEI number (optional)
            $table->string('status')->default('Available'); // Available, Activated, Faulty
            $table->boolean('activation_required')->default(false); // Whether network testing is required
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sims');
    }
};