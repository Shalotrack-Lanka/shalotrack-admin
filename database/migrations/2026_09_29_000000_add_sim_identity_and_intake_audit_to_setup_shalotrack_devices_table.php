<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Device registration used to keep only the 10-digit SIM number and then
     * DELETE the SIM's master row (which held its ICCID/IMSI) — so once a SIM
     * went into a tracker, the record of *which physical SIM card* it was
     * vanished. These columns snapshot that identity on the device row, and
     * add a small audit trail (who registered it, and by which route).
     *
     * All columns are nullable: existing rows stay valid, and devices
     * registered without a SIM stay valid.
     */
    public function up(): void
    {
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            // Postgres allows many NULLs under a UNIQUE index, so devices
            // without a SIM don't collide; two devices can never share a SIM.
            $table->string('iccid', 20)->nullable()->unique()->after('sim_number');
            $table->string('imsi', 15)->nullable()->after('iccid');

            // Admins.admin_id is a string PK, so this is a plain string, not a FK.
            $table->string('registered_by')->nullable()->after('imsi');
            // manual | scan  (existing rows stay NULL = unknown/legacy)
            $table->string('intake_source', 16)->nullable()->after('registered_by');
        });
    }

    public function down(): void
    {
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            $table->dropUnique(['iccid']);
            $table->dropColumn(['iccid', 'imsi', 'registered_by', 'intake_source']);
        });
    }
};