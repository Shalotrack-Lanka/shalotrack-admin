<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_types', function (Blueprint $table) {
            $table->id(); // Auto-increment DevID (Primary Key)

            $table->string('device_category')->unique(); // Cannot be duplicated
            $table->string('model');                      // Device model
            $table->string('protocol');                   // Language / Protocol (alphanumeric, multi-word allowed)

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_types');
    }
};