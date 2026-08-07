<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Nullable on purpose — not every product represents a
            // physical trackable device (e.g. a SIM package). Only
            // products linked here will ever touch the stocks table.
            $table->foreignId('device_type_id')
                  ->nullable()
                  ->after('description')
                  ->constrained('device_types')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_type_id');
        });
    }
};