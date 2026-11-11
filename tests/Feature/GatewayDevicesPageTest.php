<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Gateway Devices page: reads the gateway's /access report, shows trial state, never breaks when the
 * gateway is down or older. sqlite in-memory only.
 */
class GatewayDevicesPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', \DB::getDriverName());
        $this->withoutVite();

        config(['services.gateway.command_url' => 'http://gw.test:8001', 'services.gateway.command_api_key' => 'KEY']);

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
        Schema::create('activated_devices', function (Blueprint $t) {
            $t->id('activated_device_id');
            $t->string('vehicle_id')->unique();
            $t->string('customer_name')->nullable();
            $t->string('vehicle_number')->nullable();
            $t->string('imei_number')->nullable();
            $t->timestamps();
        });

        $this->actingAs(Admin::forceCreate([
            'admin_id' => 'adm-1', 'username' => 'a', 'role' => 'ADMIN', 'status' => 'ACTIVE',
        ]));
    }

    private function report(array $over = []): array
    {
        return array_merge([
            'generated_at' => '2026-11-11T12:00:00+00:00', 'trial_mode' => 'shadow', 'recheck_mode' => 'off',
            'trial_hours' => 24, 'total_devices' => 4, 'connected_now' => 1, 'connected_not_registered' => [],
            'counts' => ['allowed' => 1, 'trial' => 1, 'trial_not_started' => 0, 'trial_expired' => 1, 'stopped' => 1],
            'truncated' => false,
            'devices' => [
                ['imei' => '111111111111111', 'status' => 'Not Activated', 'category' => 'trial_expired', 'trial_hours_left' => 0.0,
                 'first_seen_at' => '2026-11-09T01:00:00+00:00', 'bound_at' => null, 'connected' => false],
                ['imei' => '222222222222222', 'status' => 'Assigned to Customer', 'category' => 'trial', 'trial_hours_left' => 5.5,
                 'first_seen_at' => '2026-11-11T05:00:00+00:00', 'bound_at' => null, 'connected' => true],
                ['imei' => '333333333333333', 'status' => 'Temporarily Stopped', 'category' => 'stopped', 'trial_hours_left' => null,
                 'first_seen_at' => null, 'bound_at' => null, 'connected' => false],
                ['imei' => '444444444444444', 'status' => 'Activated', 'category' => 'allowed', 'trial_hours_left' => null,
                 'first_seen_at' => null, 'bound_at' => '2026-10-27T20:34:25+00:00', 'connected' => true],
            ],
        ], $over);
    }

    public function test_shows_trial_state_and_the_shadow_warning(): void
    {
        Http::fake(['gw.test:8001/access' => Http::response($this->report())]);

        $html = $this->get(route('admin.vehicles.gateway-devices'))->assertOk()->getContent();

        $this->assertStringContainsString('111111111111111', $html);
        $this->assertStringContainsString('Trial ended, bind to enable', $html);
        $this->assertStringContainsString('5.5 h', $html);
        $this->assertStringContainsString('nothing is cut off yet', $html);   // shadow banner
        $this->assertStringContainsString('Trial rule: shadow', $html);
    }

    public function test_sends_the_command_api_key(): void
    {
        Http::fake(['gw.test:8001/access' => Http::response($this->report())]);
        $this->get(route('admin.vehicles.gateway-devices'))->assertOk();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/access') && $r->header('X-Command-Api-Key')[0] === 'KEY');
    }

    public function test_connected_filter_lists_only_connected_devices(): void
    {
        Http::fake(['gw.test:8001/access' => Http::response($this->report())]);

        $html = $this->get(route('admin.vehicles.gateway-devices', ['filter' => 'connected']))->assertOk()->getContent();

        $this->assertStringContainsString('222222222222222', $html);
        $this->assertStringContainsString('444444444444444', $html);
        $this->assertStringNotContainsString('111111111111111', $html);
    }

    public function test_names_come_from_the_bound_vehicle(): void
    {
        \DB::table('activated_devices')->insert([
            'vehicle_id' => 'v1', 'imei_number' => '444444444444444', 'vehicle_number' => 'WP BGU 1212', 'customer_name' => 'Okitha',
        ]);
        Http::fake(['gw.test:8001/access' => Http::response($this->report())]);

        $this->get(route('admin.vehicles.gateway-devices', ['filter' => 'all']))->assertOk()->assertSee('WP BGU 1212');
    }

    public function test_enforce_mode_has_no_shadow_warning(): void
    {
        Http::fake(['gw.test:8001/access' => Http::response($this->report(['trial_mode' => 'enforce']))]);

        $this->get(route('admin.vehicles.gateway-devices'))->assertOk()->assertDontSee('nothing is cut off yet');
    }

    public function test_gateway_down_shows_a_message_not_an_error(): void
    {
        Http::fake(['gw.test:8001/access' => Http::response([], 503)]);

        $this->get(route('admin.vehicles.gateway-devices'))->assertOk()->assertSee('could not be reached');
    }

    public function test_older_gateway_without_the_report_says_so(): void
    {
        Http::fake(['gw.test:8001/access' => Http::response(['error' => 'Not found'], 404)]);

        $this->get(route('admin.vehicles.gateway-devices'))->assertOk()->assertSee('older version');
    }

    public function test_only_admins_may_open_it(): void
    {
        Http::fake();
        $this->flushSession();
        $this->actingAs(Admin::forceCreate(['admin_id' => 'd-1', 'username' => 'd', 'role' => 'DEALER', 'status' => 'ACTIVE']));

        $this->get(route('admin.vehicles.gateway-devices'))->assertForbidden();
    }
}
