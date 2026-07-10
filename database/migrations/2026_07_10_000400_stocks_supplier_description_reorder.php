<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reversing the previous migration's unique constraint. Not editing
        // that file — it already ran. This is the correct way to undo a
        // migration that's live: a new migration, not a rewritten old one.
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropUnique(['device_type_id']);
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->foreignId('supplier_id')
                  ->nullable()
                  ->after('device_type_id')
                  ->constrained('suppliers')
                  ->nullOnDelete();

            $table->text('description')->nullable()->after('total_available');
            $table->unsignedBigInteger('sort_order')->nullable()->after('description');
        });

        // Backfill sort_order = id so existing rows keep today's ordering
        // (newest id = highest sort_order = shows first) until someone
        // manually reorders them.
        DB::statement('UPDATE stocks SET sort_order = id');
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
            $table->dropColumn(['description', 'sort_order']);
        });

        Schema::table('stocks', function (Blueprint $table) {
            $table->unique('device_type_id');
        });
    }
};