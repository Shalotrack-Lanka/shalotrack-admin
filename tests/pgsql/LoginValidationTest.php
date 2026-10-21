<?php

namespace Tests\Pgsql;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\PgsqlTestCase;

class LoginValidationTest extends PgsqlTestCase
{
    use DatabaseTransactions;

    public function test_login_requires_username_and_password(): void
    {
        $response = $this->post('/login', []);

        $response->assertSessionHasErrors();
    }
}