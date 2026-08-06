<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_types', function (Blueprint $table) {
            // Stores the selected feature IDs as a JSON array, e.g. [1,3,4].
            // Only meaningful when the device type's model is "Customize" —
            // Basic/Plus device types leave this null.
            $table->json('features')->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('device_types', function (Blueprint $table) {
            $table->dropColumn('features');
        });
    }
};