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
            
            // 🔗 Stocks table එක සමඟ සම්බන්ධ කරන Foreign Key එක
            $table->foreignId('stock_id')
                  ->constrained('stocks')
                  ->onDelete('cascade');

            $table->string('sim_number')->unique(); // SIM කාඩ්පතේ අංකය (Unique)
            $table->string('sim_type');            // SIM Type (e.g., Dialog, Mobitel, Hutch)
            $table->string('provider')->nullable(); // Provider/Network Name
            $table->string('imei_number')->nullable(); // සම්බන්ධිත IMEI අංකය (Optional)
            $table->string('status')->default('Available'); // Available, Activated, Faulty
            $table->boolean('activation_required')->default(false); // Network Testing Required ද යන්න
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sims');
    }
};