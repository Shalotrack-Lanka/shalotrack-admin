<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where and when a bound device was physically fitted. Nullable on purpose: devices bound before
     * this existed have no recorded install, and they show up under "missing install details" instead
     * of being given an invented date. Warranty is NOT stored here: it is the subscription period.
     */
    public function up(): void
    {
        Schema::table('activated_devices', function (Blueprint $table) {
            $table->date('installed_on')->nullable()->after('status');
            $table->string('installed_by', 100)->nullable()->after('installed_on');
            $table->string('install_notes', 500)->nullable()->after('installed_by');
        });
    }

    public function down(): void
    {
        Schema::table('activated_devices', function (Blueprint $table) {
            $table->dropColumn(['installed_on', 'installed_by', 'install_notes']);
        });
    }
};