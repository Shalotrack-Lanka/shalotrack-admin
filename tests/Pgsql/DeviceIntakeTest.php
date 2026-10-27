<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Dealer;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use App\Models\Stock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * DEVICE INTAKE: a new physical GPS device enters the system. Two ways in, on real PostgreSQL.
 *   Manual form       POST admin.device.store                (one device, with or without a SIM)
 *   Barcode scan      POST admin.device.scan.check / commit / sim   (a batch of up to 200, scanner or phone)
 *
 * Why it matters (simple words): bad data that gets in here is hard to fix later. The rules the portal promises:
 *   - One unit of company stock is used per device, and a device is never saved when there is no stock left.
 *   - The SIM must be an Activated SIM from the pool. It leaves the pool and its ICCID and IMSI are copied onto the device.
 *   - It is all or nothing for each device: if any rule fails, stock, SIM and device are exactly as before.
 *   - An IMEI can only be registered once. A scanned IMEI must have a correct check digit (catches misreads).
 *   - A bad row in a scanned batch never stops the good rows.
 *   - Only an ADMIN may do any of this.
 *
 * The app server is FAKED (the portal tells it about every new device). One login per test. Everything is rolled back.
 */
class DeviceIntakeTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeIntakeApi();
    }

    // ------------------------------------------------------------------ helpers

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function makeIntakeDealer(): Dealer
    {
        return Dealer::create([
            'full_name'     => 'Autotest Dealer ' . $this->nextIntakeNumber(),
            'dealer_status' => 'distributor',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => 'autotest+intake-' . $this->nextIntakeNumber() . '@example.test',
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    private function devicesWithImei(string $imei)
    {
        return SetupShalotrackDevice::where('imei_number', $imei)->get();
    }

    /** How many devices made by these tests exist (all test IMEIs start with 9990). */
    private function testDeviceCount(): int
    {
        return SetupShalotrackDevice::where('imei_number', 'like', '9990%')->count();
    }

    private function addDevice(array $form)
    {
        return $this->actingAs($this->admin())->post(route('admin.device.store'), $form);
    }

    private function commit(array $payload)
    {
        return $this->actingAs($this->admin())->postJson(route('admin.device.scan.commit'), $payload);
    }

    private function check(array $payload)
    {
        return $this->actingAs($this->admin())->postJson(route('admin.device.scan.check'), $payload);
    }

    private function quickAdd(array $payload)
    {
        return $this->actingAs($this->admin())->postJson(route('admin.device.scan.sim'), $payload);
    }

    // ================================================================== MANUAL FORM

    public function test_a_device_is_registered_with_a_sim_and_the_sim_leaves_the_pool(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 3);
        $sim = $this->makeSim();
        $imei = $this->validImei();

        $this->addDevice(['device_type_id' => $type->id, 'imei_number' => $imei, 'sim_number' => $sim->sim_number])
            ->assertRedirect(route('admin.setup-device'))
            ->assertSessionHas('success', 'Device Setup Completed Successfully!')
            ->assertSessionMissing('warning');

        $device = $this->devicesWithImei($imei)->sole();
        $this->assertSame($type->device_category . ' ' . $type->model, $device->device_category);
        $this->assertSame($type->id, (int) $device->device_type_id);
        $this->assertSame('Not Activated', $device->status);
        $this->assertNull($device->dealer_id, 'a new device starts in company stock');
        $this->assertSame($sim->sim_number, $device->sim_number);
        $this->assertSame($sim->iccid, $device->iccid, 'the SIM identity is copied onto the device');
        $this->assertSame($sim->imsi, $device->imsi);

        $this->assertNull(Sim::find($sim->id), 'the SIM has left the pool');
        $this->assertSame(2, $stock->fresh()->company_available_stock, 'exactly one unit of stock is used');

        // The app server was told about the new device, once.
        $this->assertCount(1, $this->syncCalls);
        $this->assertSame($imei, $this->syncCalls[0]['imeiNumber']);
        $this->assertSame('Not Activated', $this->syncCalls[0]['status']);
    }

    public function test_a_device_can_be_registered_without_a_sim(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 1);
        $imei = $this->validImei();

        $this->addDevice(['device_type_id' => $type->id, 'imei_number' => $imei])
            ->assertSessionHas('success');

        $device = $this->devicesWithImei($imei)->sole();
        $this->assertNull($device->sim_number);
        $this->assertNull($device->iccid);
        $this->assertSame(0, $stock->fresh()->company_available_stock);
    }

    #[DataProvider('brokenAppServers')]
    public function test_when_the_app_server_cannot_be_told_the_device_is_still_saved_and_the_admin_is_warned(string $mode): void
    {
        $this->syncMode = $mode;
        $type = $this->makeDeviceType();
        $this->stockFor($type, 1);
        $imei = $this->validImei();

        $this->addDevice(['device_type_id' => $type->id, 'imei_number' => $imei])
            ->assertSessionHas('success')
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'could not be synced'));

        $this->assertCount(1, $this->devicesWithImei($imei), 'nothing is lost when the push fails');
    }

    public static function brokenAppServers(): array
    {
        return ['connection fails' => ['down'], 'app server answers 500' => ['error']];
    }

    public function test_no_stock_means_no_device_and_the_sim_stays_in_the_pool(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 0);
        $sim = $this->makeSim();
        $imei = $this->validImei();

        $this->addDevice(['device_type_id' => $type->id, 'imei_number' => $imei, 'sim_number' => $sim->sim_number])
            ->assertSessionHasErrors(['device_type_id' => 'No Company Available Stock left for this Device Category / Type.']);

        $this->assertCount(0, $this->devicesWithImei($imei));
        $this->assertSame(0, $stock->fresh()->company_available_stock);
        $this->assertNotNull(Sim::find($sim->id), 'the SIM must not be used up by a failed registration');
        $this->assertSame([], $this->syncCalls);
    }

    public function test_a_device_type_that_has_no_stock_record_at_all_cannot_be_registered(): void
    {
        $type = $this->makeDeviceType();   // no stock row made
        $imei = $this->validImei();

        $this->addDevice(['device_type_id' => $type->id, 'imei_number' => $imei])
            ->assertSessionHasErrors('device_type_id');

        $this->assertCount(0, $this->devicesWithImei($imei));
    }

    /** Words in a form value are replaced by real test data: {type} {imei} {sim} {sim_off} {dup_imei} */
    public static function badManualForms(): array
    {
        return [
            'device type missing'          => [['device_type_id' => '__missing__'], 'device_type_id'],
            'device type does not exist'   => [['device_type_id' => '987654321'], 'device_type_id'],
            'device type is text'          => [['device_type_id' => 'abc'], 'device_type_id'],
            'device type too big for db'   => [['device_type_id' => '99999999999999999999'], 'device_type_id'],
            'device type is a list'        => [['device_type_id' => ['{type}']], 'device_type_id'],
            'imei missing'                 => [['imei_number' => '__missing__'], 'imei_number'],
            'imei has 14 digits'           => [['imei_number' => '12345678901234'], 'imei_number'],
            'imei has 16 digits'           => [['imei_number' => '1234567890123456'], 'imei_number'],
            'imei has a letter'            => [['imei_number' => '86487617098140A'], 'imei_number'],
            'imei is a list'               => [['imei_number' => ['{imei}']], 'imei_number'],
            'imei already registered'      => [['imei_number' => '{dup_imei}'], 'imei_number'],
            'sim has 9 digits'             => [['sim_number' => '070123456'], 'sim_number'],
            'sim has 11 digits'            => [['sim_number' => '07012345678'], 'sim_number'],
            'sim has a letter'             => [['sim_number' => '070123456A'], 'sim_number'],
            'sim is not in the pool'       => [['sim_number' => '0709999999'], 'sim_number'],
            'sim is not activated'         => [['sim_number' => '{sim_off}'], 'sim_number'],
            'sim is a list'                => [['sim_number' => ['{sim}']], 'sim_number'],
        ];
    }

    #[DataProvider('badManualForms')]
    public function test_a_bad_manual_form_is_refused_and_nothing_changes(array $overrides, string $errorField): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 5);
        $simOn = $this->makeSim('Activated');
        $simOff = $this->makeSim('Not Activated');

        $existing = $this->validImei();
        SetupShalotrackDevice::create([
            'device_type_id' => $type->id, 'device_category' => 'x', 'imei_number' => $existing, 'status' => 'Not Activated',
        ]);

        $freshImei = $this->validImei();
        $form = array_merge([
            'device_type_id' => $type->id,
            'imei_number'    => $freshImei,
            'sim_number'     => $simOn->sim_number,
        ], $overrides);

        $tokens = ['{type}' => (string) $type->id, '{imei}' => $freshImei, '{sim}' => $simOn->sim_number,
                   '{sim_off}' => $simOff->sim_number, '{dup_imei}' => $existing];
        array_walk_recursive($form, function (&$v) use ($tokens) {
            $v = is_string($v) ? strtr($v, $tokens) : $v;
        });
        $form = array_filter($form, fn ($v) => $v !== '__missing__');

        $this->addDevice($form)->assertSessionHasErrors($errorField);

        $this->assertSame(1, $this->testDeviceCount(), 'only the device that existed before may exist');
        $this->assertSame(5, $stock->fresh()->company_available_stock, 'stock must be untouched');
        $this->assertNotNull(Sim::find($simOn->id), 'the activated SIM must still be in the pool');
        $this->assertNotNull(Sim::find($simOff->id));
        $this->assertSame([], $this->syncCalls);
    }

    public function test_the_same_imei_cannot_be_registered_twice(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 5);
        $imei = $this->validImei();

        $this->addDevice(['device_type_id' => $type->id, 'imei_number' => $imei])->assertSessionHas('success');
        $this->addDevice(['device_type_id' => $type->id, 'imei_number' => $imei])->assertSessionHasErrors('imei_number');

        $this->assertCount(1, $this->devicesWithImei($imei));
        $this->assertSame(4, $stock->fresh()->company_available_stock, 'only the first one used stock');
    }

    public function test_the_same_sim_cannot_go_into_two_devices(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 5);
        $sim = $this->makeSim();

        $this->addDevice(['device_type_id' => $type->id, 'imei_number' => $this->validImei(), 'sim_number' => $sim->sim_number])
            ->assertSessionHas('success');
        $this->addDevice(['device_type_id' => $type->id, 'imei_number' => $this->validImei(), 'sim_number' => $sim->sim_number])
            ->assertSessionHasErrors('sim_number');

        $this->assertSame(1, $this->testDeviceCount());
        $this->assertSame(4, $stock->fresh()->company_available_stock);
    }

    public function test_the_setup_page_lists_unsold_devices_and_activated_sims_only(): void
    {
        $type = $this->makeDeviceType();
        $mine = $this->validImei();
        $sold = $this->validImei();
        $dealer = $this->makeIntakeDealer();
        SetupShalotrackDevice::create(['device_type_id' => $type->id, 'device_category' => 'x', 'imei_number' => $mine, 'status' => 'Not Activated']);
        SetupShalotrackDevice::create(['device_type_id' => $type->id, 'device_category' => 'x', 'imei_number' => $sold, 'status' => 'Activated', 'dealer_id' => $dealer->id]);
        $simOn = $this->makeSim('Activated');
        $simOff = $this->makeSim('Not Activated');

        $this->actingAs($this->admin())->get(route('admin.setup-device'))
            ->assertOk()
            ->assertViewHas('devices', fn ($devices) => $devices->pluck('imei_number')->contains($mine) && ! $devices->pluck('imei_number')->contains($sold))
            ->assertViewHas('activatedSimNumbers', fn ($sims) => $sims->contains($simOn->sim_number) && ! $sims->contains($simOff->sim_number))
            ->assertViewHas('deviceTypes', fn ($types) => $types->pluck('id')->contains($type->id));
    }

    public function test_the_device_list_json_shows_only_unsold_devices_and_no_sim_identity(): void
    {
        $type = $this->makeDeviceType();
        $mine = $this->validImei();
        $sold = $this->validImei();
        $dealer = $this->makeIntakeDealer();
        SetupShalotrackDevice::create(['device_type_id' => $type->id, 'device_category' => 'x', 'imei_number' => $mine, 'sim_number' => '0701111111', 'iccid' => '89949000000000099999', 'imsi' => '412990000099999', 'status' => 'Not Activated']);
        SetupShalotrackDevice::create(['device_type_id' => $type->id, 'device_category' => 'x', 'imei_number' => $sold, 'status' => 'Activated', 'dealer_id' => $dealer->id]);

        $rows = collect($this->actingAs($this->admin())->getJson(route('admin.device.list'))->assertOk()->json());

        $this->assertTrue($rows->pluck('imei_number')->contains($mine));
        $this->assertFalse($rows->pluck('imei_number')->contains($sold));
        $row = $rows->firstWhere('imei_number', $mine);
        $this->assertArrayNotHasKey('iccid', $row, 'the SIM serial must not be in this list');
        $this->assertArrayNotHasKey('imsi', $row);
    }

    // ================================================================== SCAN SCREEN: check

    public function test_a_scanned_imei_is_checked_for_format_check_digit_and_duplicates(): void
    {
        $good = $this->validImei();
        $registered = $this->validImei();
        SetupShalotrackDevice::create(['device_category' => 'x', 'imei_number' => $registered, 'status' => 'Not Activated']);

        $this->check(['imei' => $good])->assertOk()->assertExactJson(['imei' => ['ok' => true]]);

        $bad = $this->check(['imei' => $this->imeiWithBadCheckDigit($good)])->assertOk()->json('imei');
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString('Not a valid IMEI', $bad['message']);

        $dup = $this->check(['imei' => $registered])->assertOk()->json('imei');
        $this->assertFalse($dup['ok']);
        $this->assertStringContainsString('already registered', $dup['message']);

        foreach (['12345678901234', '86487617098140A', '1234567890123456'] as $junk) {
            $this->assertFalse($this->check(['imei' => $junk])->assertOk()->json('imei.ok'), "'{$junk}'");
        }
    }

    public function test_a_scanned_sim_is_checked_and_the_reason_is_explained(): void
    {
        $ready = $this->makeSim('Activated');
        $off = $this->makeSim('Not Activated');
        $onDevice = $this->simIdentity();
        SetupShalotrackDevice::create(['device_category' => 'x', 'imei_number' => $this->validImei(), 'iccid' => $onDevice['iccid'], 'status' => 'Not Activated']);

        $r = $this->check(['iccid' => $ready->iccid])->assertOk()->json('iccid');
        $this->assertTrue($r['ok']);
        $this->assertSame($ready->sim_number, $r['sim_number']);

        $this->assertSame('not_activated', $this->check(['iccid' => $off->iccid])->json('iccid.reason'));
        $this->assertSame('in_use', $this->check(['iccid' => $onDevice['iccid']])->json('iccid.reason'));
        $this->assertSame('not_registered', $this->check(['iccid' => '89949999999999999999'])->json('iccid.reason'));
        $this->assertSame('invalid', $this->check(['iccid' => '123'])->json('iccid.reason'));
        $this->assertSame('invalid', $this->check(['iccid' => '8994900000000000000A'])->json('iccid.reason'));
    }

    public function test_the_check_answers_nothing_when_nothing_is_sent_and_refuses_oversized_input(): void
    {
        $this->check([])->assertOk()->assertExactJson([]);

        $this->check(['imei' => str_repeat('1', 33)])->assertStatus(422);
        $this->check(['iccid' => str_repeat('1', 33)])->assertStatus(422);
        $this->check(['imei' => ['1']])->assertStatus(422);
    }

    public function test_checking_never_changes_anything(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 2);
        $sim = $this->makeSim();

        $this->check(['imei' => $this->validImei(), 'iccid' => $sim->iccid])->assertOk();

        $this->assertSame(0, $this->testDeviceCount());
        $this->assertSame(2, $stock->fresh()->company_available_stock);
        $this->assertNotNull(Sim::find($sim->id));
    }

    // ================================================================== SCAN SCREEN: commit

    public function test_a_scanned_batch_registers_every_device_and_is_audited(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 5);
        $simA = $this->makeSim();
        $simB = $this->makeSim();
        [$a, $b, $c] = [$this->validImei(), $this->validImei(), $this->validImei()];

        $response = $this->commit([
            'device_type_id' => $type->id,
            'rows' => [
                ['imei' => $a, 'iccid' => $simA->iccid],
                ['imei' => $b, 'iccid' => $simB->iccid],
                ['imei' => $c],
            ],
        ])->assertOk();

        $this->assertSame(3, $response->json('created'));
        $this->assertSame(0, $response->json('failed'));
        $this->assertSame(0, $response->json('sync_failed'));

        foreach ([$a, $b, $c] as $imei) {
            $device = $this->devicesWithImei($imei)->sole();
            $this->assertSame('Not Activated', $device->status);
            $this->assertSame('scan', $device->intake_source);
            $this->assertSame((string) $this->admin()->admin_id, (string) $device->registered_by, 'who scanned it is recorded');
            $this->assertNull($device->dealer_id);
        }
        $this->assertSame($simA->iccid, $this->devicesWithImei($a)->sole()->iccid);
        $this->assertSame($simA->sim_number, $this->devicesWithImei($a)->sole()->sim_number);
        $this->assertNull($this->devicesWithImei($c)->sole()->iccid);

        $this->assertNull(Sim::find($simA->id));
        $this->assertNull(Sim::find($simB->id));
        $this->assertSame(2, $stock->fresh()->company_available_stock);
        $this->assertCount(3, $this->syncCalls, 'the app server hears about each device');

        $logs = AuditLog::where('action', 'device.intake_committed')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('device_type', $logs[0]->subject_type);
        $this->assertSame(3, $logs[0]->meta['created']);
        $this->assertSame(3, $logs[0]->meta['submitted']);
    }

    public function test_one_bad_row_never_stops_the_good_rows_and_each_row_says_why(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 20);

        $good1 = $this->validImei();
        $good2 = $this->validImei();
        $already = $this->validImei();
        SetupShalotrackDevice::create(['device_type_id' => $type->id, 'device_category' => 'x', 'imei_number' => $already, 'status' => 'Not Activated']);

        $simShared = $this->makeSim();
        $simOff = $this->makeSim('Not Activated');
        $simUsed = $this->simIdentity();   // the SIM row is gone: it is already inside another device
        SetupShalotrackDevice::create(['device_category' => 'x', 'imei_number' => $this->validImei(), 'iccid' => $simUsed['iccid'], 'status' => 'Not Activated']);
        $realBefore = $this->testDeviceCount();

        $rows = [
            0 => ['imei' => $good1],
            1 => ['imei' => $this->imeiWithBadCheckDigit($this->validImei())],
            2 => ['imei' => $good1],                                         // repeat inside the batch
            3 => ['imei' => $already],
            4 => ['imei' => $this->validImei(), 'iccid' => $simShared->iccid],
            5 => ['imei' => $this->validImei(), 'iccid' => $simShared->iccid],   // same SIM again inside the batch
            6 => ['imei' => $this->validImei(), 'iccid' => '89949999999999999998'],  // SIM never registered
            7 => ['imei' => $this->validImei(), 'iccid' => $simOff->iccid],           // SIM not activated
            8 => ['imei' => $this->validImei(), 'iccid' => $simUsed['iccid']],        // SIM already inside a device
            9 => ['imei' => $good2],
        ];

        $response = $this->commit(['device_type_id' => $type->id, 'rows' => array_values($rows)])->assertOk();
        $results = collect($response->json('results'))->keyBy('index');

        $expect = [
            0 => [true, null],
            1 => [false, 'check digit'],
            2 => [false, 'Duplicate IMEI within this batch'],
            3 => [false, 'already registered'],
            4 => [true, null],
            5 => [false, 'Duplicate SIM'],
            6 => [false, 'not registered in the system'],
            7 => [false, 'not in Activated status'],
            8 => [false, 'not registered in the system'],
            9 => [true, null],
        ];
        foreach ($expect as $i => [$ok, $words]) {
            $this->assertSame($ok, $results[$i]['ok'], "row {$i}");
            if ($words !== null) {
                $this->assertStringContainsString($words, $results[$i]['message'], "row {$i}");
            }
        }

        $this->assertSame(3, $response->json('created'));   // rows 0, 4 and 9
        $this->assertSame(7, $response->json('failed'));
        $this->assertSame($realBefore + 3, $this->testDeviceCount());
        $this->assertSame(17, $stock->fresh()->company_available_stock, 'only the saved devices used stock');
        $this->assertNull(Sim::where('iccid', $simShared->iccid)->first(), 'the shared SIM went into the first device only');
        $this->assertNotNull(Sim::find($simOff->id), 'a refused SIM stays in the pool');
    }

    public function test_the_batch_stops_using_stock_when_it_runs_out(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 1);
        [$a, $b] = [$this->validImei(), $this->validImei()];

        $response = $this->commit(['device_type_id' => $type->id, 'rows' => [['imei' => $a], ['imei' => $b]]])->assertOk();

        $this->assertSame(1, $response->json('created'));
        $this->assertSame(1, $response->json('failed'));
        $this->assertStringContainsString('No Company Available Stock', $response->json('results.1.message'));
        $this->assertCount(1, $this->devicesWithImei($a));
        $this->assertCount(0, $this->devicesWithImei($b));
        $this->assertSame(0, $stock->fresh()->company_available_stock, 'stock never goes below zero');
    }

    public function test_a_batch_for_a_type_with_no_stock_record_creates_nothing(): void
    {
        $type = $this->makeDeviceType();
        $imei = $this->validImei();

        $response = $this->commit(['device_type_id' => $type->id, 'rows' => [['imei' => $imei]]])->assertOk();

        $this->assertSame(0, $response->json('created'));
        $this->assertCount(0, $this->devicesWithImei($imei));
        $this->assertSame(0, AuditLog::where('action', 'device.intake_committed')->count(), 'nothing was committed, so nothing is audited');
    }

    public function test_when_the_app_server_is_down_scanned_devices_are_saved_and_the_count_of_failed_syncs_is_reported(): void
    {
        $this->syncMode = 'down';
        $type = $this->makeDeviceType();
        $this->stockFor($type, 2);

        $response = $this->commit(['device_type_id' => $type->id, 'rows' => [['imei' => $this->validImei()], ['imei' => $this->validImei()]]])->assertOk();

        $this->assertSame(2, $response->json('created'));
        $this->assertSame(2, $response->json('sync_failed'));
        $this->assertSame(2, $this->testDeviceCount());
    }

    public static function badCommitBodies(): array
    {
        $imei = '490154203237518';

        return [
            'device type missing'         => [['device_type_id' => '__missing__']],
            'device type does not exist'  => [['device_type_id' => 987654321]],
            'device type is text'         => [['device_type_id' => 'abc']],
            'device type too big'         => [['device_type_id' => '99999999999999999999']],
            'device type is a list'       => [['device_type_id' => [1, 2]]],
            'no rows'                     => [['rows' => []]],
            'rows is text'                => [['rows' => 'x']],
            'row is text'                 => [['rows' => ['x']]],
            'row without imei'            => [['rows' => [['iccid' => '8994900000000000001']]]],
            'imei has 14 digits'          => [['rows' => [['imei' => '49015420323751']]]],
            'imei has a letter'           => [['rows' => [['imei' => '49015420323751A']]]],
            'imei is a list'              => [['rows' => [['imei' => [$imei]]]]],
            'iccid has 18 digits'         => [['rows' => [['imei' => $imei, 'iccid' => '899490000000000001']]]],
            'iccid has 21 digits'         => [['rows' => [['imei' => $imei, 'iccid' => '899490000000000000001']]]],
            'iccid has a letter'          => [['rows' => [['imei' => $imei, 'iccid' => '8994900000000000A01']]]],
            'more than 200 rows'          => [['rows' => array_fill(0, 201, ['imei' => $imei])]],
        ];
    }

    #[DataProvider('badCommitBodies')]
    public function test_a_bad_scan_batch_is_refused_whole_and_nothing_changes(array $overrides): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 5);

        $body = array_merge(['device_type_id' => $type->id, 'rows' => [['imei' => $this->validImei()]]], $overrides);
        $body = array_filter($body, fn ($v) => $v !== '__missing__');

        $this->commit($body)->assertStatus(422);

        $this->assertSame(0, $this->testDeviceCount());
        $this->assertSame(5, $stock->fresh()->company_available_stock);
        $this->assertSame([], $this->syncCalls);
    }

    public function test_exactly_200_rows_is_the_largest_batch_accepted(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 200);
        $rows = [];
        for ($i = 0; $i < 200; $i++) {
            $rows[] = ['imei' => $this->validImei()];
        }

        $response = $this->commit(['device_type_id' => $type->id, 'rows' => $rows])->assertOk();

        $this->assertSame(200, $response->json('created'));
        $this->assertSame(0, $stock->fresh()->company_available_stock);
    }

    public function test_the_commit_route_is_rate_limited(): void
    {
        Cache::flush();
        $this->actingAs($this->admin());

        for ($i = 1; $i <= 20; $i++) {
            $this->postJson(route('admin.device.scan.commit'), ['rows' => []])->assertStatus(422);
        }
        $this->postJson(route('admin.device.scan.commit'), ['rows' => []])->assertStatus(429);
    }

    // ================================================================== SCAN SCREEN: add a SIM on the spot

    private function simForm(array $overrides = []): array
    {
        return array_merge($this->simIdentity(), ['sim_type' => 'Dialog', 'confirm_activated' => true], $overrides);
    }

    public function test_a_scanned_sim_can_be_added_on_the_spot_and_is_then_usable(): void
    {
        $form = $this->simForm(['sim_type' => '  Mobitel  ']);

        $this->quickAdd($form)->assertStatus(201)->assertJson(['ok' => true, 'sim_number' => $form['sim_number'], 'iccid' => $form['iccid']]);

        $sim = Sim::where('iccid', $form['iccid'])->sole();
        $this->assertSame('Activated', $sim->sim_status);
        $this->assertFalse($sim->activation_required);
        $this->assertSame('Mobitel', $sim->sim_type, 'spaces around the type are removed');
        $this->assertSame($form['imsi'], $sim->imsi);

        $this->assertTrue($this->check(['iccid' => $form['iccid']])->json('iccid.ok'), 'the scan check now accepts it');
    }

    #[DataProvider('yesAnswers')]
    public function test_every_kind_of_yes_confirms_the_carrier_activation(mixed $yes): void
    {
        $this->quickAdd($this->simForm(['confirm_activated' => $yes]))->assertStatus(201);
    }

    public static function yesAnswers(): array
    {
        return ['true' => [true], 'one' => [1], 'text one' => ['1'], 'yes' => ['yes'], 'on' => ['on']];
    }

    #[DataProvider('badQuickSimForms')]
    public function test_a_bad_quick_sim_is_refused_and_nothing_is_saved(array $overrides): void
    {
        $taken = $this->makeSim();
        $before = Sim::count();

        $tokens = ['{taken_number}' => $taken->sim_number, '{taken_imsi}' => $taken->imsi, '{taken_iccid}' => $taken->iccid];
        $overrides = array_map(fn ($v) => is_string($v) ? strtr($v, $tokens) : $v, $overrides);
        $form = array_filter($this->simForm($overrides), fn ($v) => $v !== '__missing__');

        $this->quickAdd($form)->assertStatus(422);

        $this->assertSame($before, Sim::count());
    }

    public static function badQuickSimForms(): array
    {
        return [
            'not confirmed'            => [['confirm_activated' => '__missing__']],
            'confirmation is no'       => [['confirm_activated' => 'no']],
            'confirmation is false'    => [['confirm_activated' => false]],
            'iccid missing'            => [['iccid' => '__missing__']],
            'iccid 18 digits'          => [['iccid' => '899490000000000001']],
            'iccid 21 digits'          => [['iccid' => '899490000000000000001']],
            'iccid has a letter'       => [['iccid' => '8994900000000000A01']],
            'iccid already registered' => [['iccid' => '{taken_iccid}']],
            'number missing'           => [['sim_number' => '__missing__']],
            'number 9 digits'          => [['sim_number' => '070123456']],
            'number 11 digits'         => [['sim_number' => '07012345678']],
            'number already registered' => [['sim_number' => '{taken_number}']],
            'imsi missing'             => [['imsi' => '__missing__']],
            'imsi 14 digits'           => [['imsi' => '41299000000001']],
            'imsi 16 digits'           => [['imsi' => '4129900000000001A']],
            'imsi already registered'  => [['imsi' => '{taken_imsi}']],
            'type missing'             => [['sim_type' => '__missing__']],
            'type blank'               => [['sim_type' => '   ']],
        ];
    }

    public function test_a_sim_type_longer_than_255_characters_is_refused(): void
    {
        $before = Sim::count();

        $this->quickAdd($this->simForm(['sim_type' => str_repeat('d', 256)]))->assertStatus(422);
        $this->quickAdd($this->simForm(['sim_type' => str_repeat('d', 255)]))->assertStatus(201);

        $this->assertSame($before + 1, Sim::count());
    }

    // ================================================================== who may use it

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_nobody_but_an_admin_can_register_devices_or_use_the_scan_screen(string $role): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 5);
        $sim = $this->makeSim();
        $imei = $this->validImei();
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.setup-device'))->assertForbidden();
        $this->get(route('admin.device.list'))->assertForbidden();
        $this->get(route('admin.device.scan'))->assertForbidden();
        $this->post(route('admin.device.store'), ['device_type_id' => $type->id, 'imei_number' => $imei])->assertForbidden();
        $this->post(route('admin.device.scan.check'), ['imei' => $imei])->assertForbidden();
        $this->post(route('admin.device.scan.commit'), ['device_type_id' => $type->id, 'rows' => [['imei' => $imei]]])->assertForbidden();
        $this->post(route('admin.device.scan.sim'), array_merge($this->simForm(), ['sim_number' => '0700000001']))->assertForbidden();

        $this->assertSame(0, $this->testDeviceCount());
        $this->assertSame(5, $stock->fresh()->company_available_stock);
        $this->assertNotNull(Sim::find($sim->id));
        $this->assertSame([], $this->syncCalls);
    }

    public function test_a_logged_out_visitor_is_sent_to_login_everywhere(): void
    {
        $this->get(route('admin.setup-device'))->assertRedirect('/login');
        $this->get(route('admin.device.scan'))->assertRedirect('/login');
        $this->post(route('admin.device.store'), [])->assertRedirect('/login');
        $this->post(route('admin.device.scan.check'), [])->assertRedirect('/login');
        $this->post(route('admin.device.scan.commit'), [])->assertRedirect('/login');
        $this->post(route('admin.device.scan.sim'), [])->assertRedirect('/login');
    }

    public function test_the_scan_page_opens_for_an_admin_and_lists_device_types(): void
    {
        $type = $this->makeDeviceType();

        $this->actingAs($this->admin())->get(route('admin.device.scan'))
            ->assertOk()
            ->assertViewHas('deviceTypes', fn ($types) => $types->pluck('id')->contains($type->id));
    }
}