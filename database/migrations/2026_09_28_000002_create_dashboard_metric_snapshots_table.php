<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Backs the dashboard's week-over-week trend indicators. One row per
    // calendar day, written once daily by the dashboard:snapshot scheduled
    // command (see routes/console.php). Deliberately NOT stored in cache --
    // cache is ephemeral (can be flushed, evicted, or point at a store that
    // isn't durable across deploys), and a trend line that silently resets
    // itself is worse than no trend line, because it would report a wrong
    // delta instead of an honest "no data yet".
    public function up(): void
    {
        Schema::create('dashboard_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date')->unique();
            $table->unsignedInteger('open_complaints_count')->default(0);
            $table->unsignedInteger('devices_online')->default(0);
            $table->unsignedInteger('devices_offline')->default(0);
            $table->unsignedInteger('stock_units_available')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_metric_snapshots');
    }
};