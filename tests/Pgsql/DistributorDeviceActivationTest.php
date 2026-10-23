<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\ActivatedDevice;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CommissionEntry;
use App\Models\CustomerAd;
use App\Models\Dealer;
use App\Models\DealerCustomerAd;
use App\Models\PackagePayment;
use App\Models\RenewalPackage;
use App\Models\SetupShalotrackDevice;
use App\Models\VehicleAd;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * A distributor binds devices of ITS network to customer vehicles, records the paid plan and renews it.
 *   GET/POST distributor.devices.activate     GET/POST distributor.devices.renew
 *   GET distributor.devices                   GET/POST distributor.customers
 *
 * Network = the distributor itself + the dealers whose distributor_id is that distributor. Anything outside the
 * network must look exactly like "not found" (404). The plan price, margins and dates come from the server
 * (price master, Sri Lanka today, current expiry), never from the form.
 *
 * Runs on real Postgres in the autotest schema; every row is rolled back by DatabaseTransactions.
 * Tagged rows only (IMEI 99900000 6xx, plates AUTO-...). One login per test.
 */
class DistributorDeviceActivationTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private int $n = 0;
    private int $imeiSeq = 600;
    private ?Admin $login = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

        // The price master is empty in autotest: same prices as the live master for the plans used here.
        RenewalPackage::updateOrCreate(['model' => '1 Year'], [
            'code' => 'ONE_YEAR', 'label' => '1 Year', 'months' => 12,
            'customer_price' => 2999, 'company_margin' => 2199, 'distributor_margin' => 300, 'retailer_margin' => 500,
            'warranty_months' => 12, 'is_active' => true,
        ]);
        RenewalPackage::updateOrCreate(['model' => '6 Months'], [
            'code' => 'SIX_MONTHS', 'label' => '6 Months', 'months' => 6,
            'customer_price' => 1599, 'company_margin' => 1149, 'distributor_margin' => 150, 'retailer_margin' => 300,
            'warranty_months' => 6, 'is_active' => true,
        ]);
        RenewalPackage::updateOrCreate(['model' => '3 Months'], [   // no price: cannot be sold by a distributor
            'code' => 'THREE_MONTHS', 'label' => '3 Months', 'months' => 3,
            'customer_price' => null, 'company_margin' => null, 'distributor_margin' => null, 'retailer_margin' => null,
            'warranty_months' => 0, 'is_active' => true,
        ]);
    }

    // ------------------------------------------------------------------ helpers

    private function dealer(string $name, string $type = 'retailer', ?Dealer $parent = null, string $status = 'active'): Dealer
    {
        $k = ++$this->n;

        return Dealer::create([
            'full_name'      => $name,
            'dealer_status'  => $type,
            'distributor_id' => $parent?->id,
            'region'         => 'colombo',
            'country'        => 'Sri Lanka',
            'contact_email'  => "autotest+da-{$k}-" . substr(md5((string) microtime(true)), 0, 6) . '@example.test',
            'status'         => $status,
            'created_by'     => 'autotest',
        ]);
    }

    private function distributor(string $name, string $status = 'active'): Dealer
    {
        return $this->dealer($name, 'distributor', null, $status);
    }

    /**
     * A customer of $holder as the app knows them: the app account (Customer-ad), the lead the holder registered
     * (dealer_customer_ads, matched by e-mail / phone) and one vehicle without a device.
     *
     * @return array{lead: DealerCustomerAd, vehicle: VehicleAd}
     */
    private function customerOf(Dealer $holder, string $plate, ?string $name = null): array
    {
        $k     = ++$this->n;
        $name ??= 'Autotest Customer ' . $k;
        $email = "autotest+cust-{$k}-" . substr(md5((string) microtime(true)), 0, 6) . '@example.test';
        $phone = '07' . str_pad((string) (70000000 + $k), 8, '0', STR_PAD_LEFT);

        $app = CustomerAd::create(['customer_id' => (string) Str::uuid(), 'full_name' => $name, 'email' => $email, 'phone_number' => $phone]);

        $lead = DealerCustomerAd::create([
            'dealer_id' => $holder->id, 'name' => $name, 'email' => $email, 'contact' => $phone,
            'no_of_devices' => 0, 'imei_numbers' => [],
        ]);

        $vehicle = VehicleAd::create([
            'vehicle_id' => (string) Str::uuid(), 'customer_id' => $app->customer_id, 'customer_name' => $name,
            'vehicle_number' => $plate, 'model' => 'Autotest Model', 'has_gps_device' => false,
        ]);

        return ['lead' => $lead, 'vehicle' => $vehicle];
    }

    private function device(?Dealer $holder, ?DealerCustomerAd $soldTo = null): SetupShalotrackDevice
    {
        $n = (string) $this->imeiSeq++;

        $device = SetupShalotrackDevice::create([
            'device_category' => 'AUTOTEST-CAT',
            'imei_number'     => '99900000' . $n,
            'sim_number'      => '07200000' . $n,
            'iccid'           => '8994200000' . $n,
            'status'          => $soldTo ? DeviceStatus::AssignedToCustomer->value : DeviceStatus::Activated->value,
            'dealer_id'       => $holder?->id,
        ]);

        if ($soldTo) {
            $device->forceFill(['assigned_customer_id' => $soldTo->id])->save();
        }

        return $device;
    }

    /** A device that is already bound and paid, held by $holder, with $daysLeft days left on its plan. */
    private function bound(Dealer $holder, int $daysLeft = 80, string $plan = '1 Year'): ActivatedDevice
    {
        $device = $this->device($holder);
        $k      = ++$this->n;

        return ActivatedDevice::create([
            'vehicle_id'      => (string) Str::uuid(),
            'customer_id'     => (string) Str::uuid(),
            'customer_name'   => 'Autotest Bound ' . $k,
            'vehicle_number'  => 'AUTO-B' . $k,
            'model'           => 'Autotest Model',
            'has_gps_device'  => false,
            'imei_number'     => $device->imei_number,
            'sim_number'      => $device->sim_number,
            'device_category' => $device->device_category,
            'status'          => DeviceStatus::Activated->value,
            'payment_status'  => 'Paid',
            'subscription_model'      => $plan,
            'subscription_start_date' => Carbon::parse(now('Asia/Colombo')->subDays(100)->toDateString())->startOfDay(),
            'subscription_end_date'   => Carbon::parse(now('Asia/Colombo')->addDays($daysLeft)->toDateString())->startOfDay(),
            'bank_invoice'    => 'AUTOTEST-OLD-' . $k,
            'warranty_months' => 0,
            'original_activated_at' => now()->subDays(100),
        ]);
    }

    private function asDistributor(Dealer $distributor): Admin
    {
        return $this->login = $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => $distributor->id]);
    }

    private function activatePost(SetupShalotrackDevice $device, array $over = [])
    {
        return $this->actingAs($this->login)->post(route('distributor.devices.activate.store', $device->shdevice_id), $over + [
            'plan' => '1 Year', 'bank_invoice' => 'AUTOTEST-DA-' . ++$this->n,
        ]);
    }

    private function renewPost(ActivatedDevice $row, array $over = [])
    {
        return $this->actingAs($this->login)->post(route('distributor.devices.renew.store', $row->activated_device_id), $over + [
            'plan' => '6 Months', 'bank_invoice' => 'AUTOTEST-RN-' . ++$this->n,
        ]);
    }

    private function today(): string
    {
        return now('Asia/Colombo')->toDateString();
    }

    private function plus(string $date, int $days): string
    {
        return Carbon::parse($date)->addDays($days)->format('Y-m-d');
    }

    private function boundTo(string $vehicleId): ?ActivatedDevice
    {
        return ActivatedDevice::where('vehicle_id', $vehicleId)->first();
    }

    // ------------------------------------------------------------------ activation

    public function test_a_distributor_activates_its_own_stock_for_its_own_customer_and_the_payment_is_recorded(): void
    {
        $dist = $this->distributor('Autotest Distributor A');
        ['vehicle' => $vehicle] = $this->customerOf($dist, 'AUTO-D1');
        $device = $this->device($dist);
        $login  = $this->asDistributor($dist);

        $this->activatePost($device, ['vehicle_id' => $vehicle->vehicle_id, 'installed_by' => 'Autotest Tech'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $row = $this->boundTo($vehicle->vehicle_id);
        $this->assertNotNull($row, 'the device was not bound');
        $this->assertSame($device->imei_number, $row->imei_number);
        $this->assertSame('Paid', $row->payment_status);
        $this->assertSame('1 Year', $row->subscription_model);
        $this->assertSame($this->today(), $row->subscription_start_date->format('Y-m-d'), 'the plan starts today (Sri Lanka)');
        $this->assertSame($this->plus($this->today(), 360), $row->subscription_end_date->format('Y-m-d'), '1 Year = 360 days');
        $this->assertSame(12, (int) $row->warranty_months);
        $this->assertNotNull($row->original_activated_at);
        $this->assertSame('Autotest Tech', $row->installed_by);

        $pay = PackagePayment::where('imei_number', $device->imei_number)->first();
        $this->assertNotNull($pay, 'payment record missing');
        $this->assertSame('distributor', $pay->source);
        $this->assertSame(2999.0, (float) $pay->customer_price, 'the price comes from the price master');
        $this->assertSame($dist->id, (int) $pay->retailer_dealer_id);
        $this->assertNull($pay->distributor_dealer_id, 'own-stock sale: no separate distributor margin');
        $this->assertSame($login->admin_id, $pay->recorded_by);
        $this->assertSame(1, CommissionEntry::where('payment_id', $pay->id)->count());

        $this->assertSame(DeviceStatus::Activated->value, $device->fresh()->status);

        $log = AuditLog::where('action', 'distributor.device_activated')->where('subject_label', 'AUTO-D1')->first();
        $this->assertNotNull($log, 'activation must be audited');
        $this->assertSame($login->admin_id, $log->actor_id);
        $this->assertSame('DISTRIBUTOR', $log->actor_role);
        $this->assertSame($device->imei_number, $log->meta['imei']);
    }

    public function test_a_device_sold_by_a_dealer_of_the_distributor_pays_both_margins(): void
    {
        $dist   = $this->distributor('Autotest Distributor A');
        $dealer = $this->dealer('Autotest Dealer A1', 'retailer', $dist);
        ['lead' => $lead, 'vehicle' => $vehicle] = $this->customerOf($dealer, 'AUTO-D2');
        $device = $this->device($dealer, $lead);
        $this->asDistributor($dist);

        $this->activatePost($device, ['vehicle_id' => $vehicle->vehicle_id])->assertSessionHasNoErrors();

        $pay = PackagePayment::where('imei_number', $device->imei_number)->first();
        $this->assertNotNull($pay);
        $this->assertSame($dealer->id, (int) $pay->retailer_dealer_id);
        $this->assertSame($dist->id, (int) $pay->distributor_dealer_id);
        $this->assertSame(500.0, (float) CommissionEntry::where('payment_id', $pay->id)->where('dealer_id', $dealer->id)->value('amount'));
        $this->assertSame(300.0, (float) CommissionEntry::where('payment_id', $pay->id)->where('dealer_id', $dist->id)->value('amount'));
    }

    public function test_devices_outside_the_network_are_not_found(): void
    {
        $dist      = $this->distributor('Autotest Distributor A');
        $otherDist = $this->distributor('Autotest Distributor B');
        $otherDealer = $this->dealer('Autotest Dealer B1', 'retailer', $otherDist);
        $direct    = $this->dealer('Autotest Direct Dealer');
        ['lead' => $otherLead] = $this->customerOf($otherDealer, 'AUTO-D3');
        ['lead' => $directLead] = $this->customerOf($direct, 'AUTO-D4');

        $outside = [
            $this->device($otherDist),                    // another distributor's own stock
            $this->device($otherDealer, $otherLead),      // sold by a dealer of another network
            $this->device($direct, $directLead),          // sold by a direct dealer
            $this->device(null),                          // company stock
        ];
        $this->asDistributor($dist);

        foreach ($outside as $device) {
            $this->actingAs($this->login)->get(route('distributor.devices.activate', $device->shdevice_id))->assertNotFound();
            $this->activatePost($device, ['vehicle_id' => 'anything'])->assertNotFound();
        }
        $this->get('/distributor/devices/abc/activate')->assertNotFound();   // not even a number

        foreach ($outside as $device) {
            $this->assertSame(0, ActivatedDevice::where('imei_number', $device->imei_number)->count());
            $this->assertSame(0, PackagePayment::where('imei_number', $device->imei_number)->count());
        }
    }

    public function test_a_dealers_unsold_stock_cannot_be_activated_by_the_distributor(): void
    {
        $dist   = $this->distributor('Autotest Distributor A');
        $dealer = $this->dealer('Autotest Dealer A1', 'retailer', $dist);
        ['vehicle' => $vehicle] = $this->customerOf($dealer, 'AUTO-D5');
        $unsold = $this->device($dealer);   // the dealer sells it, not the distributor
        $this->asDistributor($dist);

        $this->actingAs($this->login)->get(route('distributor.devices.activate', $unsold->shdevice_id))->assertNotFound();
        $this->activatePost($unsold, ['vehicle_id' => $vehicle->vehicle_id])->assertNotFound();

        $this->assertSame(0, ActivatedDevice::where('imei_number', $unsold->imei_number)->count());
    }

    public function test_the_vehicle_must_belong_to_the_customer_the_device_was_sold_to(): void
    {
        $dist      = $this->distributor('Autotest Distributor A');
        $dealer    = $this->dealer('Autotest Dealer A1', 'retailer', $dist);
        $otherDist = $this->distributor('Autotest Distributor B');
        $otherDealer = $this->dealer('Autotest Dealer B1', 'retailer', $otherDist);

        ['lead' => $soldLead]  = $this->customerOf($dealer, 'AUTO-D6');
        ['vehicle' => $ownCustomerVehicle] = $this->customerOf($dist, 'AUTO-D7');
        ['vehicle' => $otherNetworkVehicle] = $this->customerOf($otherDealer, 'AUTO-D8');
        ['vehicle' => $dealerCustomerVehicle] = $this->customerOf($dealer, 'AUTO-D9');
        $stranger = VehicleAd::create([
            'vehicle_id' => (string) Str::uuid(), 'customer_id' => (string) Str::uuid(), 'customer_name' => 'Autotest Stranger',
            'vehicle_number' => 'AUTO-D10', 'model' => 'Autotest Model', 'has_gps_device' => false,
        ]);

        $sold = $this->device($dealer, $soldLead);   // sold to $soldLead
        $own  = $this->device($dist);                // own stock
        $this->asDistributor($dist);

        // A dealer-sold device only goes to the customer it was sold to: not to another network's, a stranger's,
        // the distributor's own customer, or another customer of the same dealer.
        foreach ([$otherNetworkVehicle, $stranger, $ownCustomerVehicle, $dealerCustomerVehicle] as $vehicle) {
            $this->activatePost($sold, ['vehicle_id' => $vehicle->vehicle_id])->assertNotFound();
        }
        // Own stock only goes to the distributor's own customers, never to a dealer's customer.
        $this->activatePost($own, ['vehicle_id' => $dealerCustomerVehicle->vehicle_id])->assertNotFound();

        $this->assertSame(0, ActivatedDevice::whereIn('imei_number', [$sold->imei_number, $own->imei_number])->count());
        $this->assertSame(0, PackagePayment::whereIn('imei_number', [$sold->imei_number, $own->imei_number])->count());
    }

    public function test_the_activation_form_offers_only_the_vehicles_of_the_right_customer(): void
    {
        $dist   = $this->distributor('Autotest Distributor A');
        $dealer = $this->dealer('Autotest Dealer A1', 'retailer', $dist);
        $other  = $this->dealer('Autotest Dealer B1', 'retailer', $this->distributor('Autotest Distributor B'));

        ['lead' => $lead] = $this->customerOf($dealer, 'AUTO-OFFER-RIGHT');
        $this->customerOf($dealer, 'AUTO-OFFER-SAME-DEALER');
        $this->customerOf($dist, 'AUTO-OFFER-OWN');
        $this->customerOf($other, 'AUTO-OFFER-OTHER');
        $sold = $this->device($dealer, $lead);
        $this->asDistributor($dist);

        $this->actingAs($this->login)->get(route('distributor.devices.activate', $sold->shdevice_id))
            ->assertOk()
            ->assertSee('AUTO-OFFER-RIGHT')
            ->assertDontSee('AUTO-OFFER-SAME-DEALER')
            ->assertDontSee('AUTO-OFFER-OWN')
            ->assertDontSee('AUTO-OFFER-OTHER');
    }

    public function test_the_plan_must_be_on_offer_and_the_price_cannot_be_typed(): void
    {
        $dist = $this->distributor('Autotest Distributor A');
        ['vehicle' => $vehicle] = $this->customerOf($dist, 'AUTO-D11');
        $device = $this->device($dist);
        $this->asDistributor($dist);

        $this->activatePost($device, ['vehicle_id' => $vehicle->vehicle_id, 'plan' => '3 Months'])->assertSessionHasErrors('plan');   // no price
        $this->activatePost($device, ['vehicle_id' => $vehicle->vehicle_id, 'plan' => '9 Year'])->assertSessionHasErrors('plan');    // does not exist
        $this->assertSame(0, ActivatedDevice::where('imei_number', $device->imei_number)->count());

        $this->activatePost($device, ['vehicle_id' => $vehicle->vehicle_id, 'price' => '1', 'customer_price' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(2999.0, (float) PackagePayment::where('imei_number', $device->imei_number)->value('customer_price'));
    }

    public function test_the_payment_reference_is_required_and_can_be_used_only_once(): void
    {
        $dist = $this->distributor('Autotest Distributor A');
        ['vehicle' => $v1] = $this->customerOf($dist, 'AUTO-D12');
        ['vehicle' => $v2] = $this->customerOf($dist, 'AUTO-D13');
        $d1 = $this->device($dist);
        $d2 = $this->device($dist);
        $this->asDistributor($dist);

        $this->activatePost($d1, ['vehicle_id' => $v1->vehicle_id, 'bank_invoice' => ''])->assertSessionHasErrors('bank_invoice');
        $this->activatePost($d1, ['vehicle_id' => $v1->vehicle_id, 'bank_invoice' => 'AUTOTEST-SAME'])->assertSessionHasNoErrors();

        $this->activatePost($d2, ['vehicle_id' => $v2->vehicle_id, 'bank_invoice' => 'AUTOTEST-SAME'])
            ->assertSessionHasErrors('bank_invoice');

        $this->assertSame(1, ActivatedDevice::whereIn('imei_number', [$d1->imei_number, $d2->imei_number])->count());
        $this->assertSame(1, PackagePayment::whereIn('imei_number', [$d1->imei_number, $d2->imei_number])->count());
    }

    public function test_the_install_date_cannot_be_in_the_future(): void
    {
        $dist = $this->distributor('Autotest Distributor A');
        ['vehicle' => $vehicle] = $this->customerOf($dist, 'AUTO-D14');
        $device = $this->device($dist);
        $this->asDistributor($dist);

        $tomorrow = now('Asia/Colombo')->addDay()->toDateString();
        $this->activatePost($device, ['vehicle_id' => $vehicle->vehicle_id, 'installed_on' => $tomorrow])->assertSessionHasErrors('installed_on');

        $this->assertSame(0, ActivatedDevice::where('imei_number', $device->imei_number)->count());
    }

    public function test_a_device_cannot_be_activated_twice_and_a_bound_vehicle_is_no_longer_offered(): void
    {
        $dist = $this->distributor('Autotest Distributor A');
        ['vehicle' => $vehicle] = $this->customerOf($dist, 'AUTO-TAKEN');
        $first  = $this->device($dist);
        $second = $this->device($dist);
        $this->asDistributor($dist);

        $this->activatePost($first, ['vehicle_id' => $vehicle->vehicle_id])->assertSessionHasNoErrors();

        // The first device is bound, so it is no longer "ready".
        $this->activatePost($first, ['vehicle_id' => $vehicle->vehicle_id])->assertNotFound();

        // The vehicle already has a device: a second device cannot be put on it, and the form no longer lists it.
        $this->activatePost($second, ['vehicle_id' => $vehicle->vehicle_id])->assertNotFound();
        $this->actingAs($this->login)->get(route('distributor.devices.activate', $second->shdevice_id))->assertOk()->assertDontSee('AUTO-TAKEN');

        $this->assertSame(1, ActivatedDevice::where('vehicle_id', $vehicle->vehicle_id)->count());
        $this->assertSame(0, ActivatedDevice::where('imei_number', $second->imei_number)->count());
    }

    // ------------------------------------------------------------------ renewal

    public function test_renewing_a_running_plan_continues_from_the_current_expiry_and_keeps_the_activation_date(): void
    {
        $dist   = $this->distributor('Autotest Distributor A');
        $dealer = $this->dealer('Autotest Dealer A1', 'retailer', $dist);
        $row    = $this->bound($dealer, 80);
        $originalActivation = $row->original_activated_at->toDateTimeString();
        $oldEnd = $row->subscription_end_date->format('Y-m-d');
        $this->asDistributor($dist);

        $this->renewPost($row, ['plan' => '1 Year'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $row->refresh();
        $this->assertSame($oldEnd, $row->subscription_start_date->format('Y-m-d'), 'the paid days that are left are never lost');
        $this->assertSame($this->plus($oldEnd, 360), $row->subscription_end_date->format('Y-m-d'));
        $this->assertSame($originalActivation, $row->original_activated_at->toDateTimeString(), 'the activation date never moves');
        $this->assertSame(12, (int) $row->warranty_months);

        $pay = PackagePayment::where('imei_number', $row->imei_number)->first();
        $this->assertNotNull($pay);
        $this->assertSame('distributor_renewal', $pay->source);
        $this->assertSame($oldEnd, $pay->previous_expiry->format('Y-m-d'));
        $this->assertSame($dealer->id, (int) $pay->retailer_dealer_id);
        $this->assertSame($dist->id, (int) $pay->distributor_dealer_id);
        $this->assertSame(1, AuditLog::where('action', 'distributor.subscription_renewed')->where('subject_label', $row->vehicle_number)->count());
    }

    public function test_the_warranty_never_gets_shorter_when_a_shorter_plan_is_renewed(): void
    {
        $dist = $this->distributor('Autotest Distributor A');
        $row  = $this->bound($dist, 80);
        $row->update(['warranty_months' => 12]);
        $this->asDistributor($dist);

        $this->renewPost($row, ['plan' => '6 Months'])->assertSessionHasNoErrors();

        $this->assertSame(12, (int) $row->fresh()->warranty_months, 'a 6-month plan must not shorten a 12-month warranty');
    }

    public function test_renewing_an_expired_plan_starts_today(): void
    {
        $dist = $this->distributor('Autotest Distributor A');
        $row  = $this->bound($dist, -10);   // ended 10 days ago
        $this->asDistributor($dist);

        $this->renewPost($row, ['plan' => '6 Months'])->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame($this->today(), $row->subscription_start_date->format('Y-m-d'), 'an expired plan cannot be back-dated');
        $this->assertSame($this->plus($this->today(), 180), $row->subscription_end_date->format('Y-m-d'));
        $this->assertSame('Paid', $row->payment_status);
    }

    public function test_a_device_outside_the_network_cannot_be_renewed(): void
    {
        $dist      = $this->distributor('Autotest Distributor A');
        $otherDist = $this->distributor('Autotest Distributor B');
        $theirs    = $this->bound($this->dealer('Autotest Dealer B1', 'retailer', $otherDist));
        $direct    = $this->bound($this->dealer('Autotest Direct Dealer'));
        $this->asDistributor($dist);

        foreach ([$theirs, $direct] as $row) {
            $before = $row->fresh()->subscription_end_date->toDateTimeString();

            $this->actingAs($this->login)->get(route('distributor.devices.renew', $row->activated_device_id))->assertNotFound();
            $this->renewPost($row, ['plan' => '1 Year'])->assertNotFound();

            $this->assertSame($before, $row->fresh()->subscription_end_date->toDateTimeString(), 'their plan must not change');
        }
        $this->assertSame(0, PackagePayment::whereIn('imei_number', [$theirs->imei_number, $direct->imei_number])->count());
    }

    public function test_a_renewal_needs_a_new_reference_and_a_plan_that_is_on_offer(): void
    {
        $dist = $this->distributor('Autotest Distributor A');
        $row  = $this->bound($dist, 80);
        $this->asDistributor($dist);

        $this->renewPost($row, ['bank_invoice' => $row->bank_invoice])->assertSessionHasErrors('bank_invoice');   // already used
        $this->renewPost($row, ['bank_invoice' => ''])->assertSessionHasErrors('bank_invoice');
        $this->renewPost($row, ['plan' => '3 Months'])->assertSessionHasErrors('plan');                           // no price
        $this->renewPost($row, ['plan' => '9 Year'])->assertSessionHasErrors('plan');

        $this->assertSame(0, PackagePayment::where('imei_number', $row->imei_number)->count());
        $this->assertSame($row->subscription_end_date->toDateTimeString(), $row->fresh()->subscription_end_date->toDateTimeString());

        $this->renewPost($row, ['price' => '1', 'customer_price' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1599.0, (float) PackagePayment::where('imei_number', $row->imei_number)->value('customer_price'), 'the price is never typed');
    }

    // ------------------------------------------------------------------ pages and customers

    public function test_the_device_list_shows_only_the_distributors_network(): void
    {
        $dist      = $this->distributor('Autotest Distributor A');
        $dealer    = $this->dealer('Autotest Dealer A1', 'retailer', $dist);
        $otherDist = $this->distributor('Autotest Distributor B');
        $otherDealer = $this->dealer('Autotest Dealer B1', 'retailer', $otherDist);

        $ownReady    = $this->device($dist);
        $ownBound    = $this->bound($dealer);
        $otherReady  = $this->device($otherDist);
        $otherBound  = $this->bound($otherDealer);
        $this->asDistributor($dist);

        $this->actingAs($this->login)->get(route('distributor.devices'))
            ->assertOk()
            ->assertSee($ownReady->imei_number)
            ->assertSee($ownBound->imei_number)
            ->assertDontSee($otherReady->imei_number)
            ->assertDontSee($otherBound->imei_number);
    }

    public function test_the_customers_page_lists_the_network_and_hides_other_networks(): void
    {
        $dist   = $this->distributor('Autotest Distributor A');
        $dealer = $this->dealer('Autotest Dealer A1', 'retailer', $dist);
        $other  = $this->dealer('Autotest Dealer B1', 'retailer', $this->distributor('Autotest Distributor B'));

        $this->customerOf($dist, 'AUTO-C1', 'Autotest Own Customer');
        $this->customerOf($dealer, 'AUTO-C2', 'Autotest Dealer Customer');
        $this->customerOf($other, 'AUTO-C3', 'Autotest Foreign Customer');
        $this->asDistributor($dist);

        $this->actingAs($this->login)->get(route('distributor.customers'))
            ->assertOk()
            ->assertSee('Autotest Own Customer')
            ->assertSee('Autotest Dealer Customer')
            ->assertDontSee('Autotest Foreign Customer');
    }

    public function test_a_distributor_can_register_its_own_customer_and_bad_input_is_refused(): void
    {
        $dist  = $this->distributor('Autotest Distributor A');
        $login = $this->asDistributor($dist);

        $this->actingAs($login)->post(route('distributor.customers.store'), [
            'name' => 'Autotest New Person', 'contact' => '0771234567', 'email' => 'Autotest.New@Example.Test',
        ])->assertSessionHasNoErrors();

        $lead = DealerCustomerAd::where('name', 'Autotest New Person')->first();
        $this->assertNotNull($lead);
        $this->assertSame($dist->id, (int) $lead->dealer_id, 'the customer belongs to the distributor itself');
        $this->assertSame('autotest.new@example.test', $lead->email, 'the e-mail is stored in lower case');
        $this->assertSame(1, AuditLog::where('action', 'distributor.customer_registered')->where('subject_label', 'Autotest New Person')->count());

        $this->post(route('distributor.customers.store'), ['name' => 'Autotest Bad', 'contact' => '123'])->assertSessionHasErrors('contact');
        $this->post(route('distributor.customers.store'), ['name' => '', 'contact' => '0771234567'])->assertSessionHasErrors('name');
        $this->post(route('distributor.customers.store'), ['name' => 'Autotest Bad', 'contact' => '0771234567', 'email' => 'nope'])->assertSessionHasErrors('email');

        $this->assertSame(0, DealerCustomerAd::where('name', 'Autotest Bad')->count());
    }

    // ------------------------------------------------------------------ who may use the pages

    public static function otherRoles(): array
    {
        return ['a dealer login' => ['DEALER'], 'an admin login' => ['ADMIN'], 'a finance login' => ['FINANCE']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_a_distributor_login_can_use_the_activation_pages(string $role): void
    {
        $dist   = $this->distributor('Autotest Distributor A');
        $device = $this->device($dist);
        $row    = $this->bound($dist);
        $this->login = $this->makeAdmin($role, ['dealer_id' => $dist->id]);

        $this->actingAs($this->login)->get(route('distributor.devices'))->assertForbidden();
        $this->get(route('distributor.customers'))->assertForbidden();
        $this->get(route('distributor.devices.activate', $device->shdevice_id))->assertForbidden();
        $this->get(route('distributor.devices.renew', $row->activated_device_id))->assertForbidden();
        $this->activatePost($device, ['vehicle_id' => 'anything'])->assertForbidden();
        $this->renewPost($row)->assertForbidden();

        $this->assertSame(0, ActivatedDevice::where('imei_number', $device->imei_number)->count());
        $this->assertSame(0, PackagePayment::where('imei_number', $row->imei_number)->count());
    }

    public function test_a_distributor_login_that_is_not_linked_to_a_distributor_is_refused(): void
    {
        $dist   = $this->distributor('Autotest Distributor A');
        $device = $this->device($dist);

        $this->login = $this->makeAdmin('DISTRIBUTOR', ['dealer_id' => null]);

        $this->actingAs($this->login)->get(route('distributor.devices'))->assertForbidden();
        $this->get(route('distributor.customers'))->assertForbidden();
        $this->activatePost($device, ['vehicle_id' => 'anything'])->assertForbidden();

        $this->assertSame(0, ActivatedDevice::where('imei_number', $device->imei_number)->count());
    }

    public function test_a_distributor_login_linked_to_an_inactive_distributor_is_refused(): void
    {
        $archived = $this->distributor('Autotest Archived Distributor', 'archived');
        $device   = $this->device($archived);
        $this->asDistributor($archived);

        $this->actingAs($this->login)->get(route('distributor.devices'))->assertForbidden();
        $this->get(route('distributor.customers'))->assertForbidden();
        $this->activatePost($device, ['vehicle_id' => 'anything'])->assertForbidden();

        $this->assertSame(0, ActivatedDevice::where('imei_number', $device->imei_number)->count());
    }
}