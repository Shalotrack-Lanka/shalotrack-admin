<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\Admin;
use App\Models\ActivatedDevice;
use App\Models\CommissionEntry;
use App\Models\Dealer;
use App\Models\ExpiredDevice;
use App\Models\PackagePayment;
use App\Models\RenewalPackage;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Renewing, correcting and reactivating a device subscription.
 *   PATCH admin.customer-device-management.update       renew or correct a bound device
 *   POST  admin.customer-device-management.reactivate   bring back a legacy expired device
 *
 * The device is first bound with the normal paid activation (already covered by PaidActivationTest), then
 * edited. Every row is rolled back by DatabaseTransactions; assertions look only at the rows created here
 * (IMEI prefix 99900000 2xx).
 */
class RenewalAndReactivationTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const START = '2026-10-01';

    /** One admin per test: a second admin mid-test would log the session out. */
    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function oneYearPackage(): RenewalPackage
    {
        return RenewalPackage::updateOrCreate(['model' => '1 Year'], [
            'code' => 'ONE_YEAR', 'label' => '1 Year', 'months' => 12,
            'customer_price' => 2999, 'company_margin' => 2199,
            'distributor_margin' => 300, 'retailer_margin' => 500,
            'warranty_months' => 12, 'is_active' => true,
        ]);
    }

    private function makeRetailer(string $suffix): Dealer
    {
        return Dealer::create([
            'full_name'     => 'Autotest Renew ' . $suffix,
            'dealer_status' => 'retailer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+renew-{$suffix}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    private function makeVehicle(string $n): VehicleAd
    {
        return VehicleAd::create([
            'vehicle_id'     => (string) Str::uuid(),
            'customer_id'    => (string) Str::uuid(),
            'customer_name'  => 'Autotest Customer R' . $n,
            'vehicle_number' => 'AUTO-R' . $n,
            'model'          => 'Autotest Model',
            'has_gps_device' => false,
        ]);
    }

    private function makeDevice(string $n, ?int $soldByDealerId = null): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create([
            'device_category' => 'AUTOTEST-CAT',
            'imei_number'     => '99900000' . $n,
            'sim_number'      => '07100000' . $n,
            'iccid'           => '8994100000' . $n,
            'status'          => $soldByDealerId ? DeviceStatus::AssignedToCustomer->value : DeviceStatus::Activated->value,
            'dealer_id'       => $soldByDealerId,
        ]);
    }

    private function paidPayload(SetupShalotrackDevice $device, string $invoice, string $start = self::START): array
    {
        return [
            'imei_number'             => $device->imei_number,
            'sim_number'              => $device->sim_number,
            'device_category'         => $device->device_category,
            'payment_status'          => 'Paid',
            'subscription_model'      => '1 Year',
            'subscription_start_date' => $start,
            'bank_invoice'            => $invoice,
        ];
    }

    /** Bind a device to a vehicle with a paid 1 Year package, through the real activation endpoint. */
    private function activate(VehicleAd $vehicle, SetupShalotrackDevice $device, string $invoice): ActivatedDevice
    {
        $this->actingAs($this->admin())
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->paidPayload($device, $invoice))
            ->assertSessionHasNoErrors();

        return ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->firstOrFail();
    }

    private function edit(ActivatedDevice $bound, array $payload)
    {
        return $this->actingAs($this->admin())
            ->patch(route('admin.customer-device-management.update', $bound->activated_device_id), $payload);
    }

    public function test_renewing_a_running_subscription_starts_the_new_period_at_the_current_expiry(): void
    {
        $this->oneYearPackage();
        $retailer = $this->makeRetailer('a');
        $vehicle  = $this->makeVehicle('01');
        $device   = $this->makeDevice('201', $retailer->id);

        $bound     = $this->activate($vehicle, $device, 'AUTOTEST-INV-R1');
        $endBefore = Carbon::parse($bound->subscription_end_date);
        $this->assertTrue($endBefore->isFuture(), 'the first period must still be running for this test');

        // A new payment whose start date falls inside the days already paid for.
        $response = $this->edit($bound, $this->paidPayload($device, 'AUTOTEST-INV-R2', '2026-10-05'));

        $response->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertStringContainsString('keeps the days', (string) session('success'));

        $fresh = $bound->fresh();
        $this->assertSame($endBefore->format('Y-m-d'), Carbon::parse($fresh->subscription_start_date)->format('Y-m-d'), 'new period must start at the old expiry');
        $this->assertSame('AUTOTEST-INV-R2', $fresh->bank_invoice);
    }

    public function test_a_renewal_adds_a_second_payment_and_keeps_the_first(): void
    {
        $this->oneYearPackage();
        $retailer = $this->makeRetailer('b');
        $vehicle  = $this->makeVehicle('02');
        $device   = $this->makeDevice('202', $retailer->id);

        $bound = $this->activate($vehicle, $device, 'AUTOTEST-INV-R3');
        $this->edit($bound, $this->paidPayload($device, 'AUTOTEST-INV-R4', '2026-10-05'))->assertSessionHasNoErrors();

        $payments = PackagePayment::where('imei_number', $device->imei_number)->get();
        $this->assertCount(2, $payments);
        $this->assertSame(2, $payments->where('status', 'valid')->count(), 'both payments stand: a renewal is not a correction');

        $this->assertSame(2, CommissionEntry::where('dealer_id', $retailer->id)->where('entry_type', 'earned')->count());
        $this->assertSame(0, CommissionEntry::where('dealer_id', $retailer->id)->where('entry_type', 'clawback')->count());
    }

    public function test_correcting_the_invoice_of_the_same_period_reverses_the_old_payment(): void
    {
        $this->oneYearPackage();
        $retailer = $this->makeRetailer('c');
        $vehicle  = $this->makeVehicle('03');
        $device   = $this->makeDevice('203', $retailer->id);

        $bound = $this->activate($vehicle, $device, 'AUTOTEST-INV-R5');

        // Same start date, different invoice number: a typo fix, not a new period.
        $this->edit($bound, $this->paidPayload($device, 'AUTOTEST-INV-R5-FIXED'))->assertSessionHasNoErrors();

        $payments = PackagePayment::where('imei_number', $device->imei_number)->get();
        $this->assertCount(2, $payments);
        $this->assertSame(1, $payments->where('status', 'valid')->count());
        $this->assertSame(1, $payments->where('status', 'reversed')->count());

        // Earned 500, clawed back 500, earned again 500: the retailer is owed exactly one payment.
        $net = (float) DB::table('commission_entries')->where('dealer_id', $retailer->id)->sum('amount');
        $this->assertSame(500.0, $net);
    }

    public function test_the_imei_of_a_bound_device_cannot_be_changed(): void
    {
        $this->oneYearPackage();
        $vehicle = $this->makeVehicle('04');
        $device  = $this->makeDevice('204');
        $other   = $this->makeDevice('205');

        $bound = $this->activate($vehicle, $device, 'AUTOTEST-INV-R6');

        $payload = $this->paidPayload($device, 'AUTOTEST-INV-R6');
        $payload['imei_number'] = $other->imei_number;

        $this->edit($bound, $payload)->assertSessionHasErrors('imei_number');

        $this->assertSame($device->imei_number, $bound->fresh()->imei_number);
    }

    public function test_an_edit_cannot_reuse_the_invoice_of_another_device(): void
    {
        $this->oneYearPackage();
        $v1 = $this->makeVehicle('05');
        $v2 = $this->makeVehicle('06');
        $d1 = $this->makeDevice('206');
        $d2 = $this->makeDevice('207');

        $this->activate($v1, $d1, 'AUTOTEST-INV-R7');
        $second = $this->activate($v2, $d2, 'AUTOTEST-INV-R8');

        $this->edit($second, $this->paidPayload($d2, 'AUTOTEST-INV-R7'))->assertSessionHasErrors('bank_invoice');

        $this->assertSame('AUTOTEST-INV-R8', $second->fresh()->bank_invoice, 'the device keeps its own invoice');
    }

    public function test_reactivating_a_legacy_expired_device_binds_it_again_and_records_the_payment(): void
    {
        $this->oneYearPackage();
        $vehicle = $this->makeVehicle('07');
        $device  = $this->makeDevice('208');

        $expired = ExpiredDevice::create([
            'vehicle_id'      => $vehicle->vehicle_id,
            'customer_id'     => $vehicle->customer_id,
            'customer_name'   => $vehicle->customer_name,
            'vehicle_number'  => $vehicle->vehicle_number,
            'model'           => $vehicle->model,
            'has_gps_device'  => false,
            'imei_number'     => $device->imei_number,
            'sim_number'      => $device->sim_number,
            'device_category' => $device->device_category,
            'payment_status'  => 'not-Paid',
            'status'          => 'Expired',
            'expired_date'    => now(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.customer-device-management.reactivate', $expired->expired_device_id), [
                'payment_status'          => 'Paid',
                'subscription_model'      => '1 Year',
                'subscription_start_date' => self::START,
                'bank_invoice'            => 'AUTOTEST-INV-R9',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $bound = ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->first();
        $this->assertNotNull($bound, 'the device must be bound to the vehicle again');
        $this->assertSame($device->imei_number, $bound->imei_number);
        $this->assertSame('Paid', $bound->payment_status);

        $payment = PackagePayment::where('imei_number', $device->imei_number)->first();
        $this->assertNotNull($payment, 'payment row missing');
        $this->assertSame('reactivation', $payment->source);
        $this->assertSame('valid', $payment->status);

        $this->assertSame(0, ExpiredDevice::where('expired_device_id', $expired->expired_device_id)->count(), 'the legacy expired row is removed');
    }

    public function test_reactivate_is_refused_when_the_vehicle_already_has_a_device(): void
    {
        $this->oneYearPackage();
        $vehicle = $this->makeVehicle('08');
        $device  = $this->makeDevice('209');

        $this->activate($vehicle, $device, 'AUTOTEST-INV-R10');

        $expired = ExpiredDevice::create([
            'vehicle_id'      => $vehicle->vehicle_id,
            'customer_id'     => $vehicle->customer_id,
            'customer_name'   => $vehicle->customer_name,
            'vehicle_number'  => $vehicle->vehicle_number,
            'imei_number'     => '99900000210',
            'payment_status'  => 'not-Paid',
            'status'          => 'Expired',
            'expired_date'    => now(),
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.customer-device-management.reactivate', $expired->expired_device_id), [
                'payment_status'          => 'Paid',
                'subscription_model'      => '1 Year',
                'subscription_start_date' => self::START,
                'bank_invoice'            => 'AUTOTEST-INV-R11',
            ])
            ->assertSessionHasErrors('vehicle');

        $this->assertSame(1, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->count(), 'the vehicle must not be double-bound');
        $this->assertSame(1, ExpiredDevice::where('expired_device_id', $expired->expired_device_id)->count());
        $this->assertSame(0, PackagePayment::where('bank_invoice', 'AUTOTEST-INV-R11')->count());
    }
}