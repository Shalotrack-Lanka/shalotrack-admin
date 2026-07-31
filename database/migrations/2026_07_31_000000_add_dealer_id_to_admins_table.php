<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table name is "Admins" (capital A) — confirmed from the real
        // schema. Postgres is case-sensitive for quoted identifiers, so
        // this must match exactly or the migration silently targets a
        // table that doesn't exist.
        Schema::table('Admins', function (Blueprint $table) {
            $table->foreignId('dealer_id')->nullable()->after('role')
                  ->constrained('dealers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('Admins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dealer_id');
        });
    }
};