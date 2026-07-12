<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            $table->foreignId('dealer_id')->nullable()->after('canceled_date')
                  ->constrained('dealers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('setup_shalotrack_devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dealer_id');
        });
    }
};