<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nukes the shared devices table. Cancel Device & Faulty Device Report
        // will break after this — that was your call, not mine.
        Schema::dropIfExists('devices');

        Schema::create('setup_shalotrack_devices', function (Blueprint $table) {
            $table->id('shdevice_id');           // auto-increment PK, named shdevice_id
            $table->string('device_category');    // e.g. Shalotrack V5 basic
            $table->string('imei_number')->unique();
            $table->string('sim_number')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_shalotrack_devices');
        // Not rebuilding the old devices table here — its FK to stocks and
        // its data are gone. A down() can't resurrect deleted data. Restore
        // from a DB backup if you need to roll back for real.
    }
};