<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DeviceType;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use App\Models\Stock;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Tests\TestCase;

/**
 * The project's full migration history can't build a fresh sqlite database,
 * so this test creates only the handful of tables the scan-intake feature
 * touches (columns mirror the real migrations).
 */
class ScanDeviceIntakeTest extends TestCase
{
    // Valid IMEIs (Luhn-correct) and one with a wrong check digit.
    private const IMEI_A = '355172106043787';
    private const IMEI_B = '490154203237518';
    private const IMEI_BAD = '355172106043788';

    private const ICCID_1 = '8994011234567890123';
    private const ICCID_2 = '8994011234567890124';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake(); // the API sync push must never hit the network in tests

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
    }

    private function actingAsRole(string $role): Admin
    {
        $admin = Admin::forceCreate([
            'admin_id' => 'adm-' . $role,
            'username' => strtolower($role),
            'full_name' => $role,
            'role' => $role,
            'status' => 'ACTIVE',
        ]);
        $this->actingAs($admin);

        return $admin;
    }

    private function type(int $stock = 5): DeviceType
    {
        $type = DeviceType::create(['device_category' => 'Shalotrack', 'model' => 'V5']);
        Stock::create(['device_type_id' => $type->id, 'company_available_stock' => $stock]);

        return $type;
    }

    private function sim(string $iccid, string $number, string $status = 'Activated'): Sim
    {
        return Sim::create([
            'sim_number' => $number, 'sim_type' => 'Dialog',
            'imsi' => '4130' . substr($iccid, -11), 'iccid' => $iccid, 'sim_status' => $status,
        ]);
    }

    public function test_non_admin_roles_cannot_use_scan_endpoints(): void
    {
        $type = $this->type();
        $this->actingAsRole('DEALER');

        $this->postJson(route('admin.device.scan.check'), ['imei' => self::IMEI_A])->assertForbidden();
        $this->postJson(route('admin.device.scan.commit'), [
            'device_type_id' => $type->id, 'rows' => [['imei' => self::IMEI_A]],
        ])->assertForbidden();

        $this->assertSame(0, SetupShalotrackDevice::count());
        $this->assertSame(5, Stock::first()->company_available_stock);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->postJson(route('admin.device.scan.commit'), [])->assertUnauthorized();
    }

    public function test_scan_page_renders_for_admin(): void
    {
        $this->type();
        $this->actingAsRole('ADMIN');

        $this->get(route('admin.device.scan'))->assertOk()->assertSee('Scan Device Intake');
    }

    public function test_check_flags_bad_check_digit_and_duplicates(): void
    {
        $type = $this->type();
        $this->actingAsRole('ADMIN');

        $this->postJson(route('admin.device.scan.check'), ['imei' => self::IMEI_BAD])
            ->assertOk()->assertJsonPath('imei.ok', false);

        $this->postJson(route('admin.device.scan.check'), ['imei' => self::IMEI_A])
            ->assertOk()->assertJsonPath('imei.ok', true);

        SetupShalotrackDevice::create([
            'device_type_id' => $type->id, 'device_category' => 'x', 'imei_number' => self::IMEI_A,
        ]);
        $this->postJson(route('admin.device.scan.check'), ['imei' => self::IMEI_A])
            ->assertJsonPath('imei.ok', false);
    }

    public function test_check_resolves_sim_number_from_iccid(): void
    {
        $this->actingAsRole('ADMIN');
        $this->sim(self::ICCID_1, '0771234567');
        $this->sim(self::ICCID_2, '0779999999', 'Not Activated');

        $this->postJson(route('admin.device.scan.check'), ['iccid' => self::ICCID_1])
            ->assertJsonPath('iccid.ok', true)->assertJsonPath('iccid.sim_number', '0771234567');
        $this->postJson(route('admin.device.scan.check'), ['iccid' => self::ICCID_2])
            ->assertJsonPath('iccid.ok', false);
        $this->postJson(route('admin.device.scan.check'), ['iccid' => '8994011234567890999'])
            ->assertJsonPath('iccid.ok', false);
    }

    public function test_commit_registers_devices_consumes_stock_and_snapshots_sim_identity(): void
    {
        $type = $this->type(5);
        $admin = $this->actingAsRole('ADMIN');
        $sim = $this->sim(self::ICCID_1, '0771234567');

        $res = $this->postJson(route('admin.device.scan.commit'), [
            'device_type_id' => $type->id,
            'rows' => [
                ['imei' => self::IMEI_A, 'iccid' => self::ICCID_1],
                ['imei' => self::IMEI_B],
            ],
        ])->assertOk();

        $res->assertJsonPath('created', 2)->assertJsonPath('failed', 0);

        $with = SetupShalotrackDevice::where('imei_number', self::IMEI_A)->first();
        $this->assertSame('0771234567', $with->sim_number);
        $this->assertSame(self::ICCID_1, $with->iccid);
        $this->assertSame($sim->imsi, $with->imsi);
        $this->assertSame('scan', $with->intake_source);
        $this->assertSame($admin->admin_id, $with->registered_by);
        $this->assertSame('Not Activated', $with->status);
        $this->assertSame('Shalotrack V5', $with->device_category);

        $this->assertNull(SetupShalotrackDevice::where('imei_number', self::IMEI_B)->first()->iccid);
        $this->assertSame(3, Stock::first()->company_available_stock);
        $this->assertSame(0, Sim::count(), 'consumed SIM leaves the available pool');

        Http::assertSentCount(2); // one API sync per created device
    }

    public function test_one_bad_row_does_not_abort_the_others(): void
    {
        $type = $this->type(5);
        $this->actingAsRole('ADMIN');

        $res = $this->postJson(route('admin.device.scan.commit'), [
            'device_type_id' => $type->id,
            'rows' => [
                ['imei' => self::IMEI_BAD],
                ['imei' => self::IMEI_A],
                ['imei' => self::IMEI_A],                       // duplicate in batch
                ['imei' => self::IMEI_B, 'iccid' => self::ICCID_1], // SIM not registered
            ],
        ])->assertOk();

        $res->assertJsonPath('created', 1)->assertJsonPath('failed', 3);
        $this->assertSame(1, SetupShalotrackDevice::count());
        $this->assertSame(4, Stock::first()->company_available_stock, 'only the successful row consumed stock');
        $this->assertFalse($res->json('results.0.ok'));
        $this->assertTrue($res->json('results.1.ok'));
    }

    public function test_out_of_stock_rows_fail_cleanly_without_partial_state(): void
    {
        $type = $this->type(1);
        $this->actingAsRole('ADMIN');
        $this->sim(self::ICCID_1, '0771234567');

        $res = $this->postJson(route('admin.device.scan.commit'), [
            'device_type_id' => $type->id,
            'rows' => [
                ['imei' => self::IMEI_A],
                ['imei' => self::IMEI_B, 'iccid' => self::ICCID_1],
            ],
        ])->assertOk();

        $res->assertJsonPath('created', 1)->assertJsonPath('failed', 1);
        $this->assertSame(0, Stock::first()->company_available_stock);
        $this->assertSame(1, Sim::count(), 'SIM of the failed row must NOT be consumed');
    }

    public function test_already_registered_imei_is_rejected_on_recommit(): void
    {
        $type = $this->type(5);
        $this->actingAsRole('ADMIN');
        $payload = ['device_type_id' => $type->id, 'rows' => [['imei' => self::IMEI_A]]];

        $this->postJson(route('admin.device.scan.commit'), $payload)->assertJsonPath('created', 1);
        $this->postJson(route('admin.device.scan.commit'), $payload)
            ->assertJsonPath('created', 0)->assertJsonPath('failed', 1);

        $this->assertSame(4, Stock::first()->company_available_stock, 'a retry must not consume stock twice');
    }

    public function test_commit_payload_limits_are_enforced(): void
    {
        $type = $this->type();
        $this->actingAsRole('ADMIN');

        $this->postJson(route('admin.device.scan.commit'), ['device_type_id' => $type->id, 'rows' => []])
            ->assertStatus(422);
        $this->postJson(route('admin.device.scan.commit'), [
            'device_type_id' => $type->id, 'rows' => [['imei' => 'abc']],
        ])->assertStatus(422);
        $this->postJson(route('admin.device.scan.commit'), [
            'device_type_id' => $type->id,
            'rows' => array_fill(0, 201, ['imei' => self::IMEI_A]),
        ])->assertStatus(422);
    }
    public function test_check_reports_why_a_sim_was_refused(): void
    {
        $type = $this->type();
        $this->actingAsRole('ADMIN');
        $this->sim(self::ICCID_2, '0779999999', 'Not Activated');

        $this->postJson(route('admin.device.scan.check'), ['iccid' => '8994031100000000031'])
            ->assertJsonPath('iccid.reason', 'not_registered');
        $this->postJson(route('admin.device.scan.check'), ['iccid' => self::ICCID_2])
            ->assertJsonPath('iccid.reason', 'not_activated');
        $this->postJson(route('admin.device.scan.check'), ['iccid' => '12345'])
            ->assertJsonPath('iccid.reason', 'invalid');

        SetupShalotrackDevice::create([
            'device_type_id' => $type->id, 'device_category' => 'x', 'imei_number' => self::IMEI_A,
            'iccid' => self::ICCID_1,
        ]);
        $this->postJson(route('admin.device.scan.check'), ['iccid' => self::ICCID_1])
            ->assertJsonPath('iccid.reason', 'in_use');
    }

    private function simPayload(array $over = []): array
    {
        return array_merge([
            'iccid' => self::ICCID_1, 'sim_number' => '0700000101', 'imsi' => '413020000000101',
            'sim_type' => 'Dialog', 'confirm_activated' => true,
        ], $over);
    }

    public function test_quick_add_sim_saves_an_activated_sim_that_can_then_be_paired(): void
    {
        $type = $this->type();
        $this->actingAsRole('ADMIN');

        $this->postJson(route('admin.device.scan.sim'), $this->simPayload())
            ->assertCreated()->assertJsonPath('sim_number', '0700000101');

        $sim = Sim::where('iccid', self::ICCID_1)->first();
        $this->assertSame('Activated', $sim->sim_status);
        $this->assertSame('413020000000101', $sim->imsi);

        $this->postJson(route('admin.device.scan.check'), ['iccid' => self::ICCID_1])
            ->assertJsonPath('iccid.ok', true);

        $this->postJson(route('admin.device.scan.commit'), [
            'device_type_id' => $type->id, 'rows' => [['imei' => self::IMEI_A, 'iccid' => self::ICCID_1]],
        ])->assertJsonPath('created', 1);
        $this->assertSame('0700000101', SetupShalotrackDevice::first()->sim_number);
    }

    public function test_quick_add_sim_requires_carrier_activation_confirmation(): void
    {
        $this->actingAsRole('ADMIN');

        $this->postJson(route('admin.device.scan.sim'), $this->simPayload(['confirm_activated' => false]))
            ->assertStatus(422)->assertJsonValidationErrors('confirm_activated');
        $this->postJson(route('admin.device.scan.sim'), array_diff_key($this->simPayload(), ['confirm_activated' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('confirm_activated');

        $this->assertSame(0, Sim::count());
    }

    public function test_quick_add_sim_validates_lengths_and_uniqueness(): void
    {
        $this->actingAsRole('ADMIN');
        $this->sim(self::ICCID_2, '0779999999');

        $this->postJson(route('admin.device.scan.sim'), $this->simPayload(['sim_number' => '12345']))
            ->assertStatus(422)->assertJsonValidationErrors('sim_number');
        $this->postJson(route('admin.device.scan.sim'), $this->simPayload(['imsi' => '123']))
            ->assertStatus(422)->assertJsonValidationErrors('imsi');
        $this->postJson(route('admin.device.scan.sim'), $this->simPayload(['iccid' => '123']))
            ->assertStatus(422)->assertJsonValidationErrors('iccid');
        $this->postJson(route('admin.device.scan.sim'), $this->simPayload(['sim_type' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('sim_type');
        // already registered ICCID / number
        $this->postJson(route('admin.device.scan.sim'), $this->simPayload(['iccid' => self::ICCID_2]))
            ->assertStatus(422)->assertJsonValidationErrors('iccid');
        $this->postJson(route('admin.device.scan.sim'), $this->simPayload(['sim_number' => '0779999999']))
            ->assertStatus(422)->assertJsonValidationErrors('sim_number');

        $this->assertSame(1, Sim::count());
    }

    public function test_quick_add_sim_is_admin_only(): void
    {
        $this->actingAsRole('DEALER');

        $this->postJson(route('admin.device.scan.sim'), $this->simPayload())->assertForbidden();
        $this->assertSame(0, Sim::count());
    }
}
