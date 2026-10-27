<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Security regression: the error page must never show the exception message, SQL, table names or the server
 * file path to the browser. Only a plain message and the reference ID are shown (details stay in the log).
 */
class ErrorPageLeakTest extends TestCase
{
    private const SECRET = 'select * from "Admins" where password_hash = \'LEAKME\'';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/boom', function () {
            throw new QueryException('pgsql', self::SECRET, [], new \Exception('SQLSTATE[42P01]: relation "Admins" does not exist'));
        });
    }

    public function test_error_page_does_not_leak_sql_or_file_path_by_default(): void
    {
        config(['app.error_details' => false]);

        $response = $this->get('/_test/boom');

        $response->assertStatus(500);
        $response->assertSee('Reference ID');
        $response->assertDontSee('LEAKME', false);
        $response->assertDontSee('SQLSTATE', false);
        $response->assertDontSee('Admins', false);
        $response->assertDontSee('ErrorPageLeakTest.php', false);
        $response->assertDontSee('.php:', false);
        $response->assertDontSee('Database', false);
    }

    public function test_technical_detail_is_shown_only_when_explicitly_enabled(): void
    {
        config(['app.error_details' => true]);

        $this->get('/_test/boom')->assertStatus(500)->assertSee('Database')->assertSee('ErrorPageLeakTest.php', false);
    }

    public function test_not_found_page_shows_no_internal_detail(): void
    {
        config(['app.error_details' => false]);

        $this->get('/definitely/not/a/real/page')->assertStatus(404)->assertDontSee('.php:', false);
    }
}