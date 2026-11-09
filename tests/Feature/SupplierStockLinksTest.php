<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DeviceType;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockTransferLedger;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PurchasingSchema;
use Tests\TestCase;

/**
 * Supplier product list <-> device types <-> stock-in: the links the other screens depend on.
 */
class SupplierStockLinksTest extends TestCase
{
    use PurchasingSchema;

    private const IMEI_A = '355172106043787';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        config(['supplier_deliveries.enabled' => true, 'supplier_deliveries.enforce_from' => null]);
        $this->buildPurchasingSchema();
        Admin::forceCreate(['admin_id' => 'adm-1', 'username' => 'admin', 'full_name' => 'Admin', 'role' => 'ADMIN', 'status' => 'ACTIVE']);
        $this->actingAs(Admin::find('adm-1'));
    }

    private function type(string $category = 'Shalotrack', string $model = 'Basic'): DeviceType
    {
        return DeviceType::create(['device_category' => $category, 'model' => $model]);
    }

    private function supplierForm(array $products, array $extra = []): array
    {
        return array_merge(['supplier_name' => 'Suwen test', 'products' => $products], $extra);
    }

    private function supplierWith(DeviceType $t): Supplier
    {
        $this->post(route('admin.suppliers.store'), $this->supplierForm([['device_type_id' => $t->id, 'price' => 1200, 'qty' => 12]]))
            ->assertSessionHasNoErrors();

        return Supplier::latest('id')->firstOrFail();
    }

    private int $serial = 0;

    /** A new valid IMEI each call (Luhn check digit). */
    private function imei(): string
    {
        $body = '35517210' . str_pad((string) ++$this->serial, 6, '0', STR_PAD_LEFT);
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $d = (int) $body[$i];
            if ($i % 2 === 1) {
                $d *= 2;
                if ($d > 9) {
                    $d -= 9;
                }
            }
            $sum += $d;
        }

        return $body . ((10 - $sum % 10) % 10);
    }

    private function order(Supplier $s, Product $p, int $qty, string $type = 'Device'): SupplierInvoice
    {
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $s->id, 'items' => [
            ['product_id' => $p->id, 'type' => $type, 'order_qty' => $qty, 'unit_price' => 10],
        ]])->assertSessionHasNoErrors();

        return SupplierInvoice::where('supplier_id', $s->id)->latest('id')->firstOrFail();
    }

    /** Order $count devices and receive them on one scanned delivery; returns the delivery id. */
    private function receive(Supplier $s, DeviceType $t, int $count = 1, ?array $imeis = null): int
    {
        $imeis ??= array_map(fn () => $this->imei(), range(1, $count));
        $po = $this->order($s, Product::where('device_type_id', $t->id)->firstOrFail(), count($imeis));

        return (int) $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $po->id, 'scans' => $imeis,
        ])->assertOk()->json('delivery_id');
    }

    /** Stock-in the way the screen does it: from a scanned delivery of $qty devices. */
    private function stockIn(Supplier $s, DeviceType $t, $qty = 5)
    {
        return $this->post(route('admin.stock.store'), ['supplier_id' => $s->id, 'device_type_id' => $t->id, 'delivery_id' => $this->receive($s, $t, $qty)]);
    }

    // ------------------------------------------------------------------------------------------ supplier products

    public function test_a_device_picked_or_typed_by_name_is_linked_to_its_device_type(): void
    {
        $t = $this->type('Shalotrack', 'Basic');

        $this->post(route('admin.suppliers.store'), $this->supplierForm([
            ['device_type_id' => $t->id, 'price' => 1200, 'qty' => 10],
            ['product_name' => '  shalotrack   BASIC ', 'price' => 1300, 'qty' => 2],   // the same device, typed
            ['product_name' => 'Dialog SIM card', 'price' => 150, 'qty' => 100],
        ]))->assertSessionHasNoErrors();

        $s = Supplier::firstOrFail();
        $this->assertSame(1, Product::where('device_type_id', $t->id)->count(), 'one product per device type');
        $this->assertSame(2, $s->products()->count(), 'the typed device merged into the picked one');
        $device = $s->products()->where('device_type_id', $t->id)->firstOrFail();
        $this->assertSame(12, (int) $device->pivot->qty);
        $this->assertNull($s->products()->where('product_name', 'Dialog SIM card')->value('device_type_id'));
    }

    public function test_the_edit_form_keeps_each_products_link(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);
        $sim = Product::create(['product_name' => 'Dialog SIM card']);
        $s->products()->attach($sim->id, ['price' => 150, 'qty' => 0]);

        $html = $this->get(route('admin.suppliers'))->assertOk()->getContent();

        // The saved lines come back pre-selected (device by type, SIM by name) instead of an empty "Select product".
        $this->assertStringContainsString('dt:' . $t->id, $html);
        $this->assertStringContainsString('name:Dialog SIM card', $html);
    }

    public function test_a_product_on_an_open_purchase_order_cannot_be_removed(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);
        $product = Product::where('device_type_id', $t->id)->firstOrFail();
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $s->id, 'items' => [
            ['product_id' => $product->id, 'type' => 'Device', 'order_qty' => 2, 'unit_price' => 10],
        ]])->assertSessionHasNoErrors();
        $po = SupplierInvoice::firstOrFail();

        $this->put(route('admin.suppliers.update', $s->id), $this->supplierForm([]))->assertSessionHasErrors('products');
        $this->assertSame(1, $s->products()->count());

        // Once the order is cancelled, it can go.
        $this->patch(route('admin.supplier-invoice.cancel', $po->id), ['reason' => 'not needed']);
        $this->put(route('admin.suppliers.update', $s->id), $this->supplierForm([], ['supplier_name' => 'Renamed']))->assertSessionHasNoErrors();
        $this->assertSame(0, $s->products()->count());
        $this->assertSame('Renamed', $s->fresh()->name);
    }

    public function test_a_device_with_a_scanned_delivery_waiting_cannot_be_removed(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);
        $this->receive($s, $t, 1, [self::IMEI_A]);

        $this->put(route('admin.suppliers.update', $s->id), $this->supplierForm([], ['supplier_name' => 'Changed']))->assertSessionHasErrors('products');
        $this->assertSame('Suwen test', $s->fresh()->name, 'nothing about the supplier changed');
    }

    // ------------------------------------------------------------------------------------------ stock-in

    public function test_stock_in_needs_the_device_on_the_suppliers_list(): void
    {
        $t = $this->type();
        $other = $this->type('Other', 'Plus');
        $s = $this->supplierWith($t);

        $this->post(route('admin.stock.store'), ['supplier_id' => $s->id, 'device_type_id' => $other->id, 'delivery_id' => $this->receive($s, $t, 1)])
            ->assertSessionHasErrors('device_type_id');
        $this->assertSame(0, (int) Stock::where('device_type_id', $other->id)->value('company_available_stock'));

        $this->stockIn($s, $t, 5)->assertSessionHasNoErrors();
        $this->assertSame(5, (int) Stock::where('device_type_id', $t->id)->value('company_available_stock'));
    }

    public function test_stock_in_keeps_the_device_on_the_suppliers_list(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);   // usual order qty 12

        $this->stockIn($s, $t, 12)->assertSessionHasNoErrors();
        $this->stockIn($s, $t, 3)->assertSessionHasNoErrors();

        $line = $s->products()->where('device_type_id', $t->id)->firstOrFail();
        $this->assertSame(12, (int) $line->pivot->qty, 'the usual order quantity is not used up by stock-in');
        $this->assertSame(15, (int) Stock::where('device_type_id', $t->id)->value('company_available_stock'));
    }

    public function test_stock_comes_only_from_scanned_deliveries_and_never_more_than_was_delivered(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);

        // A typed number is not stock: nothing was received.
        $this->post(route('admin.stock.store'), ['supplier_id' => $s->id, 'device_type_id' => $t->id, 'stock_in' => 5])
            ->assertSessionHasErrors('delivery_id');
        $this->assertSame(0, (int) Stock::where('device_type_id', $t->id)->value('company_available_stock'));

        // A delivery of 2: typing 50 next to it still adds exactly the 2 that arrived, and only once.
        $id = $this->receive($s, $t, 2);
        $this->post(route('admin.stock.store'), ['supplier_id' => $s->id, 'device_type_id' => $t->id, 'delivery_id' => $id, 'stock_in' => 50])->assertSessionHasNoErrors();
        $this->assertSame(2, (int) Stock::where('device_type_id', $t->id)->value('company_available_stock'));

        $this->post(route('admin.stock.store'), ['supplier_id' => $s->id, 'device_type_id' => $t->id, 'delivery_id' => $id])->assertSessionHasErrors('delivery_id');
        $this->assertSame(2, (int) Stock::where('device_type_id', $t->id)->value('company_available_stock'));
    }

    public function test_the_stock_page_offers_only_what_is_waiting_and_no_typed_import(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);
        $this->receive($s, $t, 1);

        $this->get(route('admin.stock.manage'))->assertOk()
            ->assertViewHas('deliveriesOn', true)
            ->assertViewHas('openDeliveries', fn ($d) => $d[$s->id]->count() === 1)
            ->assertDontSee('action="' . route('admin.stock.import') . '"', false)
            ->assertSee('Stock is added only from scanned supplier deliveries');
    }

    // ------------------------------------------------------------------------------------------ device types

    public function test_a_new_device_type_gets_a_stock_row_and_capitals_do_not_make_a_new_type(): void
    {
        $this->post(route('admin.device-types.store'), ['device_category' => 'Shalotrack  V5', 'model' => 'Basic', 'protocol' => 'GT06'])
            ->assertSessionHasNoErrors();
        $t = DeviceType::firstOrFail();
        $this->assertSame('Shalotrack V5', $t->device_category);
        $this->assertSame(0, (int) Stock::where('device_type_id', $t->id)->value('company_available_stock'));

        $this->post(route('admin.device-types.store'), ['device_category' => ' shalotrack v5', 'model' => 'Basic', 'protocol' => 'GT06'])
            ->assertSessionHasErrors('device_category');
        $this->assertSame(1, DeviceType::count());
    }

    public function test_the_device_type_list_shows_what_each_type_is_still_missing(): void
    {
        $linked = $this->type('Shalotrack', 'Basic');
        $this->type('Lonely', 'Plus');
        $this->supplierWith($linked);

        $this->get(route('admin.add-device-type'))->assertOk()
            ->assertSee('Suwen test')
            ->assertSee('No supplier')
            ->assertSee('Not set');
    }

    // ------------------------------------------------------------------------------------------ data repair

    public function test_suppliers_get_back_the_devices_the_old_stock_in_removed(): void
    {
        $t = $this->type();
        $s = Supplier::create(['name' => 'Old supplier', 'status' => 'Active']);
        $stock = Stock::create(['device_type_id' => $t->id, 'company_available_stock' => 7]);
        StockTransferLedger::create(['stock_id' => $stock->id, 'supplier_id' => $s->id, 'supplier' => 'Old supplier', 'stock_in' => 7, 'stocked_in_date' => now()->toDateString()]);

        $migration = require database_path('migrations/2026_11_09_000005_restore_supplier_product_links_from_stock_history.php');
        $migration->up();
        $migration->up();   // running it twice changes nothing

        $this->assertSame(1, DB::table('supplier_products')->where('supplier_id', $s->id)->count());
        $this->assertSame($t->id, (int) $s->products()->value('device_type_id'));
        $this->assertSame(7, (int) Stock::find($stock->id)->company_available_stock, 'stock is not touched');
    }
    // ------------------------------------------------------------------------------------------ sweep (round 3)

    public function test_a_supplier_with_open_work_cannot_be_deactivated(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);
        $product = Product::where('device_type_id', $t->id)->firstOrFail();
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $s->id, 'items' => [
            ['product_id' => $product->id, 'type' => 'Device', 'order_qty' => 2, 'unit_price' => 10],
        ]]);

        $this->patch(route('admin.suppliers.toggle-status', $s->id))->assertSessionHasErrors('status');
        $this->assertSame('Active', $s->fresh()->status);

        $this->patch(route('admin.supplier-invoice.cancel', SupplierInvoice::firstOrFail()->id), ['reason' => 'not needed']);
        $this->patch(route('admin.suppliers.toggle-status', $s->id))->assertSessionHasNoErrors();
        $this->assertSame('Inactive', $s->fresh()->status);
    }

    public function test_the_supplier_portal_shows_only_its_own_orders_and_their_pdfs(): void
    {
        $t = $this->type();
        $mine = $this->supplierWith($t);
        $product = Product::where('device_type_id', $t->id)->firstOrFail();
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $mine->id, 'items' => [
            ['product_id' => $product->id, 'type' => 'Device', 'order_qty' => 3, 'unit_price' => 10],
        ]]);
        $myOrder = SupplierInvoice::firstOrFail();

        $other = Supplier::create(['name' => 'Other supplier', 'status' => 'Active']);
        $other->products()->attach($product->id, ['price' => 1, 'qty' => 0]);
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $other->id, 'items' => [
            ['product_id' => $product->id, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1],
        ]]);
        $theirOrder = SupplierInvoice::where('supplier_id', $other->id)->firstOrFail();

        Admin::forceCreate(['admin_id' => 'sup-1', 'username' => 'sup', 'full_name' => 'Sup', 'role' => 'SUPPLIER', 'status' => 'ACTIVE', 'supplier_id' => $mine->id]);
        $this->actingAs(Admin::find('sup-1'));

        $this->get(route('supplier.dashboard'))->assertOk()
            ->assertSee($myOrder->invoice_number)
            ->assertDontSee($theirOrder->invoice_number)
            ->assertDontSee('Feature not built');
        $this->get(route('supplier.orders.download', $myOrder->id))->assertOk();
        $this->get(route('supplier.orders.download', $theirOrder->id))->assertNotFound();
    }

    public function test_a_cancelled_order_pdf_says_cancelled(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);
        $product = Product::where('device_type_id', $t->id)->firstOrFail();
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $s->id, 'items' => [
            ['product_id' => $product->id, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 10],
        ]]);
        $po = SupplierInvoice::firstOrFail();
        $this->patch(route('admin.supplier-invoice.cancel', $po->id), ['reason' => 'wrong supplier']);

        $html = view('admin.supplier.invoice_pdf', ['invoice' => $po->fresh()->load('supplier', 'items.product')])->render();
        $this->assertStringContainsString('CANCELLED', $html);
        $this->assertStringContainsString('wrong supplier', $html);
    }

    public function test_from_the_enforcement_date_a_device_is_registered_only_after_its_delivery_is_in_stock(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);
        Stock::where('device_type_id', $t->id)->delete();
        Stock::create(['device_type_id' => $t->id, 'company_available_stock' => 5]);   // other stock exists
        $id = $this->receive($s, $t, 1, [self::IMEI_A]);
        config(['supplier_deliveries.enforce_from' => now()->subDay()->toDateString()]);

        $p = app(\App\Services\Delivery\SupplierDeliveryService::class)->preview('DEVICE', self::IMEI_A, $t->id);
        $this->assertSame('blocked', $p['status']);
        $this->assertStringContainsString('has not been added to stock', $p['message']);

        $this->post(route('admin.stock.store'), ['supplier_id' => $s->id, 'device_type_id' => $t->id, 'delivery_id' => $id])->assertSessionHasNoErrors();
        $this->assertSame('ok', app(\App\Services\Delivery\SupplierDeliveryService::class)->preview('DEVICE', self::IMEI_A, $t->id)['status']);
    }

    public function test_a_device_registered_by_hand_records_who_and_how(): void
    {
        $t = $this->type();
        Stock::where('device_type_id', $t->id)->delete();
        Stock::create(['device_type_id' => $t->id, 'company_available_stock' => 1]);

        $this->post(route('admin.device.store'), ['device_type_id' => $t->id, 'imei_number' => self::IMEI_A])->assertSessionHasNoErrors();

        $row = DB::table('setup_shalotrack_devices')->where('imei_number', self::IMEI_A)->first();
        $this->assertSame('adm-1', $row->registered_by);
        $this->assertSame('manual', $row->intake_source);
    }

    public function test_the_sim_list_shows_which_supplier_delivered_each_sim(): void
    {
        $s = Supplier::create(['name' => 'Dialog Supplies', 'status' => 'Active']);
        $iccid = '8994011234567890123';
        $sim = Product::create(['product_name' => 'Dialog SIM card']);
        $s->products()->attach($sim->id, ['price' => 150, 'qty' => 0]);
        $po = $this->order($s, $sim, 1, 'SIM');
        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'SIM', 'purchase_order_id' => $po->id, 'scans' => [['iccid' => $iccid, 'sim_number' => '0771234567']]])->assertOk();
        $this->post(route('admin.stock.sim.store'), ['sim_type' => 'dialog', 'sim_number' => '0771234567', 'imsi' => '413021234567890', 'iccid' => $iccid, 'sim_status' => 'Activated'])
            ->assertSessionHasNoErrors();

        $this->get(route('admin.add-sim'))->assertOk()->assertSee('Dialog Supplies');
    }
}
