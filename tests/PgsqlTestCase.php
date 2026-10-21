<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Base class for tests that run on the real Postgres, inside the isolated "autotest" schema only.
 * The existing SQLite suite (tests/Feature, tests/Unit) keeps using Tests\TestCase.
 */
abstract class PgsqlTestCase extends TestCase
{
    protected function setUp(): void
    {
        // Must run BEFORE parent::setUp(): these traits wipe tables while the app boots.
        $traits = class_uses_recursive(static::class);
        if (isset($traits[RefreshDatabase::class]) || isset($traits[DatabaseMigrations::class])) {
            throw new RuntimeException(static::class . ' must use DatabaseTransactions. Tests stopped.');
        }

        parent::setUp();

        $row = DB::selectOne('select current_schema() as s, current_user as u');
        if ($row->s !== 'autotest' || ! str_starts_with($row->u, 'autotest_user')) {
            throw new RuntimeException("Unsafe DB connection ({$row->s}/{$row->u}). Tests stopped.");
        }

        // Pages load without needing a built front end (npm run build).
        $this->withoutVite();

        // Any real call to the hosted API / gateway fails loudly unless the test fakes it.
        Http::preventStrayRequests();
    }
}