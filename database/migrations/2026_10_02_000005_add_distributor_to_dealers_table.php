<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which distributor a dealer reports to (the middle man between the company and the dealer).
     * The distributor is itself a dealer row whose type is "distributor". It decides who earns the
     * Distributor margin on a package a dealer sells. Null = the dealer has no distributor, and that
     * margin is then not paid to anyone.
     */
    public function up(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            $table->foreignId('distributor_id')->nullable()->after('dealer_status')
                ->constrained('dealers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dealers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('distributor_id');
        });
    }
};