<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DevicePrice;
use App\Models\DeviceType;
use App\Models\Product;
use App\Models\Sim;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\SupplierDelivery;
use App\Models\SupplierDeliveryUnit;
use App\Models\SupplierInvoice;
use App\Services\Billing\ApprovedPrices;
use App\Services\Supplier\PurchaseOrders;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The purchasing chain, end to end on SQLite: purchase order -> scanned delivery (against the order) -> stock-in,
 * plus SIM numbers captured at receipt and checked at registration, and approved Device Prices feeding sales
 * invoices.
 *
 * The full migration history cannot build a fresh SQLite database, so the older tables these features touch are
 * created by hand (columns mirror the real migrations) and the NEW tables/columns come from the real migration
 * files, so the migrations themselves are exercised too.
 */
class PurchasingChainTest extends TestCase
{
    private const IMEI_A = '355172106043787';
    private const IMEI_B = '490154203237518';
    private const IMEI_C = '356938035643809';
    private const ICCID_1 = '8994011234567890123';
    private const ICCID_2 = '8994011234567890124';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        config(['supplier_deliveries.enabled' => true, 'supplier_deliveries.enforce_from' => null]);

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
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->text('address')->nullable();
            $t->string('country')->nullable();
            $t->string('state')->nullable();
            $t->string('phone_number')->nullable();
            $t->string('email')->nullable();
            $t->string('website')->nullable();
            $t->string('gstin_number')->nullable();
            $t->string('status')->default('Active');
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
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('product_name');
            $t->text('description')->nullable();
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->timestamps();
        });
        Schema::create('supplier_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_id');
            $t->unsignedBigInteger('product_id');
            $t->decimal('price', 10, 2)->default(0);
            $t->integer('qty')->default(0);
            $t->timestamps();
            $t->unique(['supplier_id', 'product_id']);
        });
        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('invoice_number')->unique();
            $t->unsignedBigInteger('supplier_id');
            $t->date('invoice_date');
            $t->decimal('grand_total', 12, 2)->default(0);
            $t->timestamps();
        });
        Schema::create('supplier_invoice_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('supplier_invoice_id');
            $t->unsignedBigInteger('product_id');
            $t->string('type');
            $t->integer('order_qty');
            $t->decimal('unit_price', 12, 2);
            $t->decimal('discount', 5, 2)->default(0);
            $t->decimal('face_value', 12, 2)->default(0);
            $t->decimal('net_amount', 12, 2);
            $t->timestamps();
        });
        Schema::create('stocks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_type_id');
            $t->string('device_category_type')->nullable();
            $t->integer('company_available_stock')->default(0);
            $t->timestamps();
        });
        Schema::create('stock_transfer_ledgers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('stock_id');
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('supplier')->nullable();
            $t->integer('stock_in');
            $t->text('description')->nullable();
            $t->date('stocked_in_date');
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
            $t->unsignedBigInteger('device_type_id')->nullable();
            $t->unsignedBigInteger('dealer_id')->nullable();
            $t->timestamps();
        });
        Schema::create('device_prices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('device_type_id')->unique();
            $t->decimal('landing_cost', 14, 2);
            $t->decimal('company_margin', 14, 2);
            $t->decimal('distributor_margin', 14, 2);
            $t->decimal('retailer_margin', 14, 2);
            $t->date('effective_from');
            $t->string('updated_by', 64)->nullable();
            $t->timestamps();
        });

        // The new tables and columns, from the real migration files.
        foreach (['2026_11_09_000002_create_supplier_delivery_tables', '2026_11_09_000003_add_stock_link_to_supplier_deliveries', '2026_11_09_000004_link_purchase_orders_deliveries_and_sim_numbers'] as $m) {
            (require database_path("migrations/{$m}.php"))->up();
        }

        Admin::forceCreate(['admin_id' => 'adm-1', 'username' => 'admin', 'full_name' => 'Admin', 'role' => 'ADMIN', 'status' => 'ACTIVE']);
        $this->actingAs(Admin::find('adm-1'));
    }

    // ------------------------------------------------------------------------------------------ helpers

    private function supplier(string $name = 'Suwen test'): Supplier
    {
        return Supplier::create(['name' => $name, 'status' => 'Active']);
    }

    private function type(string $category = 'Shalotrack', string $model = 'V5'): DeviceType
    {
        return DeviceType::create(['device_category' => $category, 'model' => $model]);
    }

    private function offerDevice(Supplier $s, DeviceType $t, float $price = 1200): Product
    {
        $p = Product::create(['product_name' => $t->device_category . ' ' . $t->model, 'device_type_id' => $t->id]);
        $s->products()->attach($p->id, ['price' => $price, 'qty' => 0]);

        return $p;
    }

    private function offerSims(Supplier $s, float $price = 150): Product
    {
        $p = Product::create(['product_name' => 'Dialog SIM card']);
        $s->products()->attach($p->id, ['price' => $price, 'qty' => 0]);

        return $p;
    }

    private function line(Product $p, int $qty, float $price, string $type = 'Device'): array
    {
        return ['product_id' => $p->id, 'type' => $type, 'order_qty' => $qty, 'unit_price' => $price, 'discount' => 0, 'face_value' => 0];
    }

    private function placeOrder(Supplier $s, array $lines): SupplierInvoice
    {
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $s->id, 'items' => $lines])->assertSessionHasNoErrors();

        return SupplierInvoice::where('supplier_id', $s->id)->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------------------------------------ purchase orders

    public function test_a_purchase_order_never_changes_stock(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        Stock::create(['device_type_id' => $t->id, 'company_available_stock' => 10]);
        $p = $this->offerDevice($s, $t);

        $order = $this->placeOrder($s, [$this->line($p, 12, 1200)]);

        $this->assertSame(10, Stock::where('device_type_id', $t->id)->value('company_available_stock'));
        $this->assertSame('14400.00', (string) $order->grand_total);
        $this->assertSame('ORDERED', $order->status);
        $this->assertSame(sprintf('PO-%d-0001', now()->year), $order->invoice_number);
    }

    public function test_only_products_on_the_suppliers_list_can_be_ordered(): void
    {
        $s = $this->supplier();
        $other = $this->supplier('Other');
        $p = $this->offerDevice($other, $this->type());

        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $s->id, 'items' => [$this->line($p, 1, 10)]])
            ->assertSessionHasErrors('items.0.product_id');

        $this->assertSame(0, SupplierInvoice::count());
    }

    public function test_totals_are_worked_out_in_cents_on_the_server(): void
    {
        $s = $this->supplier();
        $p = $this->offerDevice($s, $this->type());

        // 3 x 100.50 = 301.50, minus 10% = 271.35, plus face value 5 = 276.35
        $order = $this->placeOrder($s, [['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 3, 'unit_price' => 100.50, 'discount' => 10, 'face_value' => 5]]);

        $this->assertSame('276.35', (string) $order->grand_total);
        $this->assertSame('276.35', (string) $order->items()->first()->net_amount);
    }

    public function test_numbers_follow_the_highest_number_so_a_gap_never_repeats_a_number(): void
    {
        $s = $this->supplier();
        $p = $this->offerDevice($s, $this->type());
        $y = now()->year;
        SupplierInvoice::create(['invoice_number' => "PO-{$y}-0007", 'supplier_id' => $s->id, 'invoice_date' => now()->toDateString(), 'grand_total' => 1]);

        $order = $this->placeOrder($s, [$this->line($p, 1, 10)]);

        $this->assertSame("PO-{$y}-0008", $order->invoice_number);
    }

    public function test_an_order_can_be_edited_until_goods_arrive_then_it_is_locked(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        $p = $this->offerDevice($s, $t);
        $order = $this->placeOrder($s, [$this->line($p, 12, 1200)]);

        $this->get(route('admin.supplier-invoice.edit', $order->id))->assertOk()->assertSee($order->invoice_number);
        $this->put(route('admin.supplier-invoice.update', $order->id), ['items' => [$this->line($p, 10, 1100)]])
            ->assertSessionHasNoErrors();
        $this->assertSame('11000.00', (string) $order->fresh()->grand_total);
        $this->assertSame(1, $order->items()->count());

        // A delivery against the order locks it.
        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $order->id, 'scans' => [self::IMEI_A],
        ])->assertOk()->assertJsonPath('accepted', 1);

        $this->put(route('admin.supplier-invoice.update', $order->id), ['items' => [$this->line($p, 99, 1)]])
            ->assertSessionHasErrors('invoice');
        $this->assertSame('11000.00', (string) $order->fresh()->grand_total);
        $this->get(route('admin.supplier-invoice.edit', $order->id))->assertRedirect();
        $this->patch(route('admin.supplier-invoice.cancel', $order->id), ['reason' => 'changed mind'])->assertSessionHasErrors('invoice');
    }

    public function test_an_order_is_cancelled_with_a_reason_not_deleted(): void
    {
        $s = $this->supplier();
        $p = $this->offerDevice($s, $this->type());
        $order = $this->placeOrder($s, [$this->line($p, 2, 50)]);

        $this->patch(route('admin.supplier-invoice.cancel', $order->id), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->patch(route('admin.supplier-invoice.cancel', $order->id), ['reason' => 'Supplier out of stock'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame('CANCELLED', $order->status);
        $this->assertSame('Supplier out of stock', $order->cancel_reason);
        $this->assertNotNull($order->cancelled_at);
        $this->put(route('admin.supplier-invoice.update', $order->id), ['items' => [$this->line($p, 1, 1)]])->assertSessionHasErrors('invoice');
    }

    public function test_the_order_pages_render_with_status_and_actions(): void
    {
        $s = $this->supplier();
        $p = $this->offerDevice($s, $this->type());
        $order = $this->placeOrder($s, [$this->line($p, 2, 50)]);

        $this->get(route('admin.supplier-invoice', ['supplier_id' => $s->id]))
            ->assertOk()->assertSee($order->invoice_number)->assertSee(route('admin.supplier-invoice.edit', $order->id), false);

        $this->getJson(route('admin.suppliers.purchase-data', $s->id))
            ->assertOk()->assertJsonPath('products.0.type', 'Device')->assertJsonPath('products.0.discount', 0);
    }

    public function test_non_admins_cannot_edit_or_cancel_orders(): void
    {
        $s = $this->supplier();
        $p = $this->offerDevice($s, $this->type());
        $order = $this->placeOrder($s, [$this->line($p, 2, 50)]);

        Admin::forceCreate(['admin_id' => 'dl-1', 'username' => 'dealer', 'full_name' => 'Dealer', 'role' => 'DEALER', 'status' => 'ACTIVE']);
        $this->actingAs(Admin::find('dl-1'));

        $this->get(route('admin.supplier-invoice.edit', $order->id))->assertForbidden();
        $this->put(route('admin.supplier-invoice.update', $order->id), ['items' => [$this->line($p, 1, 1)]])->assertForbidden();
        $this->patch(route('admin.supplier-invoice.cancel', $order->id), ['reason' => 'nope nope'])->assertForbidden();
        $this->assertSame('ORDERED', $order->fresh()->status);
    }

    // ------------------------------------------------------------------------------------------ deliveries

    public function test_receive_page_offers_only_the_suppliers_products_and_its_open_orders(): void
    {
        $s = $this->supplier();
        $mine = $this->type('Shalotrack', 'V5');
        $notMine = $this->type('Other', 'X1');
        $p = $this->offerDevice($s, $mine);
        $order = $this->placeOrder($s, [$this->line($p, 12, 1200)]);

        $this->get(route('admin.suppliers.deliveries', $s->id))
            ->assertOk()
            ->assertSee('Shalotrack V5')
            ->assertDontSee('Other X1')
            ->assertViewHas('openOrders', fn ($o) => $o->pluck('id')->all() === [$order->id]);
    }

    public function test_a_device_type_the_supplier_does_not_supply_is_refused(): void
    {
        $s = $this->supplier();
        $this->offerDevice($s, $this->type('Shalotrack', 'V5'));
        $notMine = $this->type('Other', 'X1');

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'DEVICE', 'device_type_id' => $notMine->id, 'scans' => [self::IMEI_A]])
            ->assertStatus(422)->assertJsonValidationErrors('device_type_id');
        $this->assertSame(0, SupplierDelivery::count());
    }

    public function test_a_delivery_against_an_order_is_linked_and_over_receipt_is_warned_not_blocked(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        $p = $this->offerDevice($s, $t);
        $order = $this->placeOrder($s, [$this->line($p, 2, 1200)]);

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $order->id, 'scans' => [self::IMEI_A, self::IMEI_B],
        ])->assertOk()->assertJsonPath('accepted', 2)->assertJsonPath('warning', null);

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $order->id, 'scans' => [self::IMEI_C],
        ])->assertOk()->assertJsonPath('accepted', 1)
            ->assertJsonPath('warning', fn ($w) => str_contains((string) $w, '3 received, 2 ordered'));

        $summary = app(PurchaseOrders::class)->summary($order->fresh());
        $this->assertSame(['ordered' => 2, 'received' => 3], $summary['device_types'][$t->id]);
        $this->assertSame(2, SupplierDelivery::where('supplier_invoice_id', $order->id)->count());
    }

    public function test_an_order_from_another_supplier_or_cancelled_or_without_the_product_is_refused(): void
    {
        $s = $this->supplier();
        $other = $this->supplier('Other');
        $t = $this->type();
        $t2 = $this->type('Shalotrack', 'V6');
        $p = $this->offerDevice($s, $t);
        $this->offerDevice($s, $t2);
        $po = $this->offerDevice($other, $t);
        $othersOrder = $this->placeOrder($other, [$this->line($po, 5, 1)]);
        $onlyV5 = $this->placeOrder($s, [$this->line($p, 5, 1)]);

        $send = fn ($orderId, $typeId) => $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $typeId, 'purchase_order_id' => $orderId, 'scans' => [self::IMEI_A],
        ]);

        $send($othersOrder->id, $t->id)->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
        $send($onlyV5->id, $t2->id)->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');

        $this->patch(route('admin.supplier-invoice.cancel', $onlyV5->id), ['reason' => 'cancelled order']);
        $send($onlyV5->id, $t->id)->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');

        $this->assertSame(0, SupplierDelivery::count());
    }

    public function test_voiding_the_only_delivery_unlocks_the_order_again(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        $p = $this->offerDevice($s, $t);
        $order = $this->placeOrder($s, [$this->line($p, 2, 1200)]);

        $id = $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $order->id, 'scans' => [self::IMEI_A],
        ])->json('delivery_id');
        $this->assertNotNull(app(PurchaseOrders::class)->lockReason($order->fresh()));

        $this->delete(route('admin.suppliers.deliveries.void', [$s->id, $id]), ['reason' => 'wrong box']);
        $this->assertNull(app(PurchaseOrders::class)->lockReason($order->fresh()));
    }

    // ------------------------------------------------------------------------------------------ SIM numbers

    public function test_a_sim_delivery_records_each_iccid_with_its_sim_number(): void
    {
        $s = $this->supplier();
        $this->offerSims($s);

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'SIM',
            'scans' => [
                ['iccid' => self::ICCID_1, 'sim_number' => '0771234567'],
                ['iccid' => self::ICCID_2, 'sim_number' => '077123456'],    // 9 digits
            ],
        ])->assertOk()->assertJsonPath('accepted', 1)->assertJsonPath('rejected.0.identifier', self::ICCID_2);

        $this->assertSame('0771234567', SupplierDeliveryUnit::where('identifier', self::ICCID_1)->value('sim_number'));
    }

    public function test_a_sim_number_already_used_is_refused(): void
    {
        $s = $this->supplier();
        Sim::create(['sim_number' => '0770000001', 'sim_type' => 'Dialog', 'imsi' => '413020000000001', 'iccid' => '8994019999999999999', 'sim_status' => 'Activated']);

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'SIM', 'scans' => [['iccid' => self::ICCID_1, 'sim_number' => '0770000001']],
        ])->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('rejected.0.reason', fn ($r) => str_contains($r, 'already registered'));

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'SIM', 'scans' => [['iccid' => self::ICCID_1, 'sim_number' => '0771111111']],
        ])->assertOk()->assertJsonPath('accepted', 1);
        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'SIM', 'scans' => [['iccid' => self::ICCID_2, 'sim_number' => '0771111111']],
        ])->assertOk()->assertJsonPath('accepted', 0);
    }

    public function test_a_sim_delivery_must_send_pairs(): void
    {
        $s = $this->supplier();

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'SIM', 'scans' => [self::ICCID_1]])
            ->assertStatus(422);
    }

    public function test_registering_a_sim_with_a_different_number_than_received_is_refused(): void
    {
        $s = $this->supplier();
        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'SIM', 'scans' => [['iccid' => self::ICCID_1, 'sim_number' => '0771234567']],
        ])->assertOk();

        $this->post(route('admin.stock.sim.store'), [
            'sim_type' => 'Dialog', 'sim_number' => '0779999999', 'imsi' => '413021234567890', 'iccid' => self::ICCID_1, 'sim_status' => 'Activated',
        ])->assertSessionHasErrors('sim_number');
        $this->assertSame(0, Sim::count());

        $this->post(route('admin.stock.sim.store'), [
            'sim_type' => 'Dialog', 'sim_number' => '0771234567', 'imsi' => '413021234567890', 'iccid' => self::ICCID_1, 'sim_status' => 'Activated',
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, Sim::count());
        $this->assertNotNull(SupplierDeliveryUnit::where('identifier', self::ICCID_1)->value('registered_at'));
    }

    public function test_setup_device_fills_in_the_sim_number_received_with_the_sim(): void
    {
        $s = $this->supplier();
        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'SIM', 'scans' => [['iccid' => self::ICCID_1, 'sim_number' => '0771234567']],
        ])->assertOk();

        $this->postJson(route('admin.device.scan.check'), ['iccid' => self::ICCID_1])
            ->assertOk()
            ->assertJsonPath('iccid.reason', 'not_registered')
            ->assertJsonPath('iccid.received_sim_number', '0771234567');
    }

    // ------------------------------------------------------------------------------------------ sales prices

    public function test_approved_prices_follow_the_buyer_type(): void
    {
        $t = $this->type('Shalotrack', 'V5');
        DevicePrice::create(['device_type_id' => $t->id, 'landing_cost' => '1000.00', 'company_margin' => '200.00', 'distributor_margin' => '300.00', 'retailer_margin' => '500.00', 'effective_from' => now()->toDateString()]);
        $transfers = [(object) ['id' => 1, 'device_category' => 'Shalotrack V5'], (object) ['id' => 2, 'device_category' => 'Unknown thing']];

        $distributor = new \App\Models\Dealer(['dealer_status' => 'distributor']);
        $dealer = new \App\Models\Dealer(['dealer_status' => 'dealer']);

        $this->assertSame([1 => 120000, 2 => null], app(ApprovedPrices::class)->forTransfers($distributor, $transfers));
        $this->assertSame([1 => 150000, 2 => null], app(ApprovedPrices::class)->forTransfers($dealer, $transfers));
    }
}
