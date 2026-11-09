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

    private function stockIn(Supplier $s, DeviceType $t, $qty = 5)
    {
        return $this->post(route('admin.stock.store'), ['supplier_id' => $s->id, 'device_type_id' => $t->id, 'stock_in' => $qty]);
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
        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'DEVICE', 'device_type_id' => $t->id, 'scans' => [self::IMEI_A]])
            ->assertOk()->assertJsonPath('accepted', 1);

        $this->put(route('admin.suppliers.update', $s->id), $this->supplierForm([], ['supplier_name' => 'Changed']))->assertSessionHasErrors('products');
        $this->assertSame('Suwen test', $s->fresh()->name, 'nothing about the supplier changed');
    }

    // ------------------------------------------------------------------------------------------ stock-in

    public function test_stock_in_needs_the_device_on_the_suppliers_list(): void
    {
        $t = $this->type();
        $other = $this->type('Other', 'Plus');
        $s = $this->supplierWith($t);

        $this->stockIn($s, $other)->assertSessionHasErrors('device_type_id');
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

    public function test_from_the_enforcement_date_stock_comes_only_from_scanned_deliveries(): void
    {
        $t = $this->type();
        $s = $this->supplierWith($t);
        config(['supplier_deliveries.enforce_from' => now()->subDay()->toDateString()]);

        $this->stockIn($s, $t, 5)->assertSessionHasErrors('stock_in');

        $id = $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'DEVICE', 'device_type_id' => $t->id, 'scans' => [self::IMEI_A]])->json('delivery_id');
        $this->post(route('admin.stock.store'), ['supplier_id' => $s->id, 'device_type_id' => $t->id, 'delivery_id' => $id])->assertSessionHasNoErrors();
        $this->assertSame(1, (int) Stock::where('device_type_id', $t->id)->value('company_available_stock'));
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
}
