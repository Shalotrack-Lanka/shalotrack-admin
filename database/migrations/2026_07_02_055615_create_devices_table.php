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
        Schema::create('devices', function (Blueprint $table) {
            $table->id(); // Primary Key
            
            // 🔗 Foreign key linking to the stocks table
            $table->foreignId('stock_id')
                  ->constrained('stocks')
                  ->onDelete('cascade'); 
            
            // 🆔 Attributes specific to devices
            $table->string('imei_number')->unique(); // IMEI number (must be unique)
            $table->string('sim_number')->nullable(); // SIM number (optional)
            $table->string('device_model'); // Device Model (e.g., Letstrack Basic Series)
            
            // 📊 Stock / Warehouse Management Attributes
            $table->string('branch_name')->default('Srilanka Branch'); // Branch
            $table->string('status')->default('Available'); // Available, Faulty, Sold, Missing, Repaired
            
            // 📑 Audit & Tracking Attributes
            $table->string('po_reference')->nullable(); // Purchase order reference number
            $table->text('description')->nullable(); // Other special notes / remarks
            
            $table->timestamps(); // created_at and updated_at
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};