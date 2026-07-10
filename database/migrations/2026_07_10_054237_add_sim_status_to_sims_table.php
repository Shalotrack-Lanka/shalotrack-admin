<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sims', function (Blueprint $table) {
            // පරණ status එක වෙනස් නොකර අලුත් එකක් දානවා
            $table->string('sim_status')->default('Not Activated')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sims', function (Blueprint $table) {
            $table->dropColumn('sim_status');
        });
    }
};