<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_types', function (Blueprint $table) {
            $table->dropUnique(['device_category']);
            $table->unique(['device_category', 'model'], 'device_types_category_model_unique');
        });
    }

    public function down(): void
    {
        Schema::table('device_types', function (Blueprint $table) {
            $table->dropUnique('device_types_category_model_unique');
            $table->unique('device_category');
        });
    }
};