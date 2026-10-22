<?php

namespace Tests\Pgsql;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\PgsqlTestCase;

class AutotestSmokeTest extends PgsqlTestCase
{
    use DatabaseTransactions;

    public function test_can_write_and_read_in_autotest_schema(): void
    {
        DB::table('device_types')->insert([
            'device_category' => 'TEST-CATEGORY',
            'model'           => 'TEST-MODEL',
            'protocol'        => 'TEST-PROTOCOL',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->assertSame(
            1,
            DB::table('device_types')->where('device_category', 'TEST-CATEGORY')->count()
        );
    }
}