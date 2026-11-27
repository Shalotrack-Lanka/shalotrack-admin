<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * The DATABASE itself refuses two logins with the same email (index admins_email_lower_unique on lower(email)),
 * so a race, a script or a hand edit cannot bypass the form rule. Empty and missing emails stay allowed.
 * Everything is rolled back after each test (a failed insert is wrapped in a savepoint).
 */
class AdminsEmailUniqueIndexPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private function insertLogin(string $username, ?string $email): void
    {
        DB::table('Admins')->insert([
            'username' => $username, 'password' => 'x', 'role' => 'ADMIN', 'status' => 'ACTIVE', 'email' => $email,
        ]);
    }

    private function refused(callable $do): bool
    {
        DB::beginTransaction();   // savepoint: Postgres aborts the whole transaction after a failed statement
        try {
            $do();
            DB::commit();

            return false;
        } catch (QueryException $e) {
            DB::rollBack();

            return str_contains($e->getMessage(), 'admins_email_lower_unique');
        }
    }

    public function test_the_index_exists_and_covers_the_lowercase_email(): void
    {
        $row = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE tablename = 'Admins' AND indexname = 'admins_email_lower_unique' AND schemaname = current_schema()");

        $this->assertNotNull($row, 'run the migration 2026_11_27_000001 on this schema');
        $this->assertStringContainsString('lower', $row->indexdef);
        $this->assertStringContainsString('UNIQUE', $row->indexdef);
    }

    public function test_the_same_email_twice_is_refused_by_the_database(): void
    {
        $this->insertLogin('autotest-mail-a', 'autotest-dup@example.test');

        $this->assertTrue($this->refused(fn () => $this->insertLogin('autotest-mail-b', 'autotest-dup@example.test')));
    }

    public function test_the_same_email_in_another_letter_case_is_refused(): void
    {
        $this->insertLogin('autotest-mail-c', 'autotest-case@example.test');

        $this->assertTrue($this->refused(fn () => $this->insertLogin('autotest-mail-d', 'AutoTest-CASE@Example.TEST')));
    }

    public function test_changing_a_login_to_an_email_another_login_uses_is_refused(): void
    {
        $this->insertLogin('autotest-mail-e', 'autotest-one@example.test');
        $this->insertLogin('autotest-mail-f', 'autotest-two@example.test');

        $this->assertTrue($this->refused(fn () => DB::table('Admins')->where('username', 'autotest-mail-f')->update(['email' => 'AUTOTEST-ONE@example.test'])));
    }

    public function test_many_logins_without_an_email_are_still_allowed(): void
    {
        $this->insertLogin('autotest-mail-g', null);
        $this->insertLogin('autotest-mail-h', null);
        $this->insertLogin('autotest-mail-i', '');
        $this->insertLogin('autotest-mail-j', '');

        $this->assertSame(4, DB::table('Admins')->whereIn('username', ['autotest-mail-g', 'autotest-mail-h', 'autotest-mail-i', 'autotest-mail-j'])->count());
    }

    public function test_saving_a_login_again_with_its_own_email_still_works(): void
    {
        $admin = $this->makeAdmin('ADMIN');

        $this->assertSame(1, DB::table('Admins')->where('admin_id', $admin->admin_id)->update(['email' => $admin->email, 'full_name' => 'Autotest Renamed']));
    }

    public function test_the_migration_stops_and_changes_nothing_when_duplicates_already_exist(): void
    {
        // Postgres can undo DDL, so this is rolled back with the test like everything else.
        DB::statement('DROP INDEX admins_email_lower_unique');
        $this->insertLogin('autotest-mail-k', 'autotest-old@example.test');
        $this->insertLogin('autotest-mail-l', 'AUTOTEST-OLD@example.test');

        $migration = require database_path('migrations/2026_11_27_000001_add_unique_lower_email_index_to_admins.php');

        try {
            $migration->up();
            $this->fail('the migration should refuse while two logins share an email');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('autotest-old@example.test', $e->getMessage());
            $this->assertStringContainsString('autotest-mail-k, autotest-mail-l', $e->getMessage());
        }

        $this->assertNull(DB::selectOne("SELECT 1 FROM pg_indexes WHERE indexname = 'admins_email_lower_unique' AND schemaname = current_schema()"));
        $this->assertSame(2, DB::table('Admins')->whereIn('username', ['autotest-mail-k', 'autotest-mail-l'])->count(), 'no row was touched');
    }
}
