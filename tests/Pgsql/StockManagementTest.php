<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Stock;
use App\Models\StockTransferLedger;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * COMPANY STOCK (stock in, the ledger, reports and the Excel import), on real PostgreSQL.
 *
 * Rules in simple words:
 *   - Stock-in adds a quantity of one device type to the company stock and writes one ledger line saying who
 *     supplied it. Only an Active supplier can be chosen.
 *   - Receiving a device from a supplier removes that product from the supplier's offer list.
 *   - There is exactly one stock row per device type.
 *   - Deleting a ledger line only hides it (it can be recovered) and is written to the audit log. The stock number
 *     is not changed by it.
 *   - The Excel import is all or nothing: one bad row and NOTHING is added, so the file can be fixed and uploaded
 *     again without adding stock twice. Every bad row is reported with its row number and a reason.
 *   - Quantities, ids and dates are checked so that a bad value is a message on the screen, never a database error.
 *   - Only an ADMIN may do any of this.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class StockManagementTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsSupplierData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function stockIn(int $typeId, int $supplierId, $qty)
    {
        return $this->actingAs($this->admin())->post(route('admin.stock.store'), [
            'device_type_id' => $typeId,
            'supplier_id'    => $supplierId,
            'stock_in'       => $qty,
        ]);
    }

    private function ledgerLine(?int $stockId = null, array $overrides = []): StockTransferLedger
    {
        $stockId ??= $this->stockFor($this->makeDeviceType(), 5)->id;

        return StockTransferLedger::create(array_merge([
            'stock_id'             => $stockId,
            'device_category_type' => 'Autotest Device',
            'supplier'             => 'Autotest Supplier',
            'stock_in'             => 5,
            'stocked_in_date'      => now()->toDateString(),
        ], $overrides));
    }

    private function importFile(array $rows, string $name = 'stock.xlsx'): UploadedFile
    {
        return $this->xlsxUpload(['device_category', 'model', 'supplier_name', 'stock_in', 'stocked_in_date'], $rows, $name);
    }

    private function runImport(UploadedFile $file)
    {
        return $this->actingAs($this->admin())->from(route('admin.stock.manage'))
            ->post(route('admin.stock.import'), ['excel_file' => $file]);
    }

    private function companyStock($type): int
    {
        return (int) Stock::where('device_type_id', $type->id)->value('company_available_stock');
    }

    // ================================================================== the page

    public function test_the_page_lists_stock_ledger_device_types_and_only_active_suppliers(): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 12);
        $line = $this->ledgerLine();
        $active = $this->makeSupplier();
        $inactive = $this->makeSupplier(['status' => 'Inactive']);

        $this->actingAs($this->admin())->get(route('admin.stock.manage'))
            ->assertOk()
            ->assertViewHas('stockMap', fn ($m) => (int) ($m[$type->id] ?? -1) === 12)
            ->assertViewHas('ledgerEntries', fn ($l) => $l->pluck('id')->contains($line->id))
            ->assertViewHas('deviceTypes', fn ($d) => $d->pluck('id')->contains($type->id))
            ->assertViewHas('suppliers', fn ($s) => $s->pluck('id')->contains($active->id) && ! $s->pluck('id')->contains($inactive->id));
    }

    public function test_the_ledger_shows_the_newest_date_first(): void
    {
        $stock = $this->stockFor($this->makeDeviceType(), 1);
        $old = $this->ledgerLine($stock->id, ['stocked_in_date' => now()->subDays(10)->toDateString()]);
        $new = $this->ledgerLine($stock->id, ['stocked_in_date' => now()->toDateString()]);

        $this->actingAs($this->admin())->get(route('admin.stock.manage'))
            ->assertViewHas('ledgerEntries', function ($l) use ($old, $new) {
                $ids = $l->pluck('id')->all();

                return array_search($new->id, $ids) < array_search($old->id, $ids);
            });
    }

    public function test_a_deleted_ledger_line_is_not_on_the_page(): void
    {
        $line = $this->ledgerLine();
        $line->delete();

        $this->actingAs($this->admin())->get(route('admin.stock.manage'))
            ->assertViewHas('ledgerEntries', fn ($l) => ! $l->pluck('id')->contains($line->id));
    }

    public function test_a_script_in_a_supplier_name_on_the_ledger_is_shown_as_text(): void
    {
        $this->ledgerLine(null, ['supplier' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->admin())->get(route('admin.stock.manage'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ================================================================== stock in

    public function test_stock_in_adds_to_the_stock_and_writes_one_ledger_line(): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 10);
        $supplier = $this->makeSupplier(['name' => 'Autotest Ledger Supplier']);

        $this->stockIn($type->id, $supplier->id, 25)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(35, $this->companyStock($type));
        $line = StockTransferLedger::where('supplier_id', $supplier->id)->sole();
        $this->assertSame(25, $line->stock_in);
        $this->assertSame('Autotest Ledger Supplier', $line->supplier);
        $this->assertSame(now()->toDateString(), $line->stocked_in_date->toDateString());
        $this->assertSame(Stock::where('device_type_id', $type->id)->value('id'), $line->stock_id);
    }

    public function test_the_first_stock_in_for_a_device_type_creates_its_single_stock_row(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier();
        $this->assertSame(0, Stock::where('device_type_id', $type->id)->count());

        $this->stockIn($type->id, $supplier->id, 3);
        $this->stockIn($type->id, $supplier->id, 4);

        $this->assertSame(1, Stock::where('device_type_id', $type->id)->count(), 'one row per device type');
        $this->assertSame(7, $this->companyStock($type));
    }

    public function test_stock_in_removes_the_product_from_that_suppliers_offer_list_only(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier();
        $other = $this->makeSupplier();
        $received = $this->makeDeviceProduct($type);
        $stays = $this->makePlainProduct();
        $this->offer($supplier, $received);
        $this->offer($supplier, $stays);
        $this->offer($other, $received);

        $this->stockIn($type->id, $supplier->id, 2);

        $this->assertEqualsCanonicalizing([$stays->id], $supplier->products()->pluck('products.id')->all());
        $this->assertSame([$received->id], $other->products()->pluck('products.id')->all(), 'another supplier keeps its offer');
    }

    public function test_the_biggest_allowed_quantity_is_accepted(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier();

        $this->stockIn($type->id, $supplier->id, 1000000)->assertSessionHasNoErrors();

        $this->assertSame(1000000, $this->companyStock($type));
    }

    public static function badStockIns(): array
    {
        return [
            'no device type'            => [['device_type_id' => null], 'device_type_id'],
            'text device type'          => [['device_type_id' => 'abc'], 'device_type_id'],
            'huge device type'          => [['device_type_id' => '99999999999999999999'], 'device_type_id'],
            'device type not there'     => [['device_type_id' => 987654321], 'device_type_id'],
            'zero device type'          => [['device_type_id' => 0], 'device_type_id'],
            'no supplier'               => [['supplier_id' => null], 'supplier_id'],
            'text supplier'             => [['supplier_id' => 'abc'], 'supplier_id'],
            'huge supplier'             => [['supplier_id' => '99999999999999999999'], 'supplier_id'],
            'supplier not there'        => [['supplier_id' => 987654321], 'supplier_id'],
            'no quantity'               => [['stock_in' => null], 'stock_in'],
            'zero quantity'             => [['stock_in' => 0], 'stock_in'],
            'negative quantity'         => [['stock_in' => -5], 'stock_in'],
            'fraction quantity'         => [['stock_in' => 2.5], 'stock_in'],
            'text quantity'             => [['stock_in' => 'lots'], 'stock_in'],
            'over a million'            => [['stock_in' => 1000001], 'stock_in'],
            'too big for the database'  => [['stock_in' => 99999999999], 'stock_in'],
            'a list as the quantity'    => [['stock_in' => [1, 2]], 'stock_in'],
        ];
    }

    #[DataProvider('badStockIns')]
    public function test_a_bad_stock_in_is_refused_and_nothing_changes(array $override, string $field): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 10);
        $supplier = $this->makeSupplier();
        $ledger = StockTransferLedger::count();

        $this->actingAs($this->admin())->from(route('admin.stock.manage'))
            ->post(route('admin.stock.store'), array_merge(['device_type_id' => $type->id, 'supplier_id' => $supplier->id, 'stock_in' => 5], $override))
            ->assertSessionHasErrors($field);

        $this->assertSame(10, $this->companyStock($type));
        $this->assertSame($ledger, StockTransferLedger::count());
    }

    public function test_an_inactive_supplier_cannot_be_chosen(): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 10);
        $supplier = $this->makeSupplier(['status' => 'Inactive']);

        $this->stockIn($type->id, $supplier->id, 5)->assertSessionHasErrors('supplier_id');

        $this->assertSame(10, $this->companyStock($type));
    }

    // ================================================================== supplier products for the stock-in form

    public function test_the_supplier_products_answer_uses_the_device_type_id_when_there_is_one(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $device = $this->makeDeviceProduct($type);
        $plain = $this->makePlainProduct('Autotest Plain Line');
        $this->offer($supplier, $device, 10, 4);
        $this->offer($supplier, $plain, 10, 6);

        $json = $this->actingAs($this->admin())->getJson(route('admin.get-supplier-products', $supplier->id))
            ->assertOk()->assertJsonPath('success', true)->json('products');

        $byName = collect($json)->keyBy('name');
        $this->assertSame($type->id, $byName[$device->product_name]['id']);
        $this->assertSame($plain->id, $byName['Autotest Plain Line']['id']);
        $this->assertSame(4, $byName[$device->product_name]['qty']);
    }

    public function test_a_supplier_that_is_not_there_gets_a_polite_no(): void
    {
        $this->actingAs($this->admin())->getJson(route('admin.get-supplier-products', 987654321))
            ->assertOk()->assertJsonPath('success', false);
    }

    #[DataProvider('badIds')]
    public function test_a_text_or_huge_supplier_id_is_a_clean_404(string $id): void
    {
        $this->actingAs($this->admin())->getJson("/admin/stock/get-supplier-products/{$id}")->assertNotFound();
    }

    public static function badIds(): array
    {
        return ['text' => ['abc'], 'too big for the database' => ['99999999999999999999'], 'negative' => ['-4']];
    }

    // ================================================================== ledger notes and delete

    public function test_a_ledger_note_can_be_saved_and_cleared(): void
    {
        $line = $this->ledgerLine();

        $this->actingAs($this->admin())->patch(route('admin.stock.ledger.update', $line->id), ['description' => 'Box 3 was damaged'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame('Box 3 was damaged', $line->fresh()->description);

        $this->actingAs($this->admin())->patch(route('admin.stock.ledger.update', $line->id), ['description' => null]);
        $this->assertNull($line->fresh()->description);
    }

    public function test_a_ledger_note_over_1000_letters_is_refused(): void
    {
        $line = $this->ledgerLine();

        $this->actingAs($this->admin())->patch(route('admin.stock.ledger.update', $line->id), ['description' => str_repeat('a', 1001)])
            ->assertSessionHasErrors('description');
        $this->actingAs($this->admin())->patch(route('admin.stock.ledger.update', $line->id), ['description' => ['a']])
            ->assertSessionHasErrors('description');

        $this->assertNull($line->fresh()->description);
    }

    public function test_deleting_a_ledger_line_hides_it_keeps_the_stock_and_is_audited(): void
    {
        $type = $this->makeDeviceType();
        $stock = $this->stockFor($type, 20);
        $line = $this->ledgerLine($stock->id, ['supplier' => 'Autotest Audit Supplier', 'stock_in' => 8]);

        $this->actingAs($this->admin())->delete(route('admin.stock.ledger.destroy', $line->id))
            ->assertSessionHas('success');

        $this->assertNull(StockTransferLedger::find($line->id));
        $this->assertNotNull(StockTransferLedger::withTrashed()->find($line->id), 'it can be recovered');
        $this->assertSame(20, $this->companyStock($type), 'the stock number is not changed');
        $this->assertSame(1, AuditLog::where('action', 'ledger.stock_deleted')->where('subject_id', (string) $line->id)->count());
    }

    #[DataProvider('badIds')]
    public function test_a_bad_ledger_id_is_a_clean_404(string $id): void
    {
        $this->actingAs($this->admin())->patch("/admin/stock/ledger/{$id}", ['description' => 'x'])->assertNotFound();
        $this->actingAs($this->admin())->delete("/admin/stock/ledger/{$id}")->assertNotFound();
    }

    public function test_a_deleted_ledger_line_cannot_be_changed_or_deleted_again(): void
    {
        $line = $this->ledgerLine();
        $line->delete();

        $this->actingAs($this->admin())->patch(route('admin.stock.ledger.update', $line->id), ['description' => 'x'])->assertNotFound();
        $this->actingAs($this->admin())->delete(route('admin.stock.ledger.destroy', $line->id))->assertNotFound();
    }

    // ================================================================== reports

    #[DataProvider('reportTypes')]
    public function test_each_report_opens_as_a_pdf(string|array $type): void
    {
        $this->stockFor($this->makeDeviceType(), 3);
        $this->ledgerLine();

        $response = $this->actingAs($this->admin())->get(route('admin.stock.report', ['type' => $type]))->assertOk();

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public static function reportTypes(): array
    {
        return ['stock' => ['stock'], 'ledger' => ['ledger'], 'unknown type falls back to stock' => ['bogus'], 'a script' => ['<script>'], 'a list' => [['a']]];
    }

    public function test_the_report_with_no_type_is_the_stock_report(): void
    {
        $this->actingAs($this->admin())->get(route('admin.stock.report'))->assertOk();
    }

    // ================================================================== Excel import: happy paths

    public function test_a_good_file_adds_stock_and_one_ledger_line_per_row(): void
    {
        $a = $this->makeDeviceType();
        $b = $this->makeDeviceType();
        $this->stockFor($a, 10);
        $supplier = $this->makeSupplier();

        $this->runImport($this->importFile([
            [$a->device_category, $a->model, $supplier->name, 5, '2026-03-01'],
            [$b->device_category, $b->model, $supplier->name, 7, '2026-03-02'],
        ]))->assertSessionHasNoErrors()->assertSessionHas('import_success_count', 2)->assertSessionHas('import_total_stock_in', 12);

        $this->assertSame(15, $this->companyStock($a));
        $this->assertSame(7, $this->companyStock($b));
        $this->assertSame(2, StockTransferLedger::where('supplier_id', $supplier->id)->count());
        $this->assertSame('2026-03-01', StockTransferLedger::where('supplier_id', $supplier->id)->where('stock_in', 5)->first()->stocked_in_date->toDateString());
    }

    public function test_names_match_without_caring_about_capitals_or_spaces(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier(['name' => 'Autotest Mixed Case']);

        $this->runImport($this->importFile([
            [strtoupper($type->device_category), '  ' . strtolower($type->model) . '  ', 'autotest MIXED case', 3, null],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(3, $this->companyStock($type));
    }

    public function test_a_missing_date_means_today(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier();

        $this->runImport($this->importFile([[$type->device_category, $type->model, $supplier->name, 2, null]]))->assertSessionHasNoErrors();

        $this->assertSame(now()->toDateString(), StockTransferLedger::where('supplier_id', $supplier->id)->first()->stocked_in_date->toDateString());
    }

    public function test_a_date_typed_as_an_excel_date_cell_is_understood(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier();
        $serial = Date::PHPToExcel(strtotime('2026-04-15'));

        $this->runImport($this->importFile([[$type->device_category, $type->model, $supplier->name, 2, $serial]]))->assertSessionHasNoErrors();

        $this->assertSame('2026-04-15', StockTransferLedger::where('supplier_id', $supplier->id)->first()->stocked_in_date->toDateString());
    }

    public function test_a_real_csv_file_is_accepted(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier();
        $csv = "device_category,model,supplier_name,stock_in,stocked_in_date\n\"{$type->device_category}\",\"{$type->model}\",\"{$supplier->name}\",4,2026-05-01\n";
        $path = tempnam(sys_get_temp_dir(), 'stk') . '.csv';
        file_put_contents($path, $csv);

        $this->runImport(new UploadedFile($path, 'stock.csv', 'text/plain', null, true))->assertSessionHasNoErrors();

        $this->assertSame(4, $this->companyStock($type));
    }

    // ================================================================== Excel import: bad rows

    public function test_one_bad_row_adds_nothing_at_all_and_names_the_row(): void
    {
        $good = $this->makeDeviceType();
        $this->stockFor($good, 10);
        $supplier = $this->makeSupplier();
        $ledger = StockTransferLedger::count();

        $response = $this->runImport($this->importFile([
            [$good->device_category, $good->model, $supplier->name, 5, null],
            ['Nonexistent', 'Autotest', $supplier->name, 5, null],
        ]));

        $response->assertSessionHasErrors();
        $text = implode(' | ', session('errors')->all());
        $this->assertStringContainsString('Nothing was imported', $text);
        $this->assertStringContainsString('Row 3', $text, 'the heading is row 1, so the bad row is row 3');
        $this->assertSame(10, $this->companyStock($good), 'the good row was rolled back too');
        $this->assertSame($ledger, StockTransferLedger::count());
    }

    public function test_fixing_the_file_and_uploading_again_never_adds_stock_twice(): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 0);
        $supplier = $this->makeSupplier();

        $this->runImport($this->importFile([
            [$type->device_category, $type->model, $supplier->name, 5, null],
            [$type->device_category, $type->model, 'Autotest Nobody', 5, null],
        ]))->assertSessionHasErrors();
        $this->assertSame(0, $this->companyStock($type));

        $this->runImport($this->importFile([
            [$type->device_category, $type->model, $supplier->name, 5, null],
            [$type->device_category, $type->model, $supplier->name, 5, null],
        ]))->assertSessionHasNoErrors();
        $this->assertSame(10, $this->companyStock($type));
    }

    public function test_an_unknown_device_type_and_an_unknown_supplier_are_both_reported_with_reasons(): void
    {
        $this->runImport($this->importFile([['Nope', 'Nope', 'Autotest Nobody', 3, null]]))->assertSessionHasErrors();

        $text = implode(' | ', session('errors')->all());
        $this->assertStringContainsString('does not exist in the system', $text);
        $this->assertStringContainsString('no Active supplier', $text);
    }

    public function test_an_inactive_supplier_is_refused_in_the_import(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier(['status' => 'Inactive']);

        $this->runImport($this->importFile([[$type->device_category, $type->model, $supplier->name, 3, null]]))->assertSessionHasErrors();

        $this->assertSame(0, $this->companyStock($type));
    }

    public static function badRows(): array
    {
        return [
            'quantity missing'        => [[null], 'stock_in'],
            'quantity zero'           => [[0], 'stock_in'],
            'quantity negative'       => [[-4], 'stock_in'],
            'quantity a fraction'     => [[2.5], 'stock_in'],
            'quantity text'           => [['many'], 'stock_in'],
            'quantity over a million' => [[1000001], 'stock_in'],
            'quantity too big for int' => [[99999999999], 'stock_in'],
        ];
    }

    #[DataProvider('badRows')]
    public function test_a_bad_quantity_in_the_file_is_reported_and_nothing_is_added(array $qty): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier();

        $this->runImport($this->importFile([[$type->device_category, $type->model, $supplier->name, $qty[0], null]]))
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->companyStock($type));
    }

    public function test_a_row_with_a_text_date_is_reported(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier();

        $this->runImport($this->importFile([[$type->device_category, $type->model, $supplier->name, 2, 'next tuesday-ish']]))
            ->assertSessionHasErrors();

        $this->assertSame(0, $this->companyStock($type));
    }

    public function test_a_row_with_a_missing_name_is_reported(): void
    {
        $type = $this->makeDeviceType();
        $supplier = $this->makeSupplier();

        $this->runImport($this->importFile([
            [null, $type->model, $supplier->name, 2, null],
            [$type->device_category, null, $supplier->name, 2, null],
            [$type->device_category, $type->model, null, 2, null],
        ]))->assertSessionHasErrors();

        $this->assertSame(0, $this->companyStock($type));
    }

    public function test_a_file_with_only_headings_adds_nothing_and_does_not_crash(): void
    {
        $ledger = StockTransferLedger::count();

        $this->runImport($this->importFile([]))->assertSessionHasNoErrors()->assertSessionHas('import_success_count', 0);

        $this->assertSame($ledger, StockTransferLedger::count());
    }

    public function test_a_formula_in_a_name_cell_is_treated_as_plain_text(): void
    {
        $supplier = $this->makeSupplier();

        $this->runImport($this->importFile([['=HYPERLINK("http://evil.test")', 'x', $supplier->name, 1, null]]))->assertSessionHasErrors();
    }

    // ================================================================== Excel import: the file itself

    public static function badFiles(): array
    {
        return [
            'a php file named .xlsx'   => ['shell.php', 'text/x-php', '<?php echo 1;'],
            'a pdf'                    => ['stock.pdf', 'application/pdf', '%PDF-1.4'],
            'an image'                 => ['stock.png', 'image/png', "\x89PNG"],
            'text with a double name'  => ['stock.xlsx.php', 'text/plain', 'a,b'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_a_wrong_kind_of_file_is_refused(string $name, string $mime, string $body): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, $body);

        $this->actingAs($this->admin())->from(route('admin.stock.manage'))
            ->post(route('admin.stock.import'), ['excel_file' => new UploadedFile($path, $name, $mime, null, true)])
            ->assertSessionHasErrors('excel_file');
    }

    public function test_a_file_over_10_megabytes_is_refused(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'big');
        file_put_contents($path, str_repeat('a', 10241 * 1024));

        $this->actingAs($this->admin())->from(route('admin.stock.manage'))
            ->post(route('admin.stock.import'), ['excel_file' => new UploadedFile($path, 'big.csv', 'text/plain', null, true)])
            ->assertSessionHasErrors('excel_file');
    }

    public function test_no_file_is_refused(): void
    {
        $this->actingAs($this->admin())->from(route('admin.stock.manage'))
            ->post(route('admin.stock.import'), [])->assertSessionHasErrors('excel_file');
    }

    public function test_a_broken_file_gives_a_plain_message_and_no_technical_text(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'brk') . '.xlsx';
        file_put_contents($path, 'this is not really a spreadsheet');

        $this->actingAs($this->admin())->from(route('admin.stock.manage'))
            ->post(route('admin.stock.import'), ['excel_file' => new UploadedFile($path, 'broken.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true)])
            ->assertSessionHasErrors('excel_file');

        $text = implode(' ', session('errors')->all());
        $this->assertStringNotContainsString('PhpOffice', $text);
        $this->assertStringNotContainsString('/var/', $text);
        $this->assertStringNotContainsString('SQLSTATE', $text);
    }

    public function test_the_template_downloads_with_the_five_headings(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.stock.import-template'))->assertOk();

        $this->assertStringContainsString('stock_import_template.xlsx', $response->headers->get('content-disposition'));
    }

    // ================================================================== who may enter

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_only_an_admin_can_manage_company_stock(string $role): void
    {
        $type = $this->makeDeviceType();
        $this->stockFor($type, 10);
        $supplier = $this->makeSupplier();
        $line = $this->ledgerLine();
        $user = $this->makeAdmin($role);

        $this->actingAs($user)->get(route('admin.stock.manage'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.stock.store'), ['device_type_id' => $type->id, 'supplier_id' => $supplier->id, 'stock_in' => 5])->assertForbidden();
        $this->actingAs($user)->patch(route('admin.stock.ledger.update', $line->id), ['description' => 'x'])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.stock.ledger.destroy', $line->id))->assertForbidden();
        $this->actingAs($user)->get(route('admin.stock.report'))->assertForbidden();
        $this->actingAs($user)->getJson(route('admin.get-supplier-products', $supplier->id))->assertForbidden();
        $this->actingAs($user)->post(route('admin.stock.import'), [])->assertForbidden();
        $this->actingAs($user)->get(route('admin.stock.import-template'))->assertForbidden();

        $this->assertSame(10, $this->companyStock($type));
        $this->assertNull($line->fresh()->deleted_at);
        $this->assertNull($line->fresh()->description);
    }

    public function test_a_logged_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('admin.stock.manage'))->assertRedirect();
        $this->post(route('admin.stock.store'), [])->assertRedirect();
        $this->post(route('admin.stock.import'), [])->assertRedirect();
    }
}