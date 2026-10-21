<?php

namespace Tests\Pgsql;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

class AdminAccessTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    public function test_admin_can_open_admin_dashboard(): void
    {
        Http::fake(); // never let the dashboard reach the hosted API

        $admin = $this->makeAdmin('ADMIN');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_dealer_cannot_open_admin_dashboard(): void
    {
        Http::fake();

        $dealer = $this->makeAdmin('DEALER');

        $status = $this->actingAs($dealer)
            ->get(route('admin.dashboard'))
            ->getStatusCode();

        $this->assertContains($status, [302, 403]);
    }
}