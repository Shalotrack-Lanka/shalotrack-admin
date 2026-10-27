<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Stock;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * PURCHASE ORDERS (supplier invoices), on real PostgreSQL.
 *
 * Rules in simple words:
 *   - The admin picks an Active supplier and lists what is being bought: product, quantity, unit price, discount %
 *     and an optional face value. The server works out every total itself; it never trusts totals from the browser.
 *     Line total = quantity x price, minus the discount %, plus the face value. The invoice total is the sum.
 *   - Numbers are PO-<year>-0001, 0002 ... one running count per year.
 *   - A product linked to a device type raises the company stock of that device type when ordered.
 *     A product with no device type (a SIM package, a service) is only recorded on the invoice.
 *   - Everything is all or nothing: if any line is bad, no invoice and no stock change is saved.
 *   - Bad numbers (negative, too big for the database, text) are refused with a message, never a database error.
 *   - Only an ADMIN may do any of this.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class PurchaseOrderTest extends PgsqlTestCase
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

    private function line(int $productId, array $overrides = []): array
    {
        return array_merge([
            'product_id' => $productId,
            'type'       => 'Device',
            'order_qty'  => 2,
            'unit_price' => 100,
            'discount'   => 0,
            'face_value' => 0,
        ], $overrides);
    }

    private function order(int $supplierId, array $items): array
    {
        return ['supplier_id' => $supplierId, 'items' => $items];
    }

    private function place(array $payload)
    {
        return $this->actingAs($this->admin())->post(route('admin.supplier-invoice.store'), $payload);
    }

    // ================================================================== the page and the history

    public function test_the_page_lists_active_suppliers_only_and_every_invoice_newest_first(): void
    {
        $active = $this->makeSupplier();
        $inactive = $this->makeSupplier(['status' => 'Inactive']);
        $old = SupplierInvoice::create(['invoice_number' => 'PO-AUTOTEST-A' . $this->nextIntakeNumber(), 'supplier_id' => $active->id, 'invoice_date' => now()->subDays(5)->toDateString(), 'grand_total' => 10]);
        $new = SupplierInvoice::create(['invoice_number' => 'PO-AUTOTEST-B' . $this->nextIntakeNumber(), 'supplier_id' => $active->id, 'invoice_date' => now()->toDateString(), 'grand_total' => 20]);

        $this->actingAs($this->admin())->get(route('admin.supplier-invoice'))
            ->assertOk()
            ->assertViewHas('suppliers', fn ($s) => $s->pluck('id')->contains($active->id) && ! $s->pluck('id')->contains($inactive->id))
            ->assertViewHas('invoices', function ($list) use ($old, $new) {
                $ids = $list->pluck('id')->all();

                return array_search($new->id, $ids) < array_search($old->id, $ids);
            });
    }

    public function test_the_history_can_be_limited_to_one_supplier(): void
    {
        $a = $this->makeSupplier();
        $b = $this->makeSupplier();
        $ofA = SupplierInvoice::create(['invoice_number' => 'PO-AUTOTEST-C' . $this->nextIntakeNumber(), 'supplier_id' => $a->id, 'invoice_date' => now()->toDateString(), 'grand_total' => 1]);
        $ofB = SupplierInvoice::create(['invoice_number' => 'PO-AUTOTEST-D' . $this->nextIntakeNumber(), 'supplier_id' => $b->id, 'invoice_date' => now()->toDateString(), 'grand_total' => 1]);

        $this->actingAs($this->admin())->get(route('admin.supplier-invoice', ['supplier_id' => $a->id]))
            ->assertViewHas('invoices', fn ($l) => $l->pluck('id')->contains($ofA->id) && ! $l->pluck('id')->contains($ofB->id));
    }

    #[DataProvider('oddFilters')]
    public function test_an_odd_supplier_filter_means_all_invoices_and_never_an_error(string $value): void
    {
        $supplier = $this->makeSupplier();
        $invoice = SupplierInvoice::create(['invoice_number' => 'PO-AUTOTEST-E' . $this->nextIntakeNumber(), 'supplier_id' => $supplier->id, 'invoice_date' => now()->toDateString(), 'grand_total' => 1]);

        $this->actingAs($this->admin())->get(route('admin.supplier-invoice', ['supplier_id' => $value]))
            ->assertOk()
            ->assertViewHas('invoices', fn ($l) => $l->pluck('id')->contains($invoice->id));
    }

    public static function oddFilters(): array
    {
        return ['text' => ['abc'], 'too big' => ['99999999999999999999'], 'negative' => ['-5'], 'a script' => ['<script>']];
    }

    // ================================================================== supplier data for the wizard

    public function test_the_supplier_data_has_contact_details_and_the_products_with_price_and_discount(): void
    {
        $supplier = $this->makeSupplier(['name' => 'Autotest Wizard Co', 'state' => 'Western']);
        $type = $this->makeDeviceType();
        $device = $this->makeDeviceProduct($type);
        $plain = $this->makePlainProduct('AAA Autotest Plain');
        $this->offer($supplier, $device, 150.5, 3);
        $this->offer($supplier, $plain, 20, 1);

        $json = $this->actingAs($this->admin())->getJson(route('admin.suppliers.purchase-data', $supplier->id))
            ->assertOk()
            ->assertJsonPath('supplier.name', 'Autotest Wizard Co')
            ->assertJsonCount(2, 'products')
            ->json();

        $byId = collect($json['products'])->keyBy('id');
        $this->assertEquals(150.5, $byId[$device->id]['price']);
        $this->assertSame($type->id, $byId[$device->id]['device_type_id']);
        $this->assertNull($byId[$plain->id]['device_type_id']);
        $this->assertSame('AAA Autotest Plain', $json['products'][0]['product_name'], 'products come in name order');
    }

    public function test_the_older_address_for_supplier_data_gives_the_same_answer(): void
    {
        $supplier = $this->makeSupplier();

        $this->actingAs($this->admin())->getJson('/admin/supplier/get-supplier-data/' . $supplier->id)
            ->assertOk()->assertJsonPath('supplier.name', $supplier->name);
    }

    #[DataProvider('badIds')]
    public function test_a_bad_id_for_supplier_data_is_a_clean_404(string $id): void
    {
        $this->actingAs($this->admin())->getJson("/admin/supplier/{$id}/purchase-data")->assertNotFound();
        $this->actingAs($this->admin())->getJson("/admin/supplier/get-supplier-data/{$id}")->assertNotFound();
    }

    public static function badIds(): array
    {
        return ['text' => ['abc'], 'too big for the database' => ['99999999999999999999'], 'a supplier that does not exist' => ['987654321']];
    }

    // ================================================================== placing an order: the money

    public function test_totals_are_worked_out_by_the_server(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();

        // 3 x 100.50 = 301.50, minus 10% = 271.35, plus face value 5 = 276.35
        $this->place($this->order($supplier->id, [$this->line($product->id, ['order_qty' => 3, 'unit_price' => 100.50, 'discount' => 10, 'face_value' => 5])]))
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertEquals(276.35, (float) $invoice->grand_total);
        $this->assertEquals(276.35, (float) $invoice->items()->first()->net_amount);
    }

    public function test_the_invoice_total_is_the_sum_of_all_lines(): void
    {
        $supplier = $this->makeSupplier();
        $a = $this->makePlainProduct();
        $b = $this->makePlainProduct();

        $this->place($this->order($supplier->id, [
            $this->line($a->id, ['order_qty' => 2, 'unit_price' => 50]),                       // 100
            $this->line($b->id, ['order_qty' => 4, 'unit_price' => 25, 'discount' => 50]),     // 50
        ]))->assertSessionHasNoErrors();

        $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->firstOrFail();
        $this->assertEquals(150.00, (float) $invoice->grand_total);
        $this->assertSame(2, $invoice->items()->count());
    }

    public function test_a_total_sent_by_the_browser_is_ignored(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();

        $payload = $this->order($supplier->id, [$this->line($product->id, ['order_qty' => 1, 'unit_price' => 10, 'net_amount' => 1, 'grand_total' => 1])]);
        $payload['grand_total'] = 1;

        $this->place($payload)->assertSessionHasNoErrors();

        $this->assertEquals(10.00, (float) SupplierInvoice::where('supplier_id', $supplier->id)->value('grand_total'));
    }

    public function test_a_hundred_percent_discount_leaves_only_the_face_value(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();

        $this->place($this->order($supplier->id, [$this->line($product->id, ['unit_price' => 80, 'discount' => 100, 'face_value' => 7])]))
            ->assertSessionHasNoErrors();

        $this->assertEquals(7.00, (float) SupplierInvoice::where('supplier_id', $supplier->id)->value('grand_total'));
    }

    public function test_a_missing_discount_and_face_value_count_as_zero(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();

        $this->place($this->order($supplier->id, [['product_id' => $product->id, 'type' => 'SIM', 'order_qty' => 5, 'unit_price' => 12]]))
            ->assertSessionHasNoErrors();

        $this->assertEquals(60.00, (float) SupplierInvoice::where('supplier_id', $supplier->id)->value('grand_total'));
    }

    public function test_a_very_large_order_that_still_fits_is_saved(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();

        // 1,000 x 9,000,000 = 9,000,000,000, just inside the money column (9,999,999,999.99).
        $this->place($this->order($supplier->id, [$this->line($product->id, ['order_qty' => 1000, 'unit_price' => 9000000])]))
            ->assertSessionHasNoErrors();

        $this->assertNotNull(SupplierInvoice::where('supplier_id', $supplier->id)->first());
    }

    // ================================================================== placing an order: numbering

    public function test_invoice_numbers_run_in_order_for_the_year(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();
        $year = now()->year;
        $before = SupplierInvoice::whereYear('invoice_date', $year)->count();

        $this->place($this->order($supplier->id, [$this->line($product->id)]));
        $this->place($this->order($supplier->id, [$this->line($product->id)]));

        $numbers = SupplierInvoice::where('supplier_id', $supplier->id)->orderBy('id')->pluck('invoice_number')->all();
        $this->assertSame([
            sprintf('PO-%d-%04d', $year, $before + 1),
            sprintf('PO-%d-%04d', $year, $before + 2),
        ], $numbers);
    }

    public function test_the_success_message_names_the_new_number_and_returns_to_that_suppliers_history(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();

        $response = $this->place($this->order($supplier->id, [$this->line($product->id)]));
        $number = SupplierInvoice::where('supplier_id', $supplier->id)->value('invoice_number');

        $response->assertRedirect(route('admin.supplier-invoice', ['supplier_id' => $supplier->id]));
        $this->assertStringContainsString($number, session('success'));
    }

    // ================================================================== placing an order: stock

    public function test_a_device_product_raises_the_company_stock(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $this->stockFor($type, 10);
        $product = $this->makeDeviceProduct($type);

        $this->place($this->order($supplier->id, [$this->line($product->id, ['order_qty' => 7])]))
            ->assertSessionHasNoErrors();

        $this->assertSame(17, Stock::where('device_type_id', $type->id)->value('company_available_stock'));
    }

    public function test_a_device_type_with_no_stock_row_yet_gets_one(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $product = $this->makeDeviceProduct($type);
        $this->assertNull(Stock::where('device_type_id', $type->id)->first());

        $this->place($this->order($supplier->id, [$this->line($product->id, ['order_qty' => 4])]))
            ->assertSessionHasNoErrors();

        $stock = Stock::where('device_type_id', $type->id)->get();
        $this->assertCount(1, $stock, 'still one row per device type');
        $this->assertSame(4, $stock->first()->company_available_stock);
        $this->assertNotEmpty($stock->first()->device_category_type);
    }

    public function test_two_lines_for_the_same_device_type_add_up(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $this->stockFor($type, 0);
        $product = $this->makeDeviceProduct($type);

        $this->place($this->order($supplier->id, [
            $this->line($product->id, ['order_qty' => 3]),
            $this->line($product->id, ['order_qty' => 5]),
        ]))->assertSessionHasNoErrors();

        $this->assertSame(8, Stock::where('device_type_id', $type->id)->value('company_available_stock'));
    }

    public function test_a_plain_product_never_touches_stock(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();
        $before = Stock::count();

        $this->place($this->order($supplier->id, [$this->line($product->id, ['order_qty' => 9])]))
            ->assertSessionHasNoErrors();

        $this->assertSame($before, Stock::count());
    }

    // ================================================================== placing an order: bad forms

    public static function badOrders(): array
    {
        return [
            'no items'                  => [fn ($s, $p) => ['supplier_id' => $s, 'items' => []], 'items'],
            'items missing'             => [fn ($s, $p) => ['supplier_id' => $s], 'items'],
            'items is not a list'       => [fn ($s, $p) => ['supplier_id' => $s, 'items' => 'abc'], 'items'],
            'more than 200 lines'       => [fn ($s, $p) => ['supplier_id' => $s, 'items' => array_fill(0, 201, ['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1])], 'items'],
            'no supplier'               => [fn ($s, $p) => ['items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]]], 'supplier_id'],
            'text supplier'             => [fn ($s, $p) => ['supplier_id' => 'abc', 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]]], 'supplier_id'],
            'huge supplier'             => [fn ($s, $p) => ['supplier_id' => '99999999999999999999', 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]]], 'supplier_id'],
            'supplier that is not there' => [fn ($s, $p) => ['supplier_id' => 987654321, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]]], 'supplier_id'],
            'zero supplier'             => [fn ($s, $p) => ['supplier_id' => 0, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]]], 'supplier_id'],
            'no product'                => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]]], 'items.0.product_id'],
            'text product'              => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => 'abc', 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]]], 'items.0.product_id'],
            'huge product'              => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => '99999999999999999999', 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]]], 'items.0.product_id'],
            'product that is not there' => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => 987654321, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]]], 'items.0.product_id'],
            'no type'                   => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'order_qty' => 1, 'unit_price' => 1]]], 'items.0.type'],
            'type too long'             => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => str_repeat('a', 51), 'order_qty' => 1, 'unit_price' => 1]]], 'items.0.type'],
            'no quantity'               => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'unit_price' => 1]]], 'items.0.order_qty'],
            'zero quantity'             => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 0, 'unit_price' => 1]]], 'items.0.order_qty'],
            'negative quantity'         => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => -3, 'unit_price' => 1]]], 'items.0.order_qty'],
            'fraction quantity'         => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1.5, 'unit_price' => 1]]], 'items.0.order_qty'],
            'quantity over a million'   => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1000001, 'unit_price' => 1]]], 'items.0.order_qty'],
            'quantity too big for int'  => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 99999999999, 'unit_price' => 1]]], 'items.0.order_qty'],
            'text quantity'             => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 'many', 'unit_price' => 1]]], 'items.0.order_qty'],
            'no price'                  => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1]]], 'items.0.unit_price'],
            'negative price'            => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => -1]]], 'items.0.unit_price'],
            'text price'                => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 'free']]], 'items.0.unit_price'],
            'price above the limit'     => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 10000000]]], 'items.0.unit_price'],
            'negative discount'         => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1, 'discount' => -1]]], 'items.0.discount'],
            'discount over 100'         => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1, 'discount' => 100.01]]], 'items.0.discount'],
            'text discount'             => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1, 'discount' => 'half']]], 'items.0.discount'],
            'negative face value'       => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1, 'face_value' => -5]]], 'items.0.face_value'],
            'face value above the limit' => [fn ($s, $p) => ['supplier_id' => $s, 'items' => [['product_id' => $p, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1, 'face_value' => 10000000]]], 'items.0.face_value'],
        ];
    }

    #[DataProvider('badOrders')]
    public function test_a_bad_order_is_refused_with_a_message_and_nothing_is_saved(\Closure $build, string $field): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();
        $invoices = SupplierInvoice::count();
        $items = SupplierInvoiceItem::count();

        $this->actingAs($this->admin())->from(route('admin.supplier-invoice'))
            ->post(route('admin.supplier-invoice.store'), $build($supplier->id, $product->id))
            ->assertSessionHasErrors($field);

        $this->assertSame($invoices, SupplierInvoice::count());
        $this->assertSame($items, SupplierInvoiceItem::count());
    }

    public function test_one_bad_line_stops_the_whole_order_and_stock_stays_as_it_was(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $this->stockFor($type, 10);
        $product = $this->makeDeviceProduct($type);

        $this->actingAs($this->admin())->post(route('admin.supplier-invoice.store'), $this->order($supplier->id, [
            $this->line($product->id, ['order_qty' => 5]),
            $this->line($product->id, ['order_qty' => -1]),
        ]))->assertSessionHasErrors('items.1.order_qty');

        $this->assertSame(10, Stock::where('device_type_id', $type->id)->value('company_available_stock'));
        $this->assertSame(0, SupplierInvoice::where('supplier_id', $supplier->id)->count());
    }

    public function test_an_inactive_supplier_cannot_be_ordered_from(): void
    {
        $supplier = $this->makeSupplier(['status' => 'Inactive']);
        $product = $this->makePlainProduct();

        $this->actingAs($this->admin())->post(route('admin.supplier-invoice.store'), $this->order($supplier->id, [$this->line($product->id)]))
            ->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, SupplierInvoice::where('supplier_id', $supplier->id)->count());
    }

    public function test_a_line_too_big_for_the_money_column_is_refused_not_a_database_error(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();

        // 1,000,000 x 9,999,999 is about 10 trillion; the column holds up to 9,999,999,999.99.
        $this->actingAs($this->admin())->post(route('admin.supplier-invoice.store'), $this->order($supplier->id, [
            $this->line($product->id, ['order_qty' => 1000000, 'unit_price' => 9999999]),
        ]))->assertSessionHasErrors('items.0.unit_price');

        $this->assertSame(0, SupplierInvoice::where('supplier_id', $supplier->id)->count());
    }

    public function test_many_lines_that_are_each_fine_but_too_big_together_are_refused(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();

        // each line is 9,000,000,000 (fits), three lines together are 27,000,000,000 (does not fit)
        $lines = array_fill(0, 3, $this->line($product->id, ['order_qty' => 1000, 'unit_price' => 9000000]));

        $this->actingAs($this->admin())->post(route('admin.supplier-invoice.store'), $this->order($supplier->id, $lines))
            ->assertSessionHasErrors('items');

        $this->assertSame(0, SupplierInvoice::where('supplier_id', $supplier->id)->count());
    }

    // ================================================================== download

    public function test_an_invoice_downloads_as_a_pdf_named_after_its_number(): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();
        $this->place($this->order($supplier->id, [$this->line($product->id)]));
        $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->firstOrFail();

        $response = $this->actingAs($this->admin())->get(route('admin.supplier-invoice.download', $invoice->id))->assertOk();

        $this->assertStringContainsString($invoice->invoice_number . '.pdf', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[DataProvider('badIds')]
    public function test_a_bad_invoice_id_for_the_download_is_a_clean_404(string $id): void
    {
        $this->actingAs($this->admin())->get("/admin/supplier/invoice/{$id}/download")->assertNotFound();
    }

    // ================================================================== who may enter

    public static function otherRoles(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('otherRoles')]
    public function test_only_an_admin_can_use_purchase_orders(string $role): void
    {
        $supplier = $this->makeSupplier();
        $product = $this->makePlainProduct();
        $user = $this->makeAdmin($role);

        $this->actingAs($user)->get(route('admin.supplier-invoice'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.supplier-invoice.store'), $this->order($supplier->id, [$this->line($product->id)]))->assertForbidden();
        $this->actingAs($user)->get(route('admin.suppliers.purchase-data', $supplier->id))->assertForbidden();

        $this->assertSame(0, SupplierInvoice::where('supplier_id', $supplier->id)->count());
    }

    public function test_a_logged_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('admin.supplier-invoice'))->assertRedirect();
        $this->post(route('admin.supplier-invoice.store'), [])->assertRedirect();
    }
}