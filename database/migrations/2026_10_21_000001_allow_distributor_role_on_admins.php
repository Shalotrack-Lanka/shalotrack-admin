<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A distributor (the middle man between the company and the dealers) gets its own login role.
 *
 * "Admins".role is guarded by a check constraint (admins_role_check), so the new value must be added
 * there or every insert/update of a DISTRIBUTOR login fails with error 23514.
 *
 * Postgres only (the constraint does not exist on the sqlite test database). The same statement can be
 * run by hand in the Supabase SQL editor:
 *
 *   ALTER TABLE "Admins" DROP CONSTRAINT IF EXISTS admins_role_check;
 *   ALTER TABLE "Admins" ADD CONSTRAINT admins_role_check CHECK ((role)::text = ANY (ARRAY[
 *     ('ADMIN')::text, ('DEALER')::text, ('FINANCE')::text, ('TECHNICIAN')::text,
 *     ('SUPPLIER')::text, ('DISTRIBUTOR')::text]));
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE "Admins" DROP CONSTRAINT IF EXISTS admins_role_check');
        DB::statement("ALTER TABLE \"Admins\" ADD CONSTRAINT admins_role_check CHECK ((role)::text = ANY (ARRAY[('ADMIN')::text, ('DEALER')::text, ('FINANCE')::text, ('TECHNICIAN')::text, ('SUPPLIER')::text, ('DISTRIBUTOR')::text]))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Refuse to go back while distributor logins exist: the old constraint would reject the rows.
        if (DB::table('Admins')->where('role', 'DISTRIBUTOR')->exists()) {
            throw new RuntimeException('Cannot remove DISTRIBUTOR from admins_role_check while DISTRIBUTOR logins exist. Change or delete them first.');
        }

        DB::statement('ALTER TABLE "Admins" DROP CONSTRAINT IF EXISTS admins_role_check');
        DB::statement("ALTER TABLE \"Admins\" ADD CONSTRAINT admins_role_check CHECK ((role)::text = ANY (ARRAY[('ADMIN')::text, ('DEALER')::text, ('FINANCE')::text, ('TECHNICIAN')::text, ('SUPPLIER')::text]))");
    }
};