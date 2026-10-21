<?php

namespace Tests\Pgsql;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\PgsqlTestCase;

class ExampleDbTest extends PgsqlTestCase
{
    use DatabaseTransactions;

    public function test_connection_is_isolated(): void
    {
        $row = DB::selectOne('select current_schema() as s');
        $this->assertSame('autotest', $row->s);
    }
}