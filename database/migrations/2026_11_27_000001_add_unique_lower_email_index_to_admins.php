<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One login per email address, enforced by the DATABASE.
 *
 * Until now only code checked this (App\Rules\EmailFreeForLogin), so two requests at the same moment, a
 * script, or a hand edit in Supabase could still create two logins with one email. The rule compares
 * lower(email), so the index does too ("A@x.com" and "a@x.com" are the same address). Empty and NULL emails
 * are allowed any number of times (a supplier or technician login may have none).
 *
 * SAFE: the migration deletes and changes NO data. If two logins already share an email it STOPS with the list
 * and creates nothing; fix the duplicates first (change one email, or deactivate the extra login) and run it again.
 *
 * Postgres only. The same statements can be run by hand in the Supabase SQL editor (first the SELECT, which
 * must return no rows, then the CREATE):
 *
 *   SELECT lower(email), count(*) FROM "Admins" WHERE email IS NOT NULL AND email <> '' GROUP BY 1 HAVING count(*) > 1;
 *   CREATE UNIQUE INDEX IF NOT EXISTS admins_email_lower_unique ON "Admins" (lower(email)) WHERE email IS NOT NULL AND email <> '';
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $duplicates = DB::select(
            'SELECT lower(email) AS email, count(*) AS logins, string_agg(username, \', \' ORDER BY username) AS usernames
               FROM "Admins" WHERE email IS NOT NULL AND email <> \'\'
              GROUP BY lower(email) HAVING count(*) > 1 ORDER BY lower(email)'
        );

        if ($duplicates !== []) {
            $list = collect($duplicates)->map(fn ($d) => "{$d->email} used by {$d->usernames}")->implode('; ');

            throw new RuntimeException('Cannot add the unique email index: these emails are shared by more than one login. Fix them first, nothing was changed. ' . $list);
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS admins_email_lower_unique ON "Admins" (lower(email)) WHERE email IS NOT NULL AND email <> \'\'');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS admins_email_lower_unique');
    }
};
