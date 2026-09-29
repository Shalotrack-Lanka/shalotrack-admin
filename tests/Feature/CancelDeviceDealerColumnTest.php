<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Dealer;
use App\Models\SetupShalotrackDevice;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cancel Device page: the "Not Activated" table must say who holds each
 * device. (Own minimal schema - the full migration history can't build a
 * fresh sqlite database.)
 */
class CancelDeviceDealerColumnTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake(); // page chrome (header/sidebar) may call the API

        Schema::create('Admins', function (Blueprint $t) {
            $t->string('admin_id')->primary();
            $t->string('username')->nullable();
            $t->string('password')->nullable();
            $t->string('full_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone_number')->nullable();
            $t->string('role');
            $t->string('status')->default('ACTIVE');
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('dealers', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->timestamps();
        });
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('device_category');
            $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable();
            $t->string('status')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('canceled_date')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->timestamps();
        });

        $this->actingAs(Admin::forceCreate([
            'admin_id' => 'adm-1', 'username' => 'a', 'role' => 'ADMIN', 'status' => 'ACTIVE',
        ]));
    }

    private function device(string $imei, ?int $dealerId): void
    {
        SetupShalotrackDevice::create([
            'device_category' => 'V5 Basic', 'imei_number' => $imei,
            'status' => 'Not Activated', 'dealer_id' => $dealerId,
        ]);
    }

    public function test_not_activated_table_shows_dealer_or_company_stock(): void
    {
        $dealer = Dealer::create(['full_name' => 'Kasun Rajitha']);
        $this->device('868159941856606', $dealer->id);
        $this->device('864876170981409', null);

        $html = $this->get(route('admin.cancel_device.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Kasun Rajitha', $html);
        $this->assertStringContainsString('Company stock', $html);
    }

    public function test_device_pointing_at_a_missing_dealer_is_flagged_not_shown_as_company_stock(): void
    {
        $this->device('868159941856606', 999);

        $this->get(route('admin.cancel_device.index'))
            ->assertOk()->assertSee('Unknown dealer #999')->assertDontSee('Company stock');
    }
}
