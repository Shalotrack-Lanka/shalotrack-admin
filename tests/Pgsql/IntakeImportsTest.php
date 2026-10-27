<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\DeviceType;
use App\Models\Feature;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * EXCEL IMPORTS on the Master Pages, on real PostgreSQL: devices, SIMs and device types. Also the device-type form.
 * (The stock Excel import is covered with the stock tests.)
 *
 * The promise for every import, in simple words:
 *   - A good row is saved exactly as if it had been typed into the normal form (same rules, same stock use).
 *   - A bad row is SKIPPED and REPORTED with its row number and a reason. It never stops the good rows.
 *   - Nothing is skipped silently: "0 imported" always comes with the reasons.
 *   - Only .xlsx / .csv files up to 10 MB are accepted, by their real content, not just their name.
 *   - Only an ADMIN may upload or download templates.
 *
 * The files are real .xlsx files built in the test. The app server is faked. Everything is rolled back.
 */
class IntakeImportsTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData;

    private const DEVICE_HEADINGS = ['imei_number', 'sim_number', 'device_category', 'model'];
    private const SIM_HEADINGS    = ['sim_type', 'sim_number', 'imsi', 'iccid', 'sim_status', 'activation_required'];
    private const TYPE_HEADINGS   = ['device_category', 'model', 'protocol', 'features'];

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakeIntakeApi();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function upload(string $routeName, UploadedFile $file)
    {
        return $this->actingAs($this->admin())->post(route($routeName), ['excel_file' => $file]);
    }

    /** @return array<int,array{row:int,attribute:string,errors:array}> what the admin sees under "rows failed" */
    private function failures(): array
    {
        return collect(session('import_failures', []))
            ->map(fn ($f) => ['row' => $f->row(), 'attribute' => $f->attribute(), 'errors' => $f->errors()])
            ->values()->all();
    }

    /** Excel row numbers that failed, each once (a row can fail for several reasons). */
    private function failedRows(): array
    {
        return array_values(array_unique(array_column($this->failures(), 'row')));
    }

    private function failureText(int $row): string
    {
        return implode(' | ', collect($this->failures())->where('row', $row)->flatMap(fn ($f) => $f['errors'])->all());
    }

    private function textFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'intake') . '.' . pathinfo($name, PATHINFO_EXTENSION);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    // ================================================================== DEVICES

    private function deviceRow(string $imei, ?string $sim, DeviceType $type, ?string $category = null): array
    {
        return [$imei, $sim, $category ?? $type->device_category, $type->model];
    }

    public function test_a_device_file_registers_every_good_row_like_the_normal_form(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 5);
        $simA = $this->makeSim();
        $simB = $this->makeSim();
        [$a, $b, $c] = [$this->validImei(), $this->validImei(), $this->validImei()];

        $file = $this->xlsxUpload(self::DEVICE_HEADINGS, [
            $this->deviceRow($a, $simA->sim_number, $type, strtolower($type->device_category)),   // capital letters do not matter
            $this->deviceRow($b, $simB->sim_number, $type),
            $this->deviceRow($c, null, $type),                                                    // no SIM is allowed
        ]);

        $this->upload('admin.device.import', $file)
            ->assertRedirect(route('admin.setup-device'))
            ->assertSessionHas('import_success_count', 3)
            ->assertSessionHas('import_sync_failed', 0);
        $this->assertSame([], $this->failures());

        $deviceA = SetupShalotrackDevice::where('imei_number', $a)->sole();
        $this->assertSame($type->device_category . ' ' . $type->model, $deviceA->device_category);
        $this->assertSame('Not Activated', $deviceA->status);
        $this->assertNull($deviceA->dealer_id);
        $this->assertSame($simA->sim_number, $deviceA->sim_number);
        $this->assertSame($simA->iccid, $deviceA->iccid, 'the SIM identity is copied onto the device');
        $this->assertSame($simA->imsi, $deviceA->imsi);
        $this->assertNull(SetupShalotrackDevice::where('imei_number', $c)->sole()->sim_number);

        $this->assertNull(Sim::find($simA->id), 'used SIMs leave the pool');
        $this->assertNull(Sim::find($simB->id));
        $this->assertSame(2, $stock->fresh()->company_available_stock, 'one unit of stock per device');
        $this->assertCount(3, $this->syncCalls, 'the app server is told about every device');
    }

    public function test_a_device_typed_as_a_plain_number_in_excel_still_imports_correctly(): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 1);
        $imei = $this->validImei();

        $this->upload('admin.device.import', $this->xlsxUpload(self::DEVICE_HEADINGS, [[(int) $imei, null, $type->device_category, $type->model]]))
            ->assertSessionHas('import_success_count', 1);

        $this->assertSame($imei, SetupShalotrackDevice::where('imei_number', $imei)->sole()->imei_number, 'no scientific notation, no lost digits');
    }

    public function test_bad_device_rows_are_skipped_and_each_one_is_reported_with_its_reason(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 20);
        $simOn = $this->makeSim();
        $simOff = $this->makeSim('Not Activated');
        $existing = $this->validImei();
        SetupShalotrackDevice::create(['device_type_id' => $type->id, 'device_category' => 'x', 'imei_number' => $existing, 'status' => 'Not Activated']);
        $repeated = $this->validImei();
        $good1 = $this->validImei();
        $good2 = $this->validImei();
        $noStockType = $this->makeDeviceType();

        $rows = [
            2  => $this->deviceRow($good1, null, $type),
            3  => $this->deviceRow('12345678901234', null, $type),                              // 14 digits
            4  => $this->deviceRow('86487617098140A', null, $type),                             // a letter
            5  => $this->deviceRow($existing, null, $type),                                     // already registered
            6  => $this->deviceRow($this->validImei(), '0709999999', $type),                    // SIM not in the system
            7  => $this->deviceRow($this->validImei(), $simOff->sim_number, $type),             // SIM not activated
            8  => $this->deviceRow($this->validImei(), '07012', $type),                         // SIM too short
            9  => $this->deviceRow($this->validImei(), null, $type, 'No Such Category'),        // unknown device type
            10 => $this->deviceRow($this->validImei(), null, $noStockType),                     // no stock record
            11 => [$this->validImei(), null, null, $type->model],                               // category missing
            12 => [$this->validImei(), null, $type->device_category, null],                     // model missing
            13 => [null, null, $type->device_category, $type->model],                           // IMEI missing
            14 => $this->deviceRow($repeated, $simOn->sim_number, $type),                       // good, uses the SIM
            15 => $this->deviceRow($repeated, null, $type),                                     // same IMEI again in the file
            16 => $this->deviceRow($this->validImei(), $simOn->sim_number, $type),              // SIM already used by row 14
            17 => $this->deviceRow($good2, null, $type),
        ];

        $this->upload('admin.device.import', $this->xlsxUpload(self::DEVICE_HEADINGS, array_values($rows)))
            ->assertSessionHas('import_success_count', 3);   // rows 2, 14 and 17

        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 15, 16], $this->failedRows(), 'every bad row is reported, by its Excel row number');
        $this->assertStringContainsString('does not exist', $this->failureText(9), 'an unknown device type must say so, not vanish');
        $this->assertStringContainsString('No company stock', $this->failureText(10));

        $this->assertSame(17, $stock->fresh()->company_available_stock, 'only the 3 saved devices used stock');
        $this->assertNotNull(SetupShalotrackDevice::where('imei_number', $good1)->first());
        $this->assertNotNull(SetupShalotrackDevice::where('imei_number', $good2)->first());
        $this->assertCount(1, SetupShalotrackDevice::where('imei_number', $repeated)->get());
        $this->assertNotNull(Sim::find($simOff->id), 'a refused SIM stays in the pool');
    }

    public function test_when_stock_runs_out_in_the_middle_of_a_file_the_rest_are_reported(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 2);

        $file = $this->xlsxUpload(self::DEVICE_HEADINGS, [
            $this->deviceRow($this->validImei(), null, $type),
            $this->deviceRow($this->validImei(), null, $type),
            $this->deviceRow($this->validImei(), null, $type),
            $this->deviceRow($this->validImei(), null, $type),
        ]);

        $this->upload('admin.device.import', $file)->assertSessionHas('import_success_count', 2);

        $this->assertSame([4, 5], $this->failedRows());
        $this->assertStringContainsString('No company stock', $this->failureText(4));
        $this->assertSame(0, $stock->fresh()->company_available_stock, 'stock never goes below zero');
        $this->assertSame(2, SetupShalotrackDevice::where('imei_number', 'like', '9990%')->count());
    }

    public function test_a_sim_number_typed_as_a_number_loses_its_zero_and_is_reported_not_guessed(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 1);
        $sim = $this->makeSim();

        $this->upload('admin.device.import', $this->xlsxUpload(self::DEVICE_HEADINGS, [[$this->validImei(), (int) $sim->sim_number, $type->device_category, $type->model]]))
            ->assertSessionHas('import_success_count', 0);

        $this->assertSame([2], $this->failedRows());
        $this->assertSame(1, $stock->fresh()->company_available_stock);
        $this->assertNotNull(Sim::find($sim->id));
    }

    public function test_when_the_app_server_is_down_imported_devices_are_saved_and_the_failed_syncs_are_counted(): void
    {
        $this->syncMode = 'down';
        $type = $this->makeDeviceType();
        $this->stockFor($type, 2);

        $this->upload('admin.device.import', $this->xlsxUpload(self::DEVICE_HEADINGS, [
            $this->deviceRow($this->validImei(), null, $type),
            $this->deviceRow($this->validImei(), null, $type),
        ]))
            ->assertSessionHas('import_success_count', 2)
            ->assertSessionHas('import_sync_failed', 2);

        $this->assertSame(2, SetupShalotrackDevice::where('imei_number', 'like', '9990%')->count());
    }

    public function test_a_device_file_with_only_the_heading_row_imports_nothing_and_says_so(): void
    {
        $this->upload('admin.device.import', $this->xlsxUpload(self::DEVICE_HEADINGS, []))
            ->assertSessionHas('import_success_count', 0);

        $this->assertSame([], $this->failures());
    }

    public function test_a_device_file_with_wrong_headings_fails_every_row_instead_of_importing_garbage(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 2);

        $this->upload('admin.device.import', $this->xlsxUpload(['IMEI', 'SIM', 'Type', 'Name'], [
            [$this->validImei(), null, $type->device_category, $type->model],
        ]))->assertSessionHas('import_success_count', 0);

        $this->assertSame([2], $this->failedRows());
        $this->assertSame(2, $stock->fresh()->company_available_stock);
    }

    public function test_a_csv_file_works_too(): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 1);
        $imei = $this->validImei();
        $csv = "imei_number,sim_number,device_category,model\n{$imei},,{$type->device_category},{$type->model}\n";

        $this->upload('admin.device.import', $this->textFile('devices.csv', $csv))->assertSessionHas('import_success_count', 1);

        $this->assertNotNull(SetupShalotrackDevice::where('imei_number', $imei)->first());
    }

    // ================================================================== SIMS

    private function simRow(array $identity, string $status = 'Activated', string $activation = 'no', string $type = 'Dialog'): array
    {
        return [$type, $identity['sim_number'], $identity['imsi'], $identity['iccid'], $status, $activation];
    }

    public function test_a_sim_file_adds_every_good_row_with_the_right_values(): void
    {
        $a = $this->simIdentity();
        $b = $this->simIdentity();
        $c = $this->simIdentity();
        $d = $this->simIdentity();

        $this->upload('admin.stock.sim.import', $this->xlsxUpload(self::SIM_HEADINGS, [
            $this->simRow($a, 'Activated', 'no'),
            $this->simRow($b, 'Not Activated', 'Yes'),
            $this->simRow($c, 'Activated', 'y'),
            $this->simRow($d, 'Activated', '1', '  Mobitel  '),
        ]))->assertSessionHas('import_success_count', 4);
        $this->assertSame([], $this->failures());

        $simA = Sim::where('iccid', $a['iccid'])->sole();
        $this->assertSame($a['sim_number'], $simA->sim_number, 'the leading 0 is kept');
        $this->assertSame('Activated', $simA->sim_status);
        $this->assertFalse($simA->activation_required);
        $this->assertTrue(Sim::where('iccid', $b['iccid'])->sole()->activation_required);
        $this->assertSame('Not Activated', Sim::where('iccid', $b['iccid'])->sole()->sim_status);
        $this->assertTrue(Sim::where('iccid', $c['iccid'])->sole()->activation_required);
        $this->assertTrue(Sim::where('iccid', $d['iccid'])->sole()->activation_required);
        $this->assertSame('Mobitel', Sim::where('iccid', $d['iccid'])->sole()->sim_type, 'spaces are removed');
    }

    public function test_bad_sim_rows_are_skipped_and_each_one_is_reported(): void
    {
        $taken = $this->makeSim();
        $good = $this->simIdentity();
        $repeat = $this->simIdentity();
        $ok = fn () => $this->simIdentity();
        $before = Sim::count();

        $rows = [
            2  => $this->simRow($good),
            3  => $this->simRow(array_merge($ok(), ['sim_number' => '070123456'])),                // 9 digits
            4  => $this->simRow(array_merge($ok(), ['sim_number' => '07012345678'])),              // 11 digits
            5  => $this->simRow(array_merge($ok(), ['sim_number' => $taken->sim_number])),         // already in the system
            6  => $this->simRow(array_merge($ok(), ['imsi' => '41299000000001'])),                 // 14 digits
            7  => $this->simRow(array_merge($ok(), ['imsi' => $taken->imsi])),
            8  => $this->simRow(array_merge($ok(), ['iccid' => '899490000000000001'])),            // 18 digits
            9  => $this->simRow(array_merge($ok(), ['iccid' => '899490000000000000001'])),         // 21 digits
            10 => $this->simRow(array_merge($ok(), ['iccid' => $taken->iccid])),
            11 => $this->simRow($ok(), 'Cancelled'),                                                // unknown status
            12 => $this->simRow($ok(), 'activated'),                                                // wrong case
            13 => $this->simRow($ok(), 'Activated', 'no', ''),                                      // type missing
            14 => $this->simRow($ok(), 'Activated', 'no', str_repeat('d', 256)),                    // type too long
            15 => $this->simRow($repeat),
            16 => $this->simRow($repeat),                                                           // same SIM again in the same file
            17 => $this->simRow(array_merge($ok(), ['sim_number' => '070ABCDEFG'])),                // letters
        ];

        $this->upload('admin.stock.sim.import', $this->xlsxUpload(self::SIM_HEADINGS, array_values($rows)))
            ->assertSessionHas('import_success_count', 2);   // rows 2 and 15

        $this->assertSame(array_merge(range(3, 14), [16, 17]), $this->failedRows());
        $this->assertSame($before + 2, Sim::count());
        $this->assertStringContainsString('SIM', $this->failureText(5), 'a clear reason is shown');
    }

    public function test_a_sim_number_typed_as_a_number_in_excel_is_reported_because_the_zero_is_gone(): void
    {
        $id = $this->simIdentity();
        $before = Sim::count();

        $this->upload('admin.stock.sim.import', $this->xlsxUpload(self::SIM_HEADINGS, [
            ['Dialog', (int) $id['sim_number'], $id['imsi'], $id['iccid'], 'Activated', 'no'],
        ]))->assertSessionHas('import_success_count', 0);

        $this->assertSame([2], $this->failedRows());
        $this->assertSame($before, Sim::count());
    }

    public function test_a_script_in_a_sim_type_is_stored_as_plain_text_and_not_run_on_the_page(): void
    {
        $id = $this->simIdentity();
        $this->upload('admin.stock.sim.import', $this->xlsxUpload(self::SIM_HEADINGS, [$this->simRow($id, 'Activated', 'no', '<script>alert(1)</script>')]))
            ->assertSessionHas('import_success_count', 1);

        $html = $this->actingAs($this->admin())->get(route('admin.add-sim'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ================================================================== DEVICE TYPES

    private function feature(): Feature
    {
        return Feature::create(['name' => 'AUTOTEST-FEATURE-' . $this->nextIntakeNumber()]);
    }

    public function test_a_device_type_file_adds_types_and_links_or_creates_the_features(): void
    {
        $known = $this->feature();
        $newName = 'AUTOTEST-NEWFEATURE-' . $this->nextIntakeNumber();
        $cat = 'AUTOTEST-IMP-' . $this->nextIntakeNumber();

        $this->upload('admin.device-types.import', $this->xlsxUpload(self::TYPE_HEADINGS, [
            [$cat, 'Basic', 'GT06', null],
            [$cat, 'Plus', ' gt06 ', strtolower($known->name) . ' , ' . $newName . ' ,, '],
        ]))->assertSessionHas('import_success_count', 2);
        $this->assertSame([], $this->failures());

        $basic = DeviceType::where('device_category', $cat)->where('model', 'Basic')->sole();
        $plus = DeviceType::where('device_category', $cat)->where('model', 'Plus')->sole();
        $this->assertSame([], $basic->features);
        $this->assertSame('gt06', $plus->protocol, 'spaces around the value are removed');

        $created = Feature::where('name', $newName)->sole();
        $this->assertSame([(string) $known->id, (string) $created->id], $plus->features, 'a known feature is matched ignoring capitals; an unknown one is created once');
        $this->assertSame(1, Feature::whereRaw('LOWER(name) = ?', [strtolower($known->name)])->count(), 'no duplicate of the known feature');
    }

    public function test_bad_device_type_rows_are_skipped_and_reported(): void
    {
        $existing = $this->makeDeviceType();
        $cat = 'AUTOTEST-IMP-' . $this->nextIntakeNumber();
        $before = DeviceType::count();

        $this->upload('admin.device-types.import', $this->xlsxUpload(self::TYPE_HEADINGS, [
            [$cat, 'Basic', 'GT06', null],                                                       // 2 good
            [$cat, 'Gold', 'GT06', null],                                                        // 3 model not allowed
            [$cat, 'basic', 'GT06', null],                                                       // 4 same type again, capitals differ
            [strtoupper($existing->device_category), $existing->model, 'GT06', null],            // 5 already in the system
            [null, 'Plus', 'GT06', null],                                                        // 6 category missing
            [$cat, null, 'GT06', null],                                                          // 7 model missing
            [$cat, 'Plus', null, null],                                                          // 8 protocol missing
            [str_repeat('d', 256), 'Plus', 'GT06', null],                                        // 9 category too long
            [$cat, 'Customize', 'GT06', null],                                                   // 10 good
        ]))->assertSessionHas('import_success_count', 2);

        $this->assertSame([3, 4, 5, 6, 7, 8, 9], $this->failedRows());
        $this->assertSame($before + 2, DeviceType::count());
    }

    // ------------------------------------------------------------------ the device type form

    private function typeForm(array $overrides = []): array
    {
        return array_merge(['device_category' => 'AUTOTEST-FORM-' . $this->nextIntakeNumber(), 'model' => 'Basic', 'protocol' => 'GT06', 'features' => []], $overrides);
    }

    public function test_the_device_type_form_saves_a_type_with_its_features(): void
    {
        $f1 = $this->feature();
        $f2 = $this->feature();
        $form = $this->typeForm(['features' => [$f1->id, $f2->id]]);

        $this->actingAs($this->admin())->post(route('admin.device-types.store'), $form)
            ->assertRedirect()->assertSessionHas('success', 'Device Type added successfully!');

        $type = DeviceType::where('device_category', $form['device_category'])->sole();
        $this->assertEquals([$f1->id, $f2->id], $type->features);
    }

    public static function badTypeForms(): array
    {
        return [
            'category missing'        => [['device_category' => '__missing__'], 'device_category'],
            'category too long'       => [['device_category' => '__LONG__'], 'device_category'],
            'model missing'           => [['model' => '__missing__'], 'model'],
            'model not allowed'       => [['model' => 'Gold'], 'model'],
            'protocol missing'        => [['protocol' => '__missing__'], 'protocol'],
            'feature does not exist'  => [['features' => ['987654321']], 'features.0'],
            'feature is text'         => [['features' => ['abc']], 'features.0'],
            'feature id too big'      => [['features' => ['99999999999999999999']], 'features.0'],
            'features is not a list'  => [['features' => 'x'], 'features'],
        ];
    }

    #[DataProvider('badTypeForms')]
    public function test_a_bad_device_type_form_is_refused(array $overrides, string $errorField): void
    {
        $before = DeviceType::count();
        $overrides = array_map(fn ($v) => $v === '__LONG__' ? str_repeat('d', 256) : $v, $overrides);
        $form = array_filter($this->typeForm($overrides), fn ($v) => $v !== '__missing__');

        $this->actingAs($this->admin())->post(route('admin.device-types.store'), $form)->assertSessionHasErrors($errorField);

        $this->assertSame($before, DeviceType::count());
    }

    public function test_the_same_category_and_model_cannot_be_added_twice_but_another_model_can(): void
    {
        $type = $this->makeDeviceType();
        $before = DeviceType::count();

        $this->actingAs($this->admin())->post(route('admin.device-types.store'), $this->typeForm(['device_category' => $type->device_category, 'model' => $type->model]))
            ->assertSessionHasErrors(['device_category' => 'This exact Device Category + Model combination already exists.']);
        $this->assertSame($before, DeviceType::count());

        $this->actingAs($this->admin())->post(route('admin.device-types.store'), $this->typeForm(['device_category' => $type->device_category, 'model' => 'Plus']))
            ->assertSessionHasNoErrors();
        $this->assertSame($before + 1, DeviceType::count());
    }

    public function test_a_feature_is_added_once_and_a_duplicate_is_refused(): void
    {
        $name = 'AUTOTEST-FEATURE-' . $this->nextIntakeNumber();

        $this->actingAs($this->admin())->post(route('admin.features.store'), ['name' => $name])->assertSessionHas('success');
        $this->actingAs($this->admin())->post(route('admin.features.store'), ['name' => $name])->assertSessionHasErrors(['name' => 'This feature already exists.']);
        $this->actingAs($this->admin())->post(route('admin.features.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($this->admin())->post(route('admin.features.store'), ['name' => str_repeat('f', 256)])->assertSessionHasErrors('name');

        $this->assertSame(1, Feature::where('name', $name)->count());
    }

    // ================================================================== FILE RULES (same for all three uploads)

    public static function uploads(): array
    {
        return ['devices' => ['admin.device.import'], 'sims' => ['admin.stock.sim.import'], 'device types' => ['admin.device-types.import']];
    }

    #[DataProvider('uploads')]
    public function test_the_wrong_kind_of_file_is_refused_by_its_real_content_not_its_name(string $routeName): void
    {
        $before = [Sim::count(), DeviceType::count(), SetupShalotrackDevice::count()];

        $this->upload($routeName, $this->textFile('picture.png', "\x89PNG\r\n\x1a\n"))->assertSessionHasErrors('excel_file');
        $this->upload($routeName, $this->textFile('virus.exe', 'MZ'))->assertSessionHasErrors('excel_file');
        $this->upload($routeName, $this->textFile('page.html', '<script>alert(1)</script>'))->assertSessionHasErrors('excel_file');
        $this->upload($routeName, $this->textFile('fake.xlsx', '<?php echo 1;'))->assertSessionHasErrors('excel_file');   // right name, wrong content

        $this->assertSame($before, [Sim::count(), DeviceType::count(), SetupShalotrackDevice::count()]);
    }

    #[DataProvider('uploads')]
    public function test_a_missing_or_oversized_file_is_refused(string $routeName): void
    {
        $this->actingAs($this->admin())->post(route($routeName), [])->assertSessionHasErrors('excel_file');
        $this->actingAs($this->admin())->post(route($routeName), ['excel_file' => 'not-a-file'])->assertSessionHasErrors('excel_file');
        $this->actingAs($this->admin())->post(route($routeName), ['excel_file' => [$this->xlsxUpload(self::SIM_HEADINGS, [])]])->assertSessionHasErrors('excel_file');

        $big = UploadedFile::fake()->create('big.csv', 10241, 'text/csv');   // 1 KB over the 10 MB limit
        $this->upload($routeName, $big)->assertSessionHasErrors('excel_file');
    }

    public static function templates(): array
    {
        return [
            'devices'      => ['admin.device.import-template', 'device_import_template.xlsx'],
            'sims'         => ['admin.stock.sim.import-template', 'sim_import_template.xlsx'],
            'device types' => ['admin.device-types.import-template', 'device_type_import_template.xlsx'],
        ];
    }

    #[DataProvider('templates')]
    public function test_the_blank_templates_download_as_excel_files(string $routeName, string $fileName): void
    {
        $response = $this->actingAs($this->admin())->get(route($routeName))->assertOk();

        $this->assertStringContainsString($fileName, $response->headers->get('content-disposition'));
    }

    // ================================================================== who may use it

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_nobody_but_an_admin_can_upload_or_download_import_files(string $role): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 3);
        $before = [Sim::count(), DeviceType::count(), SetupShalotrackDevice::count()];
        $this->actingAs($this->makeAdmin($role));

        $device = $this->xlsxUpload(self::DEVICE_HEADINGS, [$this->deviceRow($this->validImei(), null, $type)]);
        $sim = $this->xlsxUpload(self::SIM_HEADINGS, [$this->simRow($this->simIdentity())]);
        $kind = $this->xlsxUpload(self::TYPE_HEADINGS, [['AUTOTEST-X', 'Basic', 'GT06', null]]);

        $this->post(route('admin.device.import'), ['excel_file' => $device])->assertForbidden();
        $this->post(route('admin.stock.sim.import'), ['excel_file' => $sim])->assertForbidden();
        $this->post(route('admin.device-types.import'), ['excel_file' => $kind])->assertForbidden();
        $this->post(route('admin.device-types.store'), $this->typeForm())->assertForbidden();
        $this->post(route('admin.features.store'), ['name' => 'AUTOTEST-F'])->assertForbidden();
        $this->get(route('admin.device.import-template'))->assertForbidden();
        $this->get(route('admin.stock.sim.import-template'))->assertForbidden();
        $this->get(route('admin.device-types.import-template'))->assertForbidden();

        $this->assertSame($before, [Sim::count(), DeviceType::count(), SetupShalotrackDevice::count()]);
        $this->assertSame(3, $stock->fresh()->company_available_stock);
        $this->assertSame([], $this->syncCalls);
    }

    public function test_a_logged_out_visitor_is_sent_to_login(): void
    {
        $this->post(route('admin.device.import'), [])->assertRedirect('/login');
        $this->post(route('admin.stock.sim.import'), [])->assertRedirect('/login');
        $this->post(route('admin.device-types.import'), [])->assertRedirect('/login');
        $this->get(route('admin.device.import-template'))->assertRedirect('/login');
    }
}