<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\CustomerAd;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression tests for the "dead ends like this" audit:
 *  - Linux-only case bugs / missing views behind live links
 *  - device-command authorization (ownership) and the dealer-scope leak
 *  - no public self-registration
 *  - polling intervals / error handling in the shared layouts
 */
class AuditFixesTest extends TestCase
{
    private const GATEWAY = 'http://gateway.shalotrack.internal:8001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

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
            $t->string('full_name')->nullable();
            $t->timestamps();
        });
        Schema::create('dealer_customer_ads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('contact')->nullable();
            $t->timestamps();
        });
        Schema::create('Customer-ad', function (Blueprint $t) {
            $t->string('customer_id')->primary();
            $t->string('email')->nullable();
            $t->string('phone_number')->nullable();
            $t->timestamps();
        });
        Schema::create('vehicle_ad', function (Blueprint $t) {
            $t->string('vehicle_id')->primary();
            $t->string('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('vehicle_number')->nullable();
            $t->string('imei')->nullable();
            $t->timestamps();
        });

        Http::fake([
            self::GATEWAY . '/command' => Http::response(['ok' => true], 200),
            self::GATEWAY . '/*'       => Http::response(['connected_devices' => []], 200),
            '*'                        => Http::response([], 200),
        ]);
    }

    private function login(string $role, ?int $dealerId = null): Admin
    {
        $a = Admin::forceCreate([
            'admin_id' => strtolower($role) . '-1', 'username' => strtolower($role), 'full_name' => ucfirst(strtolower($role)) . ' Person',
            'role' => $role, 'status' => 'ACTIVE', 'dealer_id' => $dealerId,
        ]);
        $this->actingAs($a);

        return $a;
    }

    /** A dealer whose lead matches an app customer who owns a vehicle with $imei. */
    private function dealerWithVehicle(string $imei, string $email = 'lead@example.com'): Dealer
    {
        $dealer = Dealer::create(['full_name' => 'Kasun']);
        DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => 'Lead', 'email' => $email, 'contact' => null]);
        CustomerAd::forceCreate(['customer_id' => 'c-own', 'email' => $email, 'phone_number' => null]);
        VehicleAd::forceCreate(['vehicle_id' => 'v-own', 'customer_id' => 'c-own', 'vehicle_number' => 'CAB-1', 'imei' => $imei]);

        return $dealer;
    }

    private function someoneElsesVehicle(string $imei): void
    {
        CustomerAd::forceCreate(['customer_id' => 'c-other', 'email' => 'other@example.com', 'phone_number' => '0771234567']);
        VehicleAd::forceCreate(['vehicle_id' => 'v-other', 'customer_id' => 'c-other', 'vehicle_number' => 'XYZ-9', 'imei' => $imei]);
    }

    private function gatewayCommandsSent(): int
    {
        return Http::recorded(fn (HttpRequest $r) => $r->url() === self::GATEWAY . '/command')->count();
    }

    // ---------------------------------------------------------------- views

    public function test_sidebar_linked_admin_pages_render_instead_of_500(): void
    {
        $this->login('ADMIN');

        foreach (['admin.activation-report', 'admin.customer-document-upload', 'admin.troubleshoot', 'admin.feedback', 'admin.device-replace-request'] as $name) {
            $this->get(route($name))->assertOk();
        }
    }

    public function test_every_role_can_open_its_profile_and_dashboard(): void
    {
        $this->login('ADMIN');
        $this->get(route('admin.profile'))->assertOk()->assertSee('Admin Person');

        foreach (['FINANCE' => ['finance.profile', 'finance.dashboard'], 'TECHNICIAN' => ['technician.profile', 'technician.dashboard']] as $role => [$profile, $dash]) {
            $this->login($role);
            $this->get(route($profile))->assertOk()->assertSee(ucfirst(strtolower($role)) . ' Person');
            $r = $this->get(route($dash))->assertOk();
            $r->assertSee('no ' . ucfirst(strtolower($role)) . ' dashboard', false);
            // These roles must never be shown admin navigation.
            $r->assertDontSee('Stock Transfer')->assertDontSee('Activation Reports');
            $r->assertSee('Logout');
        }
    }

    // ------------------------------------------------------- registration

    public function test_public_registration_is_gone(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'])->assertNotFound();
    }

    // ------------------------------------------------ command authorization

    public function test_dealer_cannot_command_a_device_they_do_not_own(): void
    {
        $dealer = $this->dealerWithVehicle('868159941856606');
        $this->someoneElsesVehicle('864876170981409');
        $this->login('DEALER', $dealer->id);

        $this->postJson(route('dealer.device-commands.send'), ['imei' => '864876170981409', 'command' => 'relay_on'])
            ->assertForbidden()->assertJson(['success' => false]);

        $this->assertSame(0, $this->gatewayCommandsSent(), 'The gateway must never be called for a device the dealer does not own.');
    }

    public function test_dealer_can_command_their_own_device_and_the_gateway_is_called(): void
    {
        $dealer = $this->dealerWithVehicle('868159941856606');
        $this->login('DEALER', $dealer->id);

        $this->postJson(route('dealer.device-commands.send'), ['imei' => '868159941856606', 'command' => 'status'])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, $this->gatewayCommandsSent());
    }

    public function test_the_admin_route_is_not_a_back_door_for_dealers(): void
    {
        $dealer = $this->dealerWithVehicle('868159941856606');
        $this->someoneElsesVehicle('864876170981409');
        $this->login('DEALER', $dealer->id);

        $this->postJson(route('admin.vehicles.device-commands.send'), ['imei' => '864876170981409', 'command' => 'reset'])
            ->assertForbidden();
        $this->assertSame(0, $this->gatewayCommandsSent());
    }

    public function test_admin_can_command_any_device(): void
    {
        $this->someoneElsesVehicle('864876170981409');
        $this->login('ADMIN');

        $this->postJson(route('admin.vehicles.device-commands.send'), ['imei' => '864876170981409', 'command' => 'status'])
            ->assertOk()->assertJson(['success' => true]);
    }

    public function test_other_roles_cannot_command_devices_at_all(): void
    {
        $this->someoneElsesVehicle('864876170981409');

        foreach (['FINANCE', 'TECHNICIAN', 'SUPPLIER'] as $role) {
            $this->login($role);
            $this->postJson(route('dealer.device-commands.send'), ['imei' => '864876170981409', 'command' => 'status'])->assertForbidden();
        }
        $this->assertSame(0, $this->gatewayCommandsSent());
    }

    public function test_dealer_with_no_customers_sees_and_controls_nothing(): void
    {
        // Used to match EVERY customer (empty where-group), exposing all vehicles.
        $dealer = Dealer::create(['full_name' => 'No Leads']);
        $this->someoneElsesVehicle('864876170981409');
        $this->login('DEALER', $dealer->id);

        $this->postJson(route('dealer.device-commands.send'), ['imei' => '864876170981409', 'command' => 'status'])->assertForbidden();
        $this->get(route('dealer.device-commands'))->assertOk()->assertDontSee('XYZ-9');
        $this->get(route('dealer.gps-tracking'))->assertOk()->assertDontSee('XYZ-9');
    }

    public function test_a_junk_lead_phone_does_not_match_every_customer(): void
    {
        // "n/a" -> "" -> LIKE '%' used to match all customers.
        $dealer = Dealer::create(['full_name' => 'Junk Phone']);
        DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => 'Lead', 'email' => null, 'contact' => 'n/a']);
        $this->someoneElsesVehicle('864876170981409');
        $this->login('DEALER', $dealer->id);

        $this->postJson(route('dealer.device-commands.send'), ['imei' => '864876170981409', 'command' => 'status'])->assertForbidden();
    }

    public function test_history_and_status_are_ownership_checked_too(): void
    {
        $dealer = $this->dealerWithVehicle('868159941856606');
        $this->someoneElsesVehicle('864876170981409');
        $this->login('DEALER', $dealer->id);

        $this->getJson(route('dealer.device-commands.history', 'v-other'))->assertForbidden();
        $this->getJson(route('dealer.device-commands.status', '864876170981409'))->assertForbidden();

        $this->getJson(route('dealer.device-commands.history', 'v-own'))->assertOk();
        $this->getJson(route('dealer.device-commands.status', '868159941856606'))->assertOk();
    }



    // ------------------------------------------- dealer stock == assignable stock

    public function test_dealer_available_stock_only_lists_devices_the_dealer_can_assign(): void
    {
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('device_category')->nullable();
            $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable();
            $t->string('status')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('assigned_customer_id')->nullable();
            $t->timestamps();
        });

        $mk = fn (string $imei, string $status, ?int $dealer = 7, ?int $cust = null) => SetupShalotrackDevice::forceCreate([
            'imei_number' => $imei, 'status' => $status, 'dealer_id' => $dealer, 'assigned_customer_id' => $cust,
        ]);

        $mk('100000000000001', 'Not Activated');                 // assignable
        $mk('100000000000002', 'Not Activated', 7, 0);           // assignable (0 == none)
        $mk('100000000000003', 'Activated');                     // admin already activated it: NOT assignable
        $mk('100000000000004', 'Temporarily Stopped');           // NOT assignable
        $mk('100000000000005', 'Pending Repair');                // own tab
        $mk('100000000000006', 'Broken Device');                 // own tab
        $mk('100000000000007', 'Assigned to Customer', 7, 5);    // already sold
        $mk('100000000000008', 'Not Activated', 7, 5);           // already bound to a customer
        $mk('100000000000009', 'Not Activated', 8);              // another dealer's
        $mk('100000000000010', 'Not Activated', null);           // company stock

        $imeis = SetupShalotrackDevice::availableForDealer(7)->orderBy('imei_number')->pluck('imei_number')->all();

        $this->assertSame(['100000000000001', '100000000000002'], $imeis);
    }

    public function test_every_dealer_stock_query_uses_the_assignable_scope(): void
    {
        $src = file_get_contents(base_path('app/Http/Controllers/Admin/Dealer/DealerDashboardController.php'));

        $this->assertSame(3, substr_count($src, 'availableForDealer('), 'dashboard list, stock count and customer-list must all use the scope');
        $this->assertStringNotContainsString('dealerSideValues', $src, 'a status blacklist lets Activated devices into dealer stock');
    }


    public function test_cancel_device_dealer_control_is_locked_unless_reactivating(): void
    {
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('device_category')->nullable();
            $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable();
            $t->string('status')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('canceled_date')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->timestamps();
        });
        $this->login('ADMIN');
        $a = SetupShalotrackDevice::create(['device_category' => 'V5', 'imei_number' => '100000000000011', 'status' => 'Activated']);
        $b = SetupShalotrackDevice::create(['device_category' => 'V5', 'imei_number' => '100000000000012', 'status' => 'Temporarily Stopped']);

        $html = $this->get(route('admin.cancel_device.index'))->assertOk()->getContent();

        foreach ([$a, $b] as $d) {
            $this->assertMatchesRegularExpression('/<select name="dealer_id" form="form-' . $d->shdevice_id . '"\s+disabled/', $html);
        }
        // Only the stopped row can unlock it, by choosing Reactivate.
        $this->assertSame(1, substr_count($html, "select[name=dealer_id]').disabled"));
    }

    // ------------------------------------------------ nothing public by accident

    public function test_supplier_product_lists_are_not_publicly_readable(): void
    {
        // These two used to be registered with no auth at all.
        $this->get('/admin/get-supplier-products/1')->assertNotFound();
        $this->get('/api/supplier-products-list/1')->assertNotFound();
    }

    public function test_every_route_requires_login_unless_deliberately_public(): void
    {
        $publicByDesign = ['/', 'up', 'sanctum/csrf-cookie', 'storage/{path}'];
        $router = app('router');
        $offenders = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $mw = implode(' ', $router->gatherRouteMiddleware($route));
            $guarded = str_contains($mw, 'Authenticate')            // auth
                    || str_contains($mw, 'RedirectIfAuthenticated') // guest-only pages (login, forgot password)
                    || str_contains($mw, 'VerifyAdminSyncKey');     // server-to-server, shared secret

            if (!$guarded && !in_array($route->uri(), $publicByDesign, true)) {
                $offenders[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $offenders, "Public routes that are not on the allow-list:\n" . implode("\n", $offenders));
    }

    // ------------------------------------------------- page-level behaviour

    public function test_notification_polling_is_15s_and_20s_not_1s_and_2s(): void
    {
        foreach (['resources/views/partials/sidebars/admin.blade.php', 'resources/views/layouts/dealer.blade.php'] as $f) {
            $src = file_get_contents(base_path($f));
            $this->assertStringContainsString('15000', $src, $f);
            $this->assertStringContainsString('20000', $src, $f);
            $this->assertDoesNotMatchRegularExpression('/\}\s*,\s*(1000|2000)\s*\)\s*;/', $src, "$f still polls every 1-2 seconds");
            $this->assertStringContainsString('document.hidden', $src, "$f should pause polling in a hidden tab");
            $this->assertStringContainsString('res.ok', $src, "$f should treat non-2xx as failure");
        }
    }

    public function test_command_center_reports_failures_instead_of_pretending(): void
    {
        foreach (['resources/views/admin/vehicles/device_command_center.blade.php', 'resources/views/dealer/device_command_center.blade.php'] as $f) {
            $src = file_get_contents(base_path($f));
            $this->assertStringContainsString('id="history-error"', $src, $f);
            $this->assertStringContainsString('httpFailureText', $src, $f);
            $this->assertStringContainsString("escapeHtml(item.command", $src, "$f must escape the command text");
        }
    }
}
