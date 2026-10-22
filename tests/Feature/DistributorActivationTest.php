<?php

namespace Tests\Feature;

use App\Models\ActivatedDevice;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\CommissionEntry;
use App\Models\PackagePayment;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Step 4: a distributor binds, activates and renews devices of its own network only. */
class DistributorActivationTest extends TestCase
{
    private int $distA;
    private int $distB;
    private int $dealerA1;
    private int $dealerB1;
    private int $direct;
    private int $leadDistA;   // distributor A's own customer
    private int $leadA1;      // dealer A1's customer
    private int $leadB1;      // dealer B1's customer (other network)

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-22 06:00:00', 'UTC'));

        Schema::create('Admins', function (Blueprint $t) {
            $t->string('admin_id')->primary()->default(DB::raw('(lower(hex(randomblob(16))))'));
            $t->string('username')->nullable();
            $t->string('password')->nullable();
            $t->string('full_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone_number')->nullable();
            $t->string('role');
            $t->string('status')->default('ACTIVE');
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('password_changed_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });
        Schema::create('dealers', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->string('dealer_status')->nullable();
            $t->unsignedBigInteger('distributor_id')->nullable();
            $t->string('region')->nullable();
            $t->string('contact_email')->nullable();
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('dealer_customer_ads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('contact')->nullable();
            $t->string('nic_or_id')->nullable();
            $t->integer('no_of_devices')->default(0);
            $t->json('imei_numbers')->nullable();
            $t->string('address')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('Customer-ad', function (Blueprint $t) {
            $t->string('customer_id')->primary();
            $t->string('full_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone_number')->nullable();
            $t->string('nic_number')->nullable();
            $t->string('address')->nullable();
            $t->string('profile_image')->nullable();
            $t->integer('vehicle_count')->nullable();
            $t->integer('source_account_status')->nullable();
            $t->string('cus_status')->nullable();
            $t->timestamp('last_synced_at')->nullable();
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
        Schema::create('setup_shalotrack_devices', function (Blueprint $t) {
            $t->id('shdevice_id');
            $t->string('device_category')->nullable();
            $t->string('imei_number')->unique();
            $t->string('sim_number')->nullable();
            $t->string('iccid')->nullable();
            $t->string('imsi')->nullable();
            $t->string('status')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('canceled_date')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->unsignedBigInteger('assigned_customer_id')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->timestamp('allocated_at')->nullable();
            $t->unsignedBigInteger('transfer_id')->nullable();
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
            $t->string('status')->nullable();
            $t->string('payment_status')->nullable();
            $t->string('subscription_model')->nullable();
            $t->timestamp('subscription_start_date')->nullable();
            $t->timestamp('subscription_end_date')->nullable();
            $t->string('bank_invoice')->nullable()->unique();
            $t->string('bank_slip')->nullable();
            $t->date('installed_on')->nullable();
            $t->string('installed_by')->nullable();
            $t->text('install_notes')->nullable();
            $t->timestamp('original_activated_at')->nullable();
            $t->unsignedSmallInteger('warranty_months')->nullable();
            $t->timestamps();
        });
        Schema::create('renewal_packages', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('model', 20)->unique();
            $t->string('label', 40);
            $t->string('positioning', 60)->nullable();
            $t->unsignedSmallInteger('months');
            $t->decimal('customer_price', 10, 2)->nullable();
            $t->decimal('company_margin', 10, 2)->nullable();
            $t->decimal('distributor_margin', 10, 2)->nullable();
            $t->decimal('retailer_margin', 10, 2)->nullable();
            $t->unsignedSmallInteger('warranty_months')->default(0);
            $t->boolean('is_active')->default(true);
            $t->date('effective_from')->nullable();
            $t->string('updated_by', 64)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('package_payments', function (Blueprint $t) {
            $t->id();
            $t->string('event_key', 64)->nullable()->unique();
            $t->unsignedBigInteger('activated_device_id')->nullable();
            $t->string('imei_number', 32);
            $t->string('vehicle_id')->nullable();
            $t->string('vehicle_number')->nullable();
            $t->string('customer_id')->nullable();
            $t->string('customer_name')->nullable();
            $t->string('source', 20);
            $t->string('package_model', 20);
            $t->string('package_label', 40)->nullable();
            $t->unsignedSmallInteger('months')->nullable();
            $t->decimal('customer_price', 10, 2)->nullable();
            $t->decimal('company_margin', 10, 2)->nullable();
            $t->decimal('distributor_margin', 10, 2)->nullable();
            $t->decimal('retailer_margin', 10, 2)->nullable();
            $t->unsignedBigInteger('retailer_dealer_id')->nullable();
            $t->unsignedBigInteger('distributor_dealer_id')->nullable();
            $t->timestamp('subscription_start')->nullable();
            $t->timestamp('subscription_end')->nullable();
            $t->timestamp('previous_expiry')->nullable();
            $t->string('bank_invoice')->nullable();
            $t->string('recorded_by', 64)->nullable();
            $t->string('recorded_by_name', 100)->nullable();
            $t->string('status', 10)->default('valid');
            $t->timestamp('reversed_at')->nullable();
            $t->string('reversed_by', 64)->nullable();
            $t->string('reversal_reason', 255)->nullable();
            $t->timestamps();
        });
        Schema::create('commission_entries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('payment_id');
            $t->unsignedBigInteger('dealer_id');
            $t->string('role', 12);
            $t->string('entry_type', 10);
            $t->decimal('amount', 12, 2);
            $t->string('period_month', 7);
            $t->timestamp('occurred_at');
            $t->unsignedBigInteger('payout_id')->nullable();
            $t->string('reason', 255)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['payment_id', 'dealer_id', 'role', 'entry_type']);
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->timestamp('occurred_at');
            $t->string('actor_id', 64)->nullable();
            $t->string('actor_name', 150)->nullable();
            $t->string('actor_role', 20)->nullable();
            $t->string('category', 20);
            $t->string('action', 60);
            $t->string('subject_type', 40)->nullable();
            $t->string('subject_id', 100)->nullable();
            $t->string('subject_label', 150)->nullable();
            $t->json('changes')->nullable();
            $t->json('meta')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 200)->nullable();
        });

        foreach ([
            ['6M', '6 Months', '6 Months', 6, 1999, 1499, 200, 300, 0, 2],
            ['1Y', '1 Year', '1 Year', 12, 2999, 2199, 300, 500, 12, 3],
            ['3M', '3 Months', '3 Months', 3, null, null, null, null, 0, 1],
        ] as [$code, $model, $label, $months, $price, $co, $di, $re, $war, $sort]) {
            DB::table('renewal_packages')->insert([
                'code' => $code, 'model' => $model, 'label' => $label, 'months' => $months, 'customer_price' => $price,
                'company_margin' => $co, 'distributor_margin' => $di, 'retailer_margin' => $re, 'warranty_months' => $war,
                'is_active' => true, 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->distA    = $this->dealer('Dist Alpha', 'distributor');
        $this->distB    = $this->dealer('Dist Beta', 'distributor');
        $this->dealerA1 = $this->dealer('Alpha One', 'retailer', $this->distA);
        $this->dealerB1 = $this->dealer('Beta One', 'retailer', $this->distB);
        $this->direct   = $this->dealer('Direct Dan', 'retailer');

        $this->leadDistA = $this->lead($this->distA, 'Dist A customer', '0771111111', 'ca@x.com');
        $this->leadA1    = $this->lead($this->dealerA1, 'A1 customer', '0772222222', 'a1@x.com');
        $this->leadB1    = $this->lead($this->dealerB1, 'B1 customer', '0773333333', 'b1@x.com');

        $this->appCustomer('cust-da', 'Dist A customer', '+94771111111', 'ca@x.com');
        $this->appCustomer('cust-a1', 'A1 customer', '+94772222222', 'a1@x.com');
        $this->appCustomer('cust-b1', 'B1 customer', '+94773333333', 'b1@x.com');
        $this->appCustomer('cust-stranger', 'Stranger', '+94779999999', 'nobody@x.com');

        $this->vehicle('veh-da', 'cust-da', 'WP-1111');
        $this->vehicle('veh-a1', 'cust-a1', 'WP-2222');
        $this->vehicle('veh-b1', 'cust-b1', 'WP-3333');
        $this->vehicle('veh-stranger', 'cust-stranger', 'WP-9999');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dealer(string $name, string $type, ?int $parent = null, string $status = 'active'): int
    {
        return DB::table('dealers')->insertGetId([
            'full_name' => $name, 'dealer_status' => $type, 'distributor_id' => $parent, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lead(int $dealer, string $name, string $phone, string $email): int
    {
        return DB::table('dealer_customer_ads')->insertGetId([
            'dealer_id' => $dealer, 'name' => $name, 'contact' => $phone, 'email' => $email,
            'imei_numbers' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function appCustomer(string $id, string $name, string $phone, string $email): void
    {
        DB::table('Customer-ad')->insert(['customer_id' => $id, 'full_name' => $name, 'phone_number' => $phone, 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function vehicle(string $id, string $customer, string $number): void
    {
        DB::table('vehicle_ad')->insert([
            'vehicle_id' => $id, 'customer_id' => $customer, 'customer_name' => DB::table('Customer-ad')->where('customer_id', $customer)->value('full_name'),
            'vehicle_number' => $number, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function device(string $imei, ?int $holder, string $status = 'Activated', ?int $lead = null): int
    {
        return DB::table('setup_shalotrack_devices')->insertGetId([
            'imei_number' => $imei, 'sim_number' => 'SIM-' . $imei, 'device_category' => 'GT06',
            'dealer_id' => $holder, 'status' => $status, 'assigned_customer_id' => $lead,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function login(string $role, string $username, ?int $dealerId = null): Admin
    {
        return Admin::find('id-' . $username) ?? Admin::forceCreate([
            'admin_id' => 'id-' . $username, 'username' => $username, 'full_name' => ucfirst($username),
            'email' => $username . '@example.com', 'password' => Hash::make('x'), 'role' => $role,
            'status' => 'ACTIVE', 'dealer_id' => $dealerId,
        ]);
    }

    private function as(Admin $u): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($u);
    }

    private function asDistA(): void
    {
        $this->as($this->login('DISTRIBUTOR', 'dist_a', $this->distA));
    }

    private function activatePost(int $deviceId, array $over = [])
    {
        return $this->post(route('distributor.devices.activate.store', $deviceId), $over + [
            'vehicle_id' => 'veh-da', 'plan' => '1 Year', 'bank_invoice' => 'INV-1',
        ]);
    }

    private function bound(string $vehicle): ?ActivatedDevice
    {
        return ActivatedDevice::where('vehicle_id', $vehicle)->first();
    }

    // ---- activation -------------------------------------------------------------------------------------

    public function test_distributor_activates_own_stock_for_its_own_customer_and_the_payment_is_recorded(): void
    {
        $d = $this->device('111', $this->distA);
        $this->asDistA();

        $this->activatePost($d, ['installed_by' => 'Tech Tom'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $row = $this->bound('veh-da');
        $this->assertSame('111', $row->imei_number);
        $this->assertSame('Paid', $row->payment_status);
        $this->assertSame('1 Year', $row->subscription_model);
        $this->assertSame('2026-10-22', $row->subscription_start_date->format('Y-m-d'));
        $this->assertSame('2027-10-17', $row->subscription_end_date->format('Y-m-d')); // 360 days, per the spec
        $this->assertSame(12, (int) $row->warranty_months);
        $this->assertNotNull($row->original_activated_at);

        $pay = PackagePayment::first();
        $this->assertSame('distributor', $pay->source);
        $this->assertEquals(2999, (float) $pay->customer_price);
        $this->assertSame($this->distA, (int) $pay->retailer_dealer_id);
        $this->assertNull($pay->distributor_dealer_id); // own sale: own-sales margin rule still undecided, code unchanged
        $this->assertSame('id-dist_a', $pay->recorded_by);

        $this->assertSame(1, CommissionEntry::count());
        $this->assertSame('Activated', DB::table('setup_shalotrack_devices')->where('shdevice_id', $d)->value('status'));

        $log = AuditLog::where('action', 'distributor.device_activated')->first();
        $this->assertSame('id-dist_a', $log->actor_id);
        $this->assertSame('DISTRIBUTOR', $log->actor_role);
        $this->assertSame('111', $log->meta['imei']);
    }

    public function test_device_sold_by_a_dealer_under_the_distributor_pays_both_margins(): void
    {
        $d = $this->device('222', $this->dealerA1, 'Assigned to Customer', $this->leadA1);
        $this->asDistA();

        $this->activatePost($d, ['vehicle_id' => 'veh-a1', 'bank_invoice' => 'INV-2'])->assertSessionHasNoErrors();

        $pay = PackagePayment::first();
        $this->assertSame($this->dealerA1, (int) $pay->retailer_dealer_id);
        $this->assertSame($this->distA, (int) $pay->distributor_dealer_id);
        $this->assertEquals(500, (float) CommissionEntry::where('dealer_id', $this->dealerA1)->value('amount'));
        $this->assertEquals(300, (float) CommissionEntry::where('dealer_id', $this->distA)->value('amount'));
    }

    public function test_devices_outside_the_network_are_not_found(): void
    {
        $other = $this->device('333', $this->distB);
        $otherDealer = $this->device('334', $this->dealerB1, 'Assigned to Customer', $this->leadB1);
        $direct = $this->device('335', $this->direct, 'Assigned to Customer', $this->leadA1);
        $company = $this->device('336', null);
        $this->asDistA();

        foreach ([$other, $otherDealer, $direct, $company] as $id) {
            $this->get(route('distributor.devices.activate', $id))->assertNotFound();
            $this->activatePost($id)->assertNotFound();
        }
        $this->assertSame(0, ActivatedDevice::count());
        $this->assertSame(0, PackagePayment::count());
    }

    public function test_a_dealers_unsold_stock_cannot_be_activated(): void
    {
        $d = $this->device('444', $this->dealerA1); // activated, unsold: the dealer sells it
        $this->asDistA();

        $this->get(route('distributor.devices.activate', $d))->assertNotFound();
        $this->activatePost($d, ['vehicle_id' => 'veh-a1'])->assertNotFound();
        $this->assertSame(0, ActivatedDevice::count());
    }

    public function test_vehicle_must_belong_to_the_customer_the_device_was_sold_to(): void
    {
        $sold = $this->device('555', $this->dealerA1, 'Assigned to Customer', $this->leadA1);
        $own = $this->device('556', $this->distA);
        $this->asDistA();

        // another network's vehicle, a stranger's vehicle, the distributor's customer for a dealer-sold device
        foreach (['veh-b1', 'veh-stranger', 'veh-da'] as $v) {
            $this->activatePost($sold, ['vehicle_id' => $v, 'bank_invoice' => 'X-' . $v])->assertNotFound();
        }
        // own stock may only go to the distributor's own customers, not to a dealer's customer
        $this->activatePost($own, ['vehicle_id' => 'veh-a1'])->assertNotFound();

        $this->assertSame(0, ActivatedDevice::count());
        $this->assertSame(0, PackagePayment::count());
    }

    public function test_form_only_offers_vehicles_of_the_right_customer(): void
    {
        $sold = $this->device('557', $this->dealerA1, 'Assigned to Customer', $this->leadA1);
        $this->asDistA();

        $html = $this->get(route('distributor.devices.activate', $sold))->assertOk()->getContent();
        $this->assertStringContainsString('WP-2222', $html);
        foreach (['WP-1111', 'WP-3333', 'WP-9999'] as $plate) {
            $this->assertStringNotContainsString($plate, $html);
        }
    }

    public function test_plan_must_be_on_offer_and_the_price_cannot_be_typed(): void
    {
        $d = $this->device('666', $this->distA);
        $this->asDistA();

        // 3 Months has no price, a made-up plan does not exist
        $this->activatePost($d, ['plan' => '3 Months'])->assertSessionHasErrors('plan');
        $this->activatePost($d, ['plan' => '9 Year'])->assertSessionHasErrors('plan');
        $this->activatePost($d, ['price' => '1', 'customer_price' => '1'])->assertSessionHasNoErrors();

        $this->assertEquals(2999, (float) PackagePayment::first()->customer_price);
    }

    public function test_payment_reference_is_required_and_cannot_be_reused(): void
    {
        $a = $this->device('701', $this->distA);
        $b = $this->device('702', $this->dealerA1, 'Assigned to Customer', $this->leadA1);
        $this->asDistA();

        $this->activatePost($a, ['bank_invoice' => ''])->assertSessionHasErrors('bank_invoice');
        $this->activatePost($a, ['bank_invoice' => 'SAME'])->assertSessionHasNoErrors();
        $this->activatePost($b, ['vehicle_id' => 'veh-a1', 'bank_invoice' => 'SAME'])->assertSessionHasErrors('bank_invoice');

        $this->assertSame(1, ActivatedDevice::count());
        $this->assertSame(1, PackagePayment::count());
    }

    public function test_same_device_cannot_be_activated_twice(): void
    {
        $d = $this->device('801', $this->distA);
        $this->asDistA();

        $this->activatePost($d, ['bank_invoice' => 'A'])->assertSessionHasNoErrors();
        $this->activatePost($d, ['bank_invoice' => 'B'])->assertNotFound(); // already bound, no longer ready
        $this->assertSame(1, ActivatedDevice::count());
    }

    // ---- renewal ----------------------------------------------------------------------------------------

    private function boundDevice(int $holder, string $imei, string $vehicle, string $plan = '6 Months'): ActivatedDevice
    {
        $this->device($imei, $holder, 'Activated');

        return ActivatedDevice::create([
            'vehicle_id' => $vehicle, 'customer_id' => 'x', 'customer_name' => 'C', 'vehicle_number' => 'V-' . $vehicle,
            'imei_number' => $imei, 'status' => 'Activated', 'payment_status' => 'Paid', 'subscription_model' => $plan,
            'subscription_start_date' => now()->subDays(100)->startOfDay(), 'subscription_end_date' => now()->addDays(80)->startOfDay(),
            'bank_invoice' => 'OLD-' . $imei, 'warranty_months' => 0, 'original_activated_at' => now()->subDays(100),
        ]);
    }

    public function test_renewing_a_running_subscription_adds_to_the_expiry_and_keeps_the_warranty_clock(): void
    {
        $row = $this->boundDevice($this->dealerA1, '901', 'v901');
        $originalActivation = $row->original_activated_at->toDateTimeString();
        $oldEnd = $row->subscription_end_date->format('Y-m-d');
        $this->asDistA();

        $this->post(route('distributor.devices.renew.store', $row->activated_device_id), ['plan' => '1 Year', 'bank_invoice' => 'R-1'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $row->refresh();
        $this->assertSame($oldEnd, $row->subscription_start_date->format('Y-m-d'));
        $this->assertSame(Carbon::parse($oldEnd)->addDays(360)->format('Y-m-d'), $row->subscription_end_date->format('Y-m-d'));
        $this->assertSame($originalActivation, $row->original_activated_at->toDateTimeString());
        $this->assertSame(12, (int) $row->warranty_months);

        $pay = PackagePayment::first();
        $this->assertSame('distributor_renewal', $pay->source);
        $this->assertSame($oldEnd, $pay->previous_expiry->format('Y-m-d'));
        $this->assertSame($this->distA, (int) $pay->distributor_dealer_id);
        $this->assertSame(1, AuditLog::where('action', 'distributor.subscription_renewed')->count());
    }

    public function test_warranty_never_shrinks_when_a_shorter_plan_is_renewed(): void
    {
        $row = $this->boundDevice($this->distA, '902', 'v902', '1 Year');
        $row->update(['warranty_months' => 12]);
        $this->asDistA();

        $this->post(route('distributor.devices.renew.store', $row->activated_device_id), ['plan' => '6 Months', 'bank_invoice' => 'R-2'])
            ->assertSessionHasNoErrors();

        $this->assertSame(12, (int) $row->fresh()->warranty_months);
    }

    public function test_renewing_a_lapsed_subscription_starts_today(): void
    {
        $row = $this->boundDevice($this->distA, '903', 'v903');
        $row->update(['payment_status' => 'not-Paid', 'subscription_model' => null, 'subscription_start_date' => null, 'subscription_end_date' => null, 'bank_invoice' => null]);
        $this->asDistA();

        $this->post(route('distributor.devices.renew.store', $row->activated_device_id), ['plan' => '6 Months', 'bank_invoice' => 'R-3'])
            ->assertSessionHasNoErrors();

        $row->refresh();
        $this->assertSame('2026-10-22', $row->subscription_start_date->format('Y-m-d'));
        $this->assertSame('2027-04-20', $row->subscription_end_date->format('Y-m-d'));
        $this->assertSame('Paid', $row->payment_status);
    }

    public function test_cannot_renew_a_device_outside_the_network(): void
    {
        $theirs = $this->boundDevice($this->dealerB1, '904', 'v904');
        $direct = $this->boundDevice($this->direct, '905', 'v905');
        $this->asDistA();

        foreach ([$theirs, $direct] as $row) {
            $before = $row->fresh()->subscription_end_date->toDateTimeString();
            $this->get(route('distributor.devices.renew', $row->activated_device_id))->assertNotFound();
            $this->post(route('distributor.devices.renew.store', $row->activated_device_id), ['plan' => '1 Year', 'bank_invoice' => 'Z-' . $row->vehicle_id])->assertNotFound();
            $this->assertSame($before, $row->fresh()->subscription_end_date->toDateTimeString());
        }
        $this->assertSame(0, PackagePayment::count());
    }

    public function test_renewal_reference_cannot_be_reused(): void
    {
        $row = $this->boundDevice($this->distA, '906', 'v906');
        $this->asDistA();

        $this->post(route('distributor.devices.renew.store', $row->activated_device_id), ['plan' => '6 Months', 'bank_invoice' => 'OLD-906'])
            ->assertSessionHasErrors('bank_invoice');
        $this->assertSame(0, PackagePayment::count());
    }

    // ---- pages and customers --------------------------------------------------------------------------

    public function test_list_page_shows_only_the_network(): void
    {
        $this->device('A-ready', $this->distA);
        $this->device('B-ready', $this->distB);
        $this->boundDevice($this->dealerA1, 'A-bound', 'vab');
        $this->boundDevice($this->dealerB1, 'B-bound', 'vbb');
        $this->asDistA();

        $html = $this->get(route('distributor.devices'))->assertOk()->getContent();
        $this->assertStringContainsString('A-ready', $html);
        $this->assertStringContainsString('A-bound', $html);
        $this->assertStringNotContainsString('B-ready', $html);
        $this->assertStringNotContainsString('B-bound', $html);
    }

    public function test_customers_page_is_read_only_for_dealers_customers_and_hides_other_networks(): void
    {
        $this->asDistA();

        $html = $this->get(route('distributor.customers'))->assertOk()->getContent();
        $this->assertStringContainsString('Dist A customer', $html);
        $this->assertStringContainsString('A1 customer', $html);
        $this->assertStringNotContainsString('B1 customer', $html);
    }

    public function test_distributor_can_register_its_own_customer(): void
    {
        $this->asDistA();

        $this->post(route('distributor.customers.store'), ['name' => 'New Person', 'contact' => '0771234567', 'email' => 'New@X.com'])
            ->assertSessionHasNoErrors();

        $lead = DB::table('dealer_customer_ads')->where('name', 'New Person')->first();
        $this->assertSame($this->distA, (int) $lead->dealer_id);
        $this->assertSame('new@x.com', $lead->email);
        $this->assertSame(1, AuditLog::where('action', 'distributor.customer_registered')->count());

        $this->post(route('distributor.customers.store'), ['name' => 'Bad', 'contact' => '123'])->assertSessionHasErrors('contact');
    }

    // ---- access ------------------------------------------------------------------------------------------

    public function test_other_roles_and_unlinked_logins_cannot_use_the_pages(): void
    {
        $d = $this->device('999', $this->distA);

        foreach (['DEALER', 'ADMIN', 'FINANCE'] as $role) {
            $this->as($this->login($role, 'u_' . $role, $this->dealerA1));
            $this->get(route('distributor.devices'))->assertForbidden();
            $this->activatePost($d)->assertForbidden();
        }

        $this->as($this->login('DISTRIBUTOR', 'unlinked', null));
        $this->get(route('distributor.devices'))->assertForbidden();
        $this->activatePost($d)->assertForbidden();
        $this->assertSame(0, ActivatedDevice::count());
    }

    // ---- Step 5: device commands -----------------------------------------------------------------------

    private function giveImeis(): void
    {
        foreach (['veh-da' => '111111111111111', 'veh-a1' => '222222222222222', 'veh-b1' => '333333333333333', 'veh-stranger' => '999999999999999'] as $v => $imei) {
            DB::table('vehicle_ad')->where('vehicle_id', $v)->update(['imei' => $imei]);
        }
    }

    /** A fresh HTTP fake: the first matching stub wins, so the generic one from setUp must be dropped. */
    private function fakeHttp(array $stubs): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake($stubs);
    }

    private function sentCommands(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($request) => str_ends_with($request->url(), '/command'))
            ->map(fn ($request) => $request->data())
            ->values()->all();
    }

    public function test_command_center_lists_only_the_networks_vehicles(): void
    {
        $this->giveImeis();
        $this->asDistA();

        $html = $this->get(route('distributor.device-commands'))->assertOk()->getContent();
        $this->assertStringContainsString('WP-1111', $html);
        $this->assertStringContainsString('WP-2222', $html);
        $this->assertStringNotContainsString('WP-3333', $html);
        $this->assertStringNotContainsString('WP-9999', $html);
        $this->assertStringContainsString('/distributor/device-commands/send', $html);
    }

    public function test_distributor_commands_its_own_and_its_dealers_customers_and_it_is_audited(): void
    {
        $this->giveImeis();
        $this->fakeHttp(['*/command' => Http::response(['ok' => true], 200), '*' => Http::response([], 200)]);
        $this->asDistA();

        foreach (['111111111111111', '222222222222222'] as $imei) {
            $this->postJson(route('distributor.device-commands.send'), ['imei' => $imei, 'command' => 'where'])
                ->assertOk()->assertJson(['success' => true]);
        }

        $logs = AuditLog::where('action', 'device.command_sent')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('id-dist_a', $logs[0]->actor_id);
        $this->assertSame('DISTRIBUTOR', $logs[0]->actor_role);
        $this->assertSame('where', $logs[0]->meta['command']);
        $this->assertSame('delivered', $logs[0]->meta['result']);
        $this->assertSame('222222222222222', $logs[1]->subject_id);
    }

    public function test_distributor_cannot_command_or_inspect_devices_outside_its_network(): void
    {
        $this->giveImeis();
        $this->fakeHttp(['*' => Http::response([], 200)]);
        $this->asDistA();

        foreach (['333333333333333', '999999999999999', '555555555555555'] as $imei) {
            $this->postJson(route('distributor.device-commands.send'), ['imei' => $imei, 'command' => 'relay_on'])->assertForbidden();
            $this->getJson(route('distributor.device-commands.status', $imei))->assertForbidden();
        }
        foreach (['veh-b1', 'veh-stranger', 'nope'] as $v) {
            $this->getJson(route('distributor.device-commands.history', $v))->assertForbidden();
        }

        $this->assertSame([], $this->sentCommands());
        $this->assertSame(0, AuditLog::where('action', 'device.command_sent')->count());
        $this->assertGreaterThanOrEqual(3, AuditLog::where('action', 'device.command_denied')->count());
    }

    public function test_non_admins_cannot_send_commands_that_rewrite_a_device_and_cannot_attach_parameters(): void
    {
        $this->giveImeis();
        $this->fakeHttp(['*/command' => Http::response(['ok' => true], 200), '*' => Http::response([], 200)]);
        $this->asDistA();

        $this->postJson(route('distributor.device-commands.send'), ['imei' => '111111111111111', 'command' => 'url'])->assertStatus(422);
        $this->postJson(route('distributor.device-commands.send'), ['imei' => '111111111111111', 'command' => 'sos_add'])->assertStatus(422);

        $this->postJson(route('distributor.device-commands.send'), [
            'imei' => '111111111111111', 'command' => 'timer', 'params' => ['server' => 'evil.example.com'],
        ])->assertOk();

        $sent = $this->sentCommands();
        $this->assertCount(1, $sent);
        $this->assertSame([], $sent[0]['params']);

        // a dealer is held to the same list
        $this->as($this->login('DEALER', 'dealer_a1', $this->dealerA1));
        $this->postJson(route('dealer.device-commands.send'), ['imei' => '222222222222222', 'command' => 'url'])->assertStatus(422);
    }

    public function test_dealer_commands_are_audited_and_stay_limited_to_its_own_customers(): void
    {
        $this->giveImeis();
        $this->fakeHttp(['*/command' => Http::response(['ok' => true], 200), '*' => Http::response([], 200)]);
        $this->as($this->login('DEALER', 'dealer_a1', $this->dealerA1));

        $this->postJson(route('dealer.device-commands.send'), ['imei' => '222222222222222', 'command' => 'status'])->assertOk();
        $this->postJson(route('dealer.device-commands.send'), ['imei' => '111111111111111', 'command' => 'status'])->assertForbidden();

        $this->assertSame(1, AuditLog::where('action', 'device.command_sent')->count());
        $this->assertSame('DEALER', AuditLog::where('action', 'device.command_sent')->value('actor_role'));
        $this->assertSame(1, AuditLog::where('action', 'device.command_denied')->count());
    }

    public function test_offline_device_and_gateway_failures_are_audited_too(): void
    {
        $this->giveImeis();
        $this->fakeHttp(['*/command' => Http::response(['error' => 'offline'], 503), '*' => Http::response([], 200)]);
        $this->asDistA();

        $this->postJson(route('distributor.device-commands.send'), ['imei' => '111111111111111', 'command' => 'reset'])->assertStatus(503);

        $this->assertSame('device_offline', AuditLog::where('action', 'device.command_sent')->first()->meta['result']);
    }

    public function test_admin_keeps_every_command_and_is_not_filled_into_the_dealer_audit(): void
    {
        $this->giveImeis();
        $this->fakeHttp(['*/command' => Http::response(['ok' => true], 200), '*' => Http::response([], 200)]);
        $this->as($this->login('ADMIN', 'boss'));

        $this->postJson(route('admin.vehicles.device-commands.send'), ['imei' => '999999999999999', 'command' => 'url', 'params' => ['a' => 'b']])
            ->assertOk();

        $sent = $this->sentCommands();
        $this->assertSame(['a' => 'b'], $sent[0]['params']);
        $this->assertSame(0, AuditLog::where('action', 'device.command_sent')->count());
    }
}