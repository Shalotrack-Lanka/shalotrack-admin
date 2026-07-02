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
            
            // 🔗 Stocks table එකත් එක්ක සම්බන්ධ කරන බන්ධන යතුර (Foreign Key)
            $table->foreignId('stock_id')
                  ->constrained('stocks')
                  ->onDelete('cascade'); 
            
            // 🆔 Devices වලටම ආවේණික අලුත් Attributes
            $table->string('imei_number')->unique(); // IMEI අංකය (Unique විය යුතුය)
            $table->string('sim_number')->nullable(); // SIM අංකය (Optional)
            $table->string('device_model'); // Device Model (e.g., Letstrack Basic Series)
            
            // 📊 Stock / Warehouse Management Attributes
            $table->string('branch_name')->default('Srilanka Branch'); // ඇති ශාඛාව
            $table->string('status')->default('Available'); // Available, Faulty, Sold, Missing, Repaired
            
            // 📑 Audit & Tracking Attributes
            $table->string('po_reference')->nullable(); // Purchase Order reference අංකය
            $table->text('description')->nullable(); // වෙනත් විශේෂ සටහන්/Remarks
            
            $table->timestamps(); // created_at සහ updated_at
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