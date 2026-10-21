<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\ActivatedDevice;
use App\Models\Dealer;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Admin > Customer Device Management > activate a device on a customer vehicle
 * (POST admin.customer-device-management.activate).
 * Covers the unpaid activation path and the guard rules. The "Paid" path also writes payment and
 * commission rows, so it gets its own test file once the renewal packages are seeded in autotest.
 * Rolled back after every test; assertions use only the tagged rows created here.
 */
class DeviceActivationTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    private function makeVehicle(string $n): VehicleAd
    {
        return VehicleAd::create([
            'vehicle_id'     => (string) Str::uuid(),
            'customer_id'    => (string) Str::uuid(),
            'customer_name'  => 'Autotest Customer ' . $n,
            'vehicle_number' => 'AUTO-' . $n,
            'model'          => 'Autotest Model',
            'has_gps_device' => false,
        ]);
    }

    private function makeDevice(string $n, ?string $status = null, ?int $dealerId = null): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create([
            'device_category' => 'AUTOTEST-CAT',
            'imei_number'     => '99900000000' . $n,
            'sim_number'      => '07000000' . $n,
            'iccid'           => '8994000000000' . $n,
            'status'          => $status ?? DeviceStatus::Activated->value,
            'dealer_id'       => $dealerId,
        ]);
    }

    private function payload(SetupShalotrackDevice $device, array $overrides = []): array
    {
        return array_merge([
            'imei_number'     => $device->imei_number,
            'sim_number'      => $device->sim_number,
            'device_category' => $device->device_category,
            'payment_status'  => 'not-Paid',
        ], $overrides);
    }

    public function test_admin_can_activate_an_unpaid_device_on_a_vehicle(): void
    {
        $admin   = $this->makeAdmin('ADMIN');
        $vehicle = $this->makeVehicle('01');
        $device  = $this->makeDevice('01');

        $this->actingAs($admin)
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($device))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $bound = ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->first();
        $this->assertNotNull($bound, 'activated_devices row was not created');
        $this->assertSame($device->imei_number, $bound->imei_number);
        $this->assertSame('not-Paid', $bound->payment_status);
        $this->assertNotNull($bound->original_activated_at);
        $this->assertSame(DeviceStatus::Activated->value, $device->refresh()->status);
    }

    public function test_a_vehicle_that_already_has_a_device_cannot_be_activated_twice(): void
    {
        $admin   = $this->makeAdmin('ADMIN');
        $vehicle = $this->makeVehicle('02');
        $first   = $this->makeDevice('02');
        $second  = $this->makeDevice('03');

        $this->actingAs($admin)
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($first))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($second))
            ->assertSessionHasErrors('vehicle');

        $this->assertSame(1, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_a_device_that_is_not_activated_yet_cannot_be_bound(): void
    {
        $admin   = $this->makeAdmin('ADMIN');
        $vehicle = $this->makeVehicle('04');
        $device  = $this->makeDevice('04', DeviceStatus::NotActivated->value);

        $this->actingAs($admin)
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($device))
            ->assertSessionHasErrors('imei_number');

        $this->assertSame(0, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_a_dealers_unsold_stock_cannot_be_bound_by_the_admin(): void
    {
        $admin   = $this->makeAdmin('ADMIN');
        $vehicle = $this->makeVehicle('05');
        $dealer  = Dealer::create([
            'full_name'     => 'Autotest Dealer act',
            'dealer_status' => 'distributor',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => 'autotest+act@example.test',
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
        $device = $this->makeDevice('05', null, $dealer->id);

        $this->actingAs($admin)
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($device))
            ->assertSessionHasErrors('imei_number');

        $this->assertSame(0, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_paid_activation_requires_model_start_date_and_invoice(): void
    {
        $admin   = $this->makeAdmin('ADMIN');
        $vehicle = $this->makeVehicle('06');
        $device  = $this->makeDevice('06');

        $this->actingAs($admin)
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($device, ['payment_status' => 'Paid']))
            ->assertSessionHasErrors(['subscription_model', 'subscription_start_date', 'bank_invoice']);

        $this->assertSame(0, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_install_date_in_the_future_is_rejected(): void
    {
        $admin   = $this->makeAdmin('ADMIN');
        $vehicle = $this->makeVehicle('07');
        $device  = $this->makeDevice('07');

        $this->actingAs($admin)
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($device, [
                'installed_on' => now()->addDays(5)->toDateString(),
            ]))
            ->assertSessionHasErrors('installed_on');

        $this->assertSame(0, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->count());
    }

    public function test_a_dealer_login_cannot_activate_devices(): void
    {
        $dealerLogin = $this->makeAdmin('DEALER');
        $vehicle     = $this->makeVehicle('08');
        $device      = $this->makeDevice('08');

        $status = $this->actingAs($dealerLogin)
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->payload($device))
            ->getStatusCode();

        $this->assertContains($status, [302, 403]);
        $this->assertSame(0, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->count());
    }
}