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
use Tests\Concerns\PurchasingSchema;
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
    use PurchasingSchema;

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

        $this->buildPurchasingSchema();

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

    /** A purchase order for $qty SIMs (the supplier's SIM product is put on its list first). */
    private function simOrder(Supplier $s, int $qty, string $name = 'Dialog SIM card'): SupplierInvoice
    {
        $p = Product::firstOrCreate(['product_name' => $name]);
        $s->products()->syncWithoutDetaching([$p->id => ['price' => 150, 'qty' => 0]]);

        return $this->placeOrder($s, [$this->line($p, $qty, 150, 'SIM')]);
    }

    private function receiveSims(Supplier $s, SupplierInvoice $order, array $pairs)
    {
        return $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'SIM', 'purchase_order_id' => $order->id, 'scans' => $pairs]);
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
        $p = $this->offerDevice($s, $this->type('Shalotrack', 'V5'));
        $order = $this->placeOrder($s, [$this->line($p, 1, 1200)]);
        $notMine = $this->type('Other', 'X1');

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'DEVICE', 'device_type_id' => $notMine->id, 'purchase_order_id' => $order->id, 'scans' => [self::IMEI_A]])
            ->assertStatus(422)->assertJsonValidationErrors('device_type_id');
        $this->assertSame(0, SupplierDelivery::count());
    }

    public function test_goods_are_only_received_against_a_purchase_order(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        $this->offerDevice($s, $t);
        $this->offerSims($s);

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'DEVICE', 'device_type_id' => $t->id, 'scans' => [self::IMEI_A]])
            ->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'SIM', 'scans' => [['iccid' => self::ICCID_1, 'sim_number' => '0771234567']]])
            ->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');

        $this->assertSame(0, SupplierDelivery::count());
    }

    public function test_more_than_was_ordered_can_never_be_received(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        $p = $this->offerDevice($s, $t);
        $order = $this->placeOrder($s, [$this->line($p, 2, 1200)]);
        $send = fn (array $imeis) => $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $order->id, 'scans' => $imeis,
        ]);

        // 3 scanned on an order for 2: nothing is saved.
        $send([self::IMEI_A, self::IMEI_B, self::IMEI_C])->assertStatus(422)->assertJsonValidationErrors('scans');
        $this->assertSame(0, SupplierDelivery::count());

        // In two parts up to the ordered 2, then one more is refused.
        $send([self::IMEI_A])->assertOk()->assertJsonPath('accepted', 1);
        $send([self::IMEI_B])->assertOk()->assertJsonPath('accepted', 1);
        $send([self::IMEI_C])->assertStatus(422)->assertJsonValidationErrors('scans');

        $summary = app(PurchaseOrders::class)->summary($order->fresh());
        $this->assertSame(['ordered' => 2, 'received' => 2], $summary['device_types'][$t->id]);
        $this->assertSame(2, SupplierDelivery::where('supplier_invoice_id', $order->id)->count());
    }

    public function test_more_sims_than_were_ordered_can_never_be_received(): void
    {
        $s = $this->supplier();
        $order = $this->simOrder($s, 1, 'Mobitel SIM');

        $this->receiveSims($s, $order, [
            ['iccid' => self::ICCID_1, 'sim_number' => '0711234567'],
            ['iccid' => self::ICCID_2, 'sim_number' => '0711234568'],
        ])->assertStatus(422)->assertJsonValidationErrors('scans');
        $this->assertSame(0, SupplierDeliveryUnit::count());
    }

    public function test_a_fully_received_order_is_no_longer_offered_and_lines_show_what_is_still_to_come(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        $p = $this->offerDevice($s, $t);
        $order = $this->simOrder($s, 1);
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $s->id, 'items' => [$this->line($p, 2, 1200)]]);
        $deviceOrder = SupplierInvoice::latest('id')->firstOrFail();

        $line = collect(app(PurchaseOrders::class)->openOrders($s->id)->firstWhere('id', $deviceOrder->id)['lines'])->firstWhere('kind', 'DEVICE');
        $this->assertSame([2, 0, 2, 'Shalotrack V5'], [$line['ordered'], $line['received'], $line['remaining'], $line['name']]);

        $this->receiveSims($s, $order, [['iccid' => self::ICCID_1, 'sim_number' => '0771234567']])->assertOk()->assertJsonPath('accepted', 1);

        $this->assertSame([$deviceOrder->id], app(PurchaseOrders::class)->openOrders($s->id)->pluck('id')->all());
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
        $order = $this->simOrder($s, 2);

        $this->receiveSims($s, $order, [
            ['iccid' => self::ICCID_1, 'sim_number' => '0771234567'],
            ['iccid' => self::ICCID_2, 'sim_number' => '077123456'],    // 9 digits
        ])->assertOk()->assertJsonPath('accepted', 1)->assertJsonPath('rejected.0.identifier', self::ICCID_2);

        $this->assertSame('0771234567', SupplierDeliveryUnit::where('identifier', self::ICCID_1)->value('sim_number'));
    }

    public function test_a_sim_number_already_used_is_refused(): void
    {
        $s = $this->supplier();
        $order = $this->simOrder($s, 2);
        Sim::create(['sim_number' => '0770000001', 'sim_type' => 'Dialog', 'imsi' => '413020000000001', 'iccid' => '8994019999999999999', 'sim_status' => 'Activated']);

        $this->receiveSims($s, $order, [['iccid' => self::ICCID_1, 'sim_number' => '0770000001']])
            ->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('rejected.0.reason', fn ($r) => str_contains($r, 'already registered'));

        $this->receiveSims($s, $order, [['iccid' => self::ICCID_1, 'sim_number' => '0771111111']])->assertOk()->assertJsonPath('accepted', 1);
        $this->receiveSims($s, $order, [['iccid' => self::ICCID_2, 'sim_number' => '0771111111']])->assertOk()->assertJsonPath('accepted', 0);
    }

    public function test_a_sim_delivery_must_send_pairs(): void
    {
        $s = $this->supplier();
        $order = $this->simOrder($s, 1);

        $this->receiveSims($s, $order, [self::ICCID_1])->assertStatus(422);
    }

    public function test_registering_a_sim_with_a_different_number_than_received_is_refused(): void
    {
        $s = $this->supplier();
        $this->receiveSims($s, $this->simOrder($s, 1), [['iccid' => self::ICCID_1, 'sim_number' => '0771234567']])->assertOk();

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

    public function test_received_sims_wait_on_add_sim_until_they_are_registered(): void
    {
        $s = $this->supplier('Abisara');
        $order = $this->simOrder($s, 2, 'Mobitel SIM');
        $this->receiveSims($s, $order, [
            ['iccid' => self::ICCID_1, 'sim_number' => '0711234567'],
            ['iccid' => self::ICCID_2, 'sim_number' => '0711234568'],
        ])->assertOk()->assertJsonPath('accepted', 2);

        $this->get(route('admin.add-sim'))->assertOk()
            ->assertSee('Received SIMs waiting to be registered')
            ->assertSee(self::ICCID_1)->assertSee(self::ICCID_2)
            ->assertSee('data-type="mobitel"', false)
            ->assertViewHas('waitingSims', fn ($w) => $w->count() === 2 && $w->first()['order'] === $order->invoice_number);

        $this->post(route('admin.stock.sim.store'), [
            'sim_type' => 'mobitel', 'sim_number' => '0711234567', 'imsi' => '413011234567890', 'iccid' => self::ICCID_1, 'sim_status' => 'Not Activated',
        ])->assertSessionHasNoErrors();

        $this->get(route('admin.add-sim'))->assertOk()
            ->assertViewHas('waitingSims', fn ($w) => $w->pluck('iccid')->all() === [self::ICCID_2]);

        // The delivery list says how many of the SIMs are registered.
        $this->get(route('admin.suppliers.deliveries', $s->id))->assertOk()->assertSee('1 of 2 registered');
    }

    public function test_setup_device_fills_in_the_sim_number_received_with_the_sim(): void
    {
        $s = $this->supplier();
        $this->receiveSims($s, $this->simOrder($s, 1), [['iccid' => self::ICCID_1, 'sim_number' => '0771234567']])->assertOk();

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
