<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DeviceType;
use App\Models\SetupShalotrackDevice;
use App\Models\Stock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Dead-end fixes: (1) a failed API sync is reported to the admin instead of
 * being hidden behind a green "success", (2) activating a device that is no
 * longer activatable says why. Own minimal schema (the full migration history
 * can't build a fresh sqlite database).
 */
class DeviceSyncAndActivationMessagesTest extends TestCase
{
    private const IMEI = '868159941856606';

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
        Schema::create('device_types', function (Blueprint $t) {
            $t->id();
            $t->string('device_category');
            $t->string('model');
            $t->string('protocol')->nullable();
            $t->text('features')->nullable();
            $t->timestamps();
        });
        Schema::create('stocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_type_id');
            $t->string('device_category_type')->nullable();
            $t->integer('company_available_stock')->default(0);
            $t->timestamps();
        });
        Schema::create('sims', function (Blueprint $t) {
            $t->id();
            $t->string('sim_number', 10)->unique();
            $t->string('sim_type');
            $t->string('imsi', 15)->unique();
            $t->string('iccid', 20)->unique();
            $t->boolean('activation_required')->default(false);
            $t->string('sim_status')->default('Not Activated');
            $t->timestamps();
        });
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('device_category');
            $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable();
            $t->string('iccid', 20)->nullable()->unique();
            $t->string('imsi', 15)->nullable();
            $t->string('registered_by')->nullable();
            $t->string('intake_source', 16)->nullable();
            $t->string('status')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('canceled_date')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->timestamp('allocated_at')->nullable();
            $t->unsignedBigInteger('transfer_id')->nullable();
            $t->timestamps();
        });
        Schema::create('vehicle_ad', function (Blueprint $t) {
            $t->string('vehicle_id')->primary();
            $t->string('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('vehicle_number')->nullable();
            $t->string('chassis_number')->nullable();
            $t->string('engine_number')->nullable();
            $t->string('make')->nullable();
            $t->string('model')->nullable();
            $t->integer('year')->nullable();
            $t->string('color')->nullable();
            $t->string('vehicle_type')->nullable();
            $t->string('fuel_type')->nullable();
            $t->boolean('has_gps_device')->default(false);
            $t->string('imei')->nullable();
            $t->timestamp('last_synced_at')->nullable();
            $t->timestamps();
        });
        Schema::create('activated_devices', function (Blueprint $t) {
            $t->id('activated_device_id');
            $t->string('vehicle_id')->unique();
            $t->string('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('vehicle_number')->nullable();
            $t->string('model')->nullable();
            $t->boolean('has_gps_device')->default(false);
            $t->string('imei_number')->nullable();
            $t->string('sim_number')->nullable();
            $t->string('device_category')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('subscription_model')->nullable();
            $t->date('subscription_start_date')->nullable();
            $t->timestamp('subscription_end_date')->nullable();
            $t->string('bank_invoice')->nullable();
            $t->string('bank_slip')->nullable();
            $t->string('status')->nullable();
            $t->timestamps();
        });

        $this->actingAs(Admin::forceCreate([
            'admin_id' => 'adm-1', 'username' => 'a', 'role' => 'ADMIN', 'status' => 'ACTIVE',
        ]));
    }

    private function type(): DeviceType
    {
        $type = DeviceType::create(['device_category' => 'Shalotrack', 'model' => 'V5']);
        Stock::create(['device_type_id' => $type->id, 'company_available_stock' => 5]);

        return $type;
    }

    private function device(string $status): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create([
            'device_category' => 'V5 Basic', 'imei_number' => self::IMEI, 'status' => $status,
        ]);
    }

    public function test_manual_setup_warns_when_api_sync_fails_but_still_saves(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
        $type = $this->type();

        $this->post(route('admin.device.store'), ['device_type_id' => $type->id, 'imei_number' => self::IMEI])
            ->assertRedirect(route('admin.setup-device'))
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        $this->assertSame(1, SetupShalotrackDevice::count(), 'device must still be saved');
        $this->assertSame(4, Stock::first()->company_available_stock);
    }

    public function test_manual_setup_has_no_warning_when_sync_succeeds(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $type = $this->type();

        $this->post(route('admin.device.store'), ['device_type_id' => $type->id, 'imei_number' => self::IMEI])
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');
    }

    public function test_scan_commit_reports_how_many_devices_failed_to_sync(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]);
        $type = $this->type();

        $this->postJson(route('admin.device.scan.commit'), [
            'device_type_id' => $type->id, 'rows' => [['imei' => self::IMEI]],
        ])->assertOk()->assertJsonPath('created', 1)->assertJsonPath('sync_failed', 1);
    }

    public function test_scan_commit_reports_zero_sync_failures_on_success(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $type = $this->type();

        $this->postJson(route('admin.device.scan.commit'), [
            'device_type_id' => $type->id, 'rows' => [['imei' => self::IMEI]],
        ])->assertJsonPath('sync_failed', 0);
    }

    public function test_cancel_device_status_change_warns_when_api_sync_fails(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $device = $this->device('Not Activated');

        $this->patch(route('admin.cancel_device.update', $device->shdevice_id), ['status' => 'Activated'])
            ->assertRedirect(route('admin.cancel_device.index'))
            ->assertSessionHas('success')
            ->assertSessionHas('warning');

        $this->assertSame('Activated', $device->fresh()->status);
    }

    public function test_cancel_device_status_change_has_no_warning_when_sync_succeeds(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $device = $this->device('Not Activated');

        $this->patch(route('admin.cancel_device.update', $device->shdevice_id), ['status' => 'Activated'])
            ->assertSessionHas('success')
            ->assertSessionMissing('warning');
    }

    public function test_activating_a_device_that_is_no_longer_available_explains_why(): void
    {
        Http::fake();
        $this->device('Assigned to Customer');   // e.g. a dealer already assigned it
        \App\Models\VehicleAd::forceCreate(['vehicle_id' => 'veh-1', 'customer_name' => 'A', 'vehicle_number' => 'WP 1']);

        $response = $this->post(route('admin.customer-device-management.activate', 'veh-1'), [
            'payment_status' => 'not-Paid',
            'imei_number'    => self::IMEI,
            'sim_number'     => '0700000101',
            'device_category' => 'V5 Basic',
        ]);

        $response->assertSessionHasErrors('imei_number');
        $this->assertStringContainsString(
            'no longer available to activate',
            session('errors')->first('imei_number')
        );
        $this->assertSame(0, \App\Models\ActivatedDevice::count());
        $this->assertSame('Assigned to Customer', SetupShalotrackDevice::first()->status);
    }

    public function test_activating_an_available_device_still_works(): void
    {
        Http::fake();
        $this->device('Not Activated');
        \App\Models\VehicleAd::forceCreate(['vehicle_id' => 'veh-1', 'customer_name' => 'A', 'vehicle_number' => 'WP 1']);

        $this->post(route('admin.customer-device-management.activate', 'veh-1'), [
            'payment_status' => 'not-Paid',
            'imei_number'    => self::IMEI,
            'sim_number'     => '0700000101',
            'device_category' => 'V5 Basic',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('Activated', SetupShalotrackDevice::first()->status);
        $this->assertSame(1, \App\Models\ActivatedDevice::count());
    }
}
