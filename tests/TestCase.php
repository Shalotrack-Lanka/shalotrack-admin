<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test builds its own in-memory schema, so the "does device_sync_state exist" answer is per test.
        \App\Services\DeviceSync::forgetTableCheck();
    }
}
