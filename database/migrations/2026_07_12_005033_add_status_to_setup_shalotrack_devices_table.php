<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            $table->string('status')->default('Not Activated')->after('sim_number');
            $table->string('cancel_reason')->nullable()->after('status');
            $table->timestamp('canceled_date')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            $table->dropColumn(['status', 'cancel_reason', 'canceled_date']);
        });
    }
};