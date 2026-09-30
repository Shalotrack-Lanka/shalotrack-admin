<?php

namespace Tests\Feature;

use App\Models\ActivatedDevice;
use App\Models\Admin;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A device registered without a SIM cannot be transferred to a dealer or bound to a customer
 * (both need a SIM). The admin gives it a spare Activated SIM on the Cancel Device page.
 */
class NoSimDeviceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();

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
            $t->string('status')->default('active');
            $t->timestamps();
        });
        Schema::create('dealer_transfer_ledgers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dealer_id');
            $t->string('device_category');
            $t->integer('quantity');
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
            $t->string('customer_name')->nullable();
            $t->string('vehicle_number')->nullable();
            $t->string('imei_number')->nullable();
            $t->string('sim_number')->nullable();
            $t->timestamps();
        });
        Schema::create('sims', function (Blueprint $t) {
            $t->id();
            $t->string('sim_number')->unique();
            $t->string('sim_type')->nullable();
            $t->string('imsi')->nullable();
            $t->string('iccid')->nullable();
            $t->boolean('activation_required')->default(false);
            $t->string('sim_status')->nullable();
            $t->timestamps();
        });

        $this->actingAs(Admin::forceCreate(['admin_id' => 'adm-1', 'username' => 'a', 'role' => 'ADMIN', 'status' => 'ACTIVE']));
    }

    private function device(string $imei, string $status, ?string $sim = null): SetupShalotrackDevice
    {
        return SetupShalotrackDevice::forceCreate([
            'device_category' => 'V10 Plus', 'imei_number' => $imei, 'sim_number' => $sim, 'status' => $status,
        ]);
    }

    private function spareSim(string $number, string $status = 'Activated'): Sim
    {
        return Sim::forceCreate([
            'sim_number' => $number, 'sim_type' => 'Dialog', 'imsi' => '4120' . $number, 'iccid' => '8994' . $number, 'sim_status' => $status,
        ]);
    }

    private function attach(SetupShalotrackDevice $d, string $sim)
    {
        return $this->from(route('admin.cancel_device.index'))
            ->patch(route('admin.cancel_device.attach_sim', $d->shdevice_id), ['sim_number' => $sim]);
    }

    public function test_attaching_a_spare_sim_fills_the_device_and_takes_the_sim_out_of_the_pool(): void
    {
        $d = $this->device('100000000000001', 'Activated');
        $this->spareSim('0771111111');

        $this->attach($d, '0771111111')->assertRedirect(route('admin.cancel_device.index'))->assertSessionHas('success');

        $d->refresh();
        $this->assertSame('0771111111', $d->sim_number);
        $this->assertSame('89940771111111', $d->iccid);
        $this->assertSame('41200771111111', $d->imsi);
        $this->assertSame(0, Sim::count(), 'the SIM leaves the spare pool, like at registration');
    }

    public function test_a_no_sim_device_becomes_transferable_only_after_a_sim_is_attached(): void
    {
        $d = $this->device('100000000000002', 'Activated');
        $this->spareSim('0772222222');

        $this->assertFalse(SetupShalotrackDevice::companyStockForTransfer()->whereKey($d->getKey())->exists());
        $this->assertTrue(SetupShalotrackDevice::companyStockWithoutSim()->whereKey($d->getKey())->exists());

        $this->attach($d, '0772222222');

        $this->assertTrue(SetupShalotrackDevice::companyStockForTransfer()->whereKey($d->getKey())->exists());
        $this->assertFalse(SetupShalotrackDevice::companyStockWithoutSim()->whereKey($d->getKey())->exists());
    }

    public function test_works_for_a_not_activated_device_too(): void
    {
        $d = $this->device('100000000000003', 'Not Activated');
        $this->spareSim('0773333333');

        $this->attach($d, '0773333333')->assertSessionHas('success');
        $this->assertSame('0773333333', $d->fresh()->sim_number);
    }

    public function test_a_device_that_already_has_a_sim_is_left_alone(): void
    {
        $d = $this->device('100000000000004', 'Activated', '0774444444');
        $this->spareSim('0775555555');

        $this->attach($d, '0775555555')->assertSessionHasErrors('sim_number');

        $this->assertSame('0774444444', $d->fresh()->sim_number);
        $this->assertSame(1, Sim::count(), 'the spare SIM is not consumed');
    }

    public function test_a_sim_that_is_not_activated_or_not_in_the_pool_is_refused(): void
    {
        $d = $this->device('100000000000005', 'Activated');
        $this->spareSim('0776666666', 'Not Activated');

        $this->attach($d, '0776666666')->assertSessionHasErrors('sim_number');
        $this->attach($d, '0779999999')->assertSessionHasErrors('sim_number');

        $this->assertNull($d->fresh()->sim_number);
        $this->assertSame(1, Sim::count());
    }

    public function test_a_device_already_on_a_customer_or_stopped_cannot_take_a_sim(): void
    {
        $bound = $this->device('100000000000006', 'Activated');
        ActivatedDevice::forceCreate(['vehicle_id' => 'v-1', 'imei_number' => '100000000000006', 'customer_name' => 'X', 'vehicle_number' => 'WP 1']);
        $stopped = $this->device('100000000000007', 'Temporarily Stopped');
        $this->spareSim('0777777777');

        $this->attach($bound, '0777777777')->assertSessionHasErrors('sim_number');
        $this->attach($stopped, '0777777777')->assertSessionHasErrors('sim_number');

        $this->assertNull($bound->fresh()->sim_number);
        $this->assertNull($stopped->fresh()->sim_number);
        $this->assertSame(1, Sim::count());
    }

    public function test_the_same_spare_sim_cannot_go_into_two_devices(): void
    {
        $a = $this->device('100000000000008', 'Activated');
        $b = $this->device('100000000000009', 'Activated');
        $this->spareSim('0778888888');

        $this->attach($a, '0778888888')->assertSessionHas('success');
        $this->attach($b, '0778888888')->assertSessionHasErrors('sim_number');

        $this->assertNull($b->fresh()->sim_number);
    }

    public function test_only_admins_can_attach_a_sim(): void
    {
        $d = $this->device('100000000000010', 'Activated');
        $this->spareSim('0770000001');
        $this->actingAs(Admin::forceCreate(['admin_id' => 'dlr', 'username' => 'd', 'role' => 'DEALER', 'status' => 'ACTIVE']));

        $this->patch(route('admin.cancel_device.attach_sim', $d->shdevice_id), ['sim_number' => '0770000001'])->assertForbidden();

        $this->assertNull($d->fresh()->sim_number);
    }

    public function test_cancel_device_page_offers_the_attach_form_only_where_it_applies(): void
    {
        $noSim   = $this->device('100000000000011', 'Activated');
        $withSim = $this->device('100000000000012', 'Activated', '0770000012');
        $pending = $this->device('100000000000013', 'Not Activated');
        $this->spareSim('0770000002');

        $html = $this->get(route('admin.cancel_device.index'))->assertOk()->getContent();

        // one form for the activated no-SIM device, one for the not-activated no-SIM device
        $this->assertSame(2, substr_count($html, 'data-attach-sim-form'));
        $this->assertStringContainsString(route('admin.cancel_device.attach_sim', $noSim->shdevice_id), $html);
        $this->assertStringContainsString(route('admin.cancel_device.attach_sim', $pending->shdevice_id), $html);
        $this->assertStringNotContainsString(route('admin.cancel_device.attach_sim', $withSim->shdevice_id), $html);
    }

    public function test_with_no_spare_sim_the_page_points_to_add_sim(): void
    {
        $this->device('100000000000014', 'Activated');

        $html = $this->get(route('admin.cancel_device.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-no-spare-sim', $html);
        $this->assertStringNotContainsString('data-attach-sim-form', $html);
    }

    public function test_stock_transfer_explains_why_activated_no_sim_devices_are_missing(): void
    {
        Schema::create('device_types', function (Blueprint $t) {
            $t->id();
            $t->timestamps();
        });
        $this->device('100000000000015', 'Activated');

        $html = $this->get(route('admin.stock_transfer'))->assertOk()->getContent();

        $this->assertStringContainsString('id="no-sim-hint"', $html);
        $this->assertStringContainsString('1 activated device(s) have no SIM', $html);
        $this->assertStringContainsString(route('admin.cancel_device.index'), $html);
    }
}
