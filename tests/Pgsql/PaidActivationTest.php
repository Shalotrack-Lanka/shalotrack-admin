<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\Admin;
use App\Models\ActivatedDevice;
use App\Models\CommissionEntry;
use App\Models\Dealer;
use App\Models\PackagePayment;
use App\Models\RenewalPackage;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Paid activation: activate a device with a paid subscription package.
 * POST admin.customer-device-management.activate  ->  activated_devices + package_payments + commission_entries.
 *
 * The price master (renewal_packages) is empty in "autotest", so each test creates the package it needs
 * with the same prices as the live master (1 Year: 2999 customer / 2199 company / 300 distributor / 500 retailer).
 * Rolled back after every test; assertions use only the tagged rows created here (IMEI prefix 99900000000).
 */
class PaidActivationTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const START = '2026-10-01';

    /** One admin per test: a second admin mid-test has a different password hash and AuthenticateSession logs the session out. */
    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
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

    private function trialPackageWithoutPrice(): RenewalPackage
    {
        return RenewalPackage::updateOrCreate(['model' => '3 Months'], [
            'code' => 'THREE_MONTHS', 'label' => '3 Months', 'months' => 3,
            'customer_price' => null, 'company_margin' => null,
            'distributor_margin' => null, 'retailer_margin' => null,
            'warranty_months' => 0, 'is_active' => true,
        ]);
    }

    private function makeDealer(string $suffix, string $status = 'dealer', ?int $distributorId = null): Dealer
    {
        return Dealer::create([
            'full_name'      => 'Autotest ' . $suffix,
            'dealer_status'  => $status,
            'distributor_id' => $distributorId,
            'region'         => 'colombo',
            'country'        => 'Sri Lanka',
            'contact_email'  => "autotest+paid-{$suffix}@example.test",
            'status'         => 'active',
            'created_by'     => 'autotest',
        ]);
    }

    private function makeVehicle(string $n, ?string $customerId = null): VehicleAd
    {
        return VehicleAd::create([
            'vehicle_id'     => (string) Str::uuid(),
            'customer_id'    => $customerId ?? (string) Str::uuid(),
            'customer_name'  => 'Autotest Customer ' . $n,
            'vehicle_number' => 'AUTO-P' . $n,
            'model'          => 'Autotest Model',
            'has_gps_device' => false,
        ]);
    }

    /** A device the admin may bind: sold by a dealer ("Assigned to Customer") or unsold company stock. */
    private function makeDevice(string $n, ?int $soldByDealerId = null): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::create([
            'device_category' => 'AUTOTEST-CAT',
            'imei_number'     => '99900000000' . $n,
            'sim_number'      => '07000000' . $n,
            'iccid'           => '8994000000000' . $n,
            'status'          => $soldByDealerId ? DeviceStatus::AssignedToCustomer->value : DeviceStatus::Activated->value,
            'dealer_id'       => $soldByDealerId,
        ]);
    }

    private function paidPayload(SetupShalotrackDevice $device, string $invoice, string $model = '1 Year'): array
    {
        return [
            'imei_number'             => $device->imei_number,
            'sim_number'              => $device->sim_number,
            'device_category'         => $device->device_category,
            'payment_status'          => 'Paid',
            'subscription_model'      => $model,
            'subscription_start_date' => self::START,
            'bank_invoice'            => $invoice,
        ];
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function activate(VehicleAd $vehicle, array $payload)
    {
        return $this->actingAs($this->admin())
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $payload);
    }

    public function test_paid_activation_records_the_payment_and_pays_retailer_and_distributor(): void
    {
        $this->oneYearPackage();
        $distributor = $this->makeDealer('dist', 'distributor');
        $retailer    = $this->makeDealer('ret', 'dealer', $distributor->id);
        $vehicle     = $this->makeVehicle('01');
        $device      = $this->makeDevice('01', $retailer->id);

        $this->activate($vehicle, $this->paidPayload($device, 'AUTOTEST-INV-01'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $bound = ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->first();
        $this->assertNotNull($bound);
        $this->assertSame('Paid', $bound->payment_status);
        $this->assertSame('1 Year', $bound->subscription_model);
        $this->assertSame(
            Carbon::parse(self::START)->addDays(360)->format('Y-m-d'),
            Carbon::parse($bound->subscription_end_date)->format('Y-m-d'),
            '1 Year = 360 days per the spec'
        );
        $this->assertSame(12, (int) $bound->warranty_months);

        $payment = PackagePayment::where('imei_number', $device->imei_number)->first();
        $this->assertNotNull($payment, 'payment row missing');
        $this->assertSame('valid', $payment->status);
        $this->assertSame('activation', $payment->source);
        $this->assertSame(2999.0, (float) $payment->customer_price);
        $this->assertSame(2199.0, (float) $payment->company_margin);
        $this->assertSame($retailer->id, (int) $payment->retailer_dealer_id);
        $this->assertSame($distributor->id, (int) $payment->distributor_dealer_id);

        $earned = CommissionEntry::where('payment_id', $payment->id)->where('entry_type', 'earned')->get();
        $this->assertCount(2, $earned);
        $this->assertSame(500.0, (float) $earned->firstWhere('role', 'retailer')->amount);
        $this->assertSame(300.0, (float) $earned->firstWhere('role', 'distributor')->amount);
        $this->assertSame($retailer->id, (int) $earned->firstWhere('role', 'retailer')->dealer_id);
        $this->assertSame($distributor->id, (int) $earned->firstWhere('role', 'distributor')->dealer_id);
        $this->assertSame(
            (float) $payment->customer_price,
            (float) $payment->company_margin + (float) $payment->distributor_margin + (float) $payment->retailer_margin,
            'the price master must split the customer price exactly'
        );
    }

    public function test_a_retailer_without_a_distributor_earns_alone(): void
    {
        $this->oneYearPackage();
        $retailer = $this->makeDealer('solo');
        $vehicle  = $this->makeVehicle('02');
        $device   = $this->makeDevice('02', $retailer->id);

        $this->activate($vehicle, $this->paidPayload($device, 'AUTOTEST-INV-02'))->assertSessionHasNoErrors();

        $payment = PackagePayment::where('imei_number', $device->imei_number)->first();
        $this->assertNotNull($payment);
        $this->assertNull($payment->distributor_dealer_id);

        $earned = CommissionEntry::where('payment_id', $payment->id)->get();
        $this->assertCount(1, $earned);
        $this->assertSame('retailer', $earned->first()->role);
        $this->assertSame(500.0, (float) $earned->first()->amount);
    }

    public function test_a_distributor_link_to_a_non_distributor_earns_nothing(): void
    {
        $this->oneYearPackage();
        $notADistributor = $this->makeDealer('plain', 'dealer');
        $retailer        = $this->makeDealer('ret2', 'dealer', $notADistributor->id);
        $vehicle         = $this->makeVehicle('03');
        $device          = $this->makeDevice('03', $retailer->id);

        $this->activate($vehicle, $this->paidPayload($device, 'AUTOTEST-INV-03'))->assertSessionHasNoErrors();

        $payment = PackagePayment::where('imei_number', $device->imei_number)->first();
        $this->assertNull($payment->distributor_dealer_id);
        $this->assertSame(0, CommissionEntry::where('payment_id', $payment->id)->where('role', 'distributor')->count());
    }

    public function test_company_stock_with_no_selling_dealer_records_the_payment_but_pays_no_commission(): void
    {
        $this->oneYearPackage();
        $vehicle = $this->makeVehicle('04');
        $device  = $this->makeDevice('04');

        $this->activate($vehicle, $this->paidPayload($device, 'AUTOTEST-INV-04'))->assertSessionHasNoErrors();

        $payment = PackagePayment::where('imei_number', $device->imei_number)->first();
        $this->assertNotNull($payment, 'proof of payment must still be recorded');
        $this->assertNull($payment->retailer_dealer_id);
        $this->assertSame(0, CommissionEntry::where('payment_id', $payment->id)->count());
    }

    public function test_a_package_with_no_price_is_recorded_without_commission(): void
    {
        $this->trialPackageWithoutPrice();
        $retailer = $this->makeDealer('trial');
        $vehicle  = $this->makeVehicle('05');
        $device   = $this->makeDevice('05', $retailer->id);

        $this->activate($vehicle, $this->paidPayload($device, 'AUTOTEST-INV-05', '3 Months'))->assertSessionHasNoErrors();

        $payment = PackagePayment::where('imei_number', $device->imei_number)->first();
        $this->assertNotNull($payment);
        $this->assertNull($payment->customer_price);
        $this->assertSame(0, CommissionEntry::where('payment_id', $payment->id)->count());
    }

    public function test_the_same_bank_invoice_cannot_be_used_for_another_customer(): void
    {
        $this->oneYearPackage();
        $retailer = $this->makeDealer('inv');
        $v1 = $this->makeVehicle('06');
        $v2 = $this->makeVehicle('07');
        $d1 = $this->makeDevice('06', $retailer->id);
        $d2 = $this->makeDevice('07', $retailer->id);

        $this->activate($v1, $this->paidPayload($d1, 'AUTOTEST-INV-SHARED'))->assertSessionHasNoErrors();

        $this->activate($v2, $this->paidPayload($d2, 'AUTOTEST-INV-SHARED'))->assertSessionHasErrors('bank_invoice');

        $this->assertSame(0, ActivatedDevice::where('vehicle_id', $v2->vehicle_id)->count());
        $this->assertSame(0, PackagePayment::where('imei_number', $d2->imei_number)->count());
    }

        public function test_one_bank_invoice_cannot_cover_two_devices_even_for_the_same_owner(): void
    {
        $this->oneYearPackage();
        $retailer = $this->makeDealer('fleet');
        $owner    = (string) Str::uuid();
        $v1 = $this->makeVehicle('08', $owner);
        $v2 = $this->makeVehicle('09', $owner);
        $d1 = $this->makeDevice('08', $retailer->id);
        $d2 = $this->makeDevice('09', $retailer->id);

        $this->activate($v1, $this->paidPayload($d1, 'AUTOTEST-INV-FLEET'))->assertSessionHasNoErrors();

        $this->activate($v2, $this->paidPayload($d2, 'AUTOTEST-INV-FLEET'))->assertSessionHasErrors('bank_invoice');

        $this->assertSame(1, ActivatedDevice::where('vehicle_id', $v1->vehicle_id)->count());
        $this->assertSame(0, ActivatedDevice::where('vehicle_id', $v2->vehicle_id)->count());
        $this->assertSame(0, PackagePayment::where('imei_number', $d2->imei_number)->count());
    }

    public function test_un_marking_a_payment_reverses_it_and_claws_the_commission_back(): void
    {
        $this->oneYearPackage();
        $distributor = $this->makeDealer('rdist', 'distributor');
        $retailer    = $this->makeDealer('rret', 'dealer', $distributor->id);
        $vehicle     = $this->makeVehicle('10');
        $device      = $this->makeDevice('10', $retailer->id);
        $admin       = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->paidPayload($device, 'AUTOTEST-INV-10'))
            ->assertSessionHasNoErrors();

        $bound = ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->first();

        $this->actingAs($admin)
            ->patch(route('admin.customer-device-management.update', $bound->activated_device_id), [
                'imei_number'     => $device->imei_number,
                'sim_number'      => $device->sim_number,
                'device_category' => $device->device_category,
                'payment_status'  => 'not-Paid',
            ])
            ->assertSessionHasNoErrors();

        $payment = PackagePayment::where('imei_number', $device->imei_number)->first();
        $this->assertSame('reversed', $payment->status, 'a payment is never deleted, only marked reversed');
        $this->assertNull($payment->event_key);

        $entries = CommissionEntry::where('payment_id', $payment->id)->get();
        $this->assertCount(4, $entries, '2 earned + 2 clawback lines');
        $this->assertSame(0.0, round((float) $entries->sum('amount'), 2), 'earned and clawback must cancel out');
        $this->assertSame(2, $entries->where('entry_type', 'clawback')->count());
    }

    public function test_a_dealer_login_cannot_record_a_paid_activation(): void
    {
        $this->oneYearPackage();
        $retailer = $this->makeDealer('forb');
        $vehicle  = $this->makeVehicle('11');
        $device   = $this->makeDevice('11', $retailer->id);

        $status = $this->actingAs($this->makeAdmin('DEALER'))
            ->post(route('admin.customer-device-management.activate', $vehicle->vehicle_id), $this->paidPayload($device, 'AUTOTEST-INV-11'))
            ->getStatusCode();

        $this->assertContains($status, [302, 403]);
        $this->assertSame(0, PackagePayment::where('imei_number', $device->imei_number)->count());
        $this->assertSame(0, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->count());
    }
}