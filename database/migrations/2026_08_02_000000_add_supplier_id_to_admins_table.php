<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The role column has a real Postgres CHECK constraint that
        // currently only allows ADMIN/DEALER/FINANCE/TECHNICIAN — SUPPLIER
        // was never added. Table name is "Admins" (capital A, quoted),
        // confirmed from the real schema earlier today.
        DB::statement('ALTER TABLE "Admins" DROP CONSTRAINT IF EXISTS admins_role_check');
        DB::statement("ALTER TABLE \"Admins\" ADD CONSTRAINT admins_role_check CHECK ((role)::text = ANY (ARRAY[('ADMIN')::text, ('DEALER')::text, ('FINANCE')::text, ('TECHNICIAN')::text, ('SUPPLIER')::text]))");

        Schema::table('Admins', function (Blueprint $table) {
            if (!Schema::hasColumn('Admins', 'supplier_id')) {
                $table->foreignId('supplier_id')->nullable()->after('dealer_id')
                      ->constrained('suppliers')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('Admins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });

        DB::statement('ALTER TABLE "Admins" DROP CONSTRAINT IF EXISTS admins_role_check');
        DB::statement("ALTER TABLE \"Admins\" ADD CONSTRAINT admins_role_check CHECK ((role)::text = ANY (ARRAY[('ADMIN')::text, ('DEALER')::text, ('FINANCE')::text, ('TECHNICIAN')::text]))");
    }
};