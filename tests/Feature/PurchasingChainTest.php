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
        return $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'SIM', 'sim_type' => 'mobitel', 'purchase_order_id' => $order->id, 'scans' => $pairs]);
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

    public function test_a_delivery_against_an_order_is_linked_and_the_order_is_a_hard_limit(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        $p = $this->offerDevice($s, $t);
        $order = $this->placeOrder($s, [$this->line($p, 2, 1200)]);
        $send = fn (array $scans) => $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $order->id, 'scans' => $scans,
        ]);

        // More than ordered in one go: the whole delivery is refused, nothing is saved.
        $send([self::IMEI_A, self::IMEI_B, self::IMEI_C])->assertStatus(422)->assertJsonValidationErrors('scans');
        $this->assertSame(0, SupplierDelivery::count());

        $send([self::IMEI_A, self::IMEI_B])->assertOk()->assertJsonPath('accepted', 2);

        // The order is now fully received: one more unit is refused.
        $send([self::IMEI_C])->assertStatus(422)->assertJsonValidationErrors('scans');

        $summary = app(PurchaseOrders::class)->summary($order->fresh());
        $this->assertSame(['ordered' => 2, 'received' => 2], $summary['device_types'][$t->id]);
        $this->assertSame(1, SupplierDelivery::where('supplier_invoice_id', $order->id)->count());
    }

    public function test_goods_are_received_only_against_a_purchase_order(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        $this->offerDevice($s, $t);

        $this->postJson(route('admin.suppliers.deliveries.store', $s->id), ['kind' => 'DEVICE', 'device_type_id' => $t->id, 'scans' => [self::IMEI_A]])
            ->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
        $this->assertSame(0, SupplierDelivery::count());
    }

    public function test_a_cancelled_delivery_gives_its_quantity_back_to_the_order(): void
    {
        $s = $this->supplier();
        $t = $this->type();
        $p = $this->offerDevice($s, $t);
        $order = $this->placeOrder($s, [$this->line($p, 1, 1200)]);
        $send = fn (string $imei) => $this->postJson(route('admin.suppliers.deliveries.store', $s->id), [
            'kind' => 'DEVICE', 'device_type_id' => $t->id, 'purchase_order_id' => $order->id, 'scans' => [$imei],
        ]);

        $id = $send(self::IMEI_A)->assertOk()->json('delivery_id');
        $send(self::IMEI_B)->assertStatus(422);

        $this->delete(route('admin.suppliers.deliveries.void', [$s->id, $id]), ['reason' => 'wrong box']);
        $send(self::IMEI_B)->assertOk()->assertJsonPath('accepted', 1);
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

    // ------------------------------------------------------------------------------------------ SIM deliveries

    private function simsOrder(Supplier $s, int $qty = 2): array
    {
        $p = $this->offerSims($s);

        return [$p, $this->placeOrder($s, [$this->line($p, $qty, 150, 'SIM')])];
    }

    private function sendSims(Supplier $s, ?SupplierInvoice $order, array $scans, string $type = 'mobitel')
    {
        return $this->postJson(route('admin.suppliers.deliveries.store', $s->id), array_filter([
            'kind' => 'SIM', 'sim_type' => $type, 'purchase_order_id' => $order?->id, 'scans' => $scans,
        ], fn ($v) => $v !== null));
    }

    private function pair(string $iccid, string $number): array
    {
        return ['iccid' => $iccid, 'sim_number' => $number];
    }

    public function test_a_sim_delivery_puts_each_sim_in_the_sim_list_not_activated(): void
    {
        $s = $this->supplier();
        [, $order] = $this->simsOrder($s);

        $this->sendSims($s, $order, [
            $this->pair(self::ICCID_1, '0771234567'),
            $this->pair(self::ICCID_2, '077123456'),    // 9-digit number: rejected
        ])->assertOk()->assertJsonPath('accepted', 1)->assertJsonPath('rejected.0.identifier', self::ICCID_2);

        $sim = Sim::where('iccid', self::ICCID_1)->firstOrFail();
        $this->assertSame('0771234567', $sim->sim_number);
        $this->assertNull($sim->imsi);   // the operator gives no IMSIs
        $this->assertSame('mobitel', $sim->sim_type);
        $this->assertSame('Not Activated', $sim->sim_status);
        $this->assertSame(1, Sim::count());
        $unit = SupplierDeliveryUnit::where('identifier', self::ICCID_1)->firstOrFail();
        $this->assertSame('0771234567', $unit->sim_number);
        $this->assertNotNull($unit->registered_at);
        $this->assertSame((string) $sim->id, $unit->registered_ref);

        // And the Add SIM page lists it with the supplier.
        $this->get(route('admin.add-sim'))->assertOk()->assertSee($s->name)->assertSee('0771234567');
    }

    public function test_a_sim_delivery_is_held_to_the_order_and_needs_a_supplier_that_supplies_sims(): void
    {
        $s = $this->supplier();
        [, $order] = $this->simsOrder($s, 1);

        $this->sendSims($s, $order, [
            $this->pair(self::ICCID_1, '0771234567'),
            $this->pair(self::ICCID_2, '0771234568'),
        ])->assertStatus(422)->assertJsonValidationErrors('scans');
        $this->assertSame(0, Sim::count());
        $this->assertSame(0, SupplierDelivery::count());

        $this->sendSims($s, null, [$this->pair(self::ICCID_1, '0771234567')])
            ->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');

        // A supplier with only devices cannot deliver SIMs.
        $devices = $this->supplier('Devices only');
        $t = $this->type();
        $this->offerDevice($devices, $t);
        $this->sendSims($devices, null, [$this->pair(self::ICCID_1, '0771234567')])
            ->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_orders_expose_what_is_still_to_come_per_line_and_hide_when_all_received(): void
    {
        $s = $this->supplier();
        [, $simOrder] = $this->simsOrder($s, 1);
        $t = $this->type();
        $p = $this->offerDevice($s, $t);
        $devOrder = $this->placeOrder($s, [$this->line($p, 2, 1200)]);

        $line = collect(app(PurchaseOrders::class)->openOrders($s->id)->firstWhere('id', $devOrder->id)['lines'])->firstWhere('kind', 'DEVICE');
        $this->assertSame([2, 0, 2, 'Shalotrack V5'], [$line['ordered'], $line['received'], $line['remaining'], $line['name']]);

        $this->sendSims($s, $simOrder, [$this->pair(self::ICCID_1, '0771234567')], 'Mobitel')->assertOk()->assertJsonPath('accepted', 1);
        $this->assertSame('mobitel', Sim::firstOrFail()->sim_type, 'the type is stored the way Add SIM stores it');
        $this->assertSame([$devOrder->id], app(PurchaseOrders::class)->openOrders($s->id)->pluck('id')->all());
    }

    public function test_a_product_named_like_4_sims_of_mobitel_counts_as_a_sim_product(): void
    {
        $s = $this->supplier('Abisara');
        $p = Product::create(['product_name' => '4 sims of mobitel sims']);
        $s->products()->attach($p->id, ['price' => 100, 'qty' => 4]);
        $order = $this->placeOrder($s, [$this->line($p, 4, 100, 'SIM')]);

        $this->assertSame(4, app(PurchaseOrders::class)->summary($order)['sims_ordered']);
        $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0771234567')])->assertOk()->assertJsonPath('accepted', 1);
    }

    public function test_a_sim_needs_its_number_and_its_type_but_no_imsi(): void
    {
        $s = $this->supplier();
        [, $order] = $this->simsOrder($s);

        // No SIM number: refused. An IMSI is not asked for, and one that is sent is not accepted.
        $this->sendSims($s, $order, [['iccid' => self::ICCID_1]])->assertStatus(422);
        $this->sendSims($s, $order, [['iccid' => self::ICCID_1, 'sim_number' => '0771234567', 'imsi' => '413021111111111']])->assertStatus(422);
        $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0771234567')], '   ')
            ->assertStatus(422)->assertJsonValidationErrors('sim_type');
        $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0771234567')], 'Airtel')
            ->assertStatus(422)->assertJsonValidationErrors('sim_type');
        $this->assertSame(0, Sim::count());

        $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0771234567')])->assertOk()->assertJsonPath('accepted', 1);
        $this->assertNull(Sim::firstOrFail()->imsi);
    }

    public function test_a_sim_number_or_iccid_already_used_is_refused(): void
    {
        $s = $this->supplier();
        [, $order] = $this->simsOrder($s, 4);
        Sim::create(['sim_number' => '0770000001', 'sim_type' => 'Dialog', 'iccid' => '8994019999999999999', 'sim_status' => 'Activated']);

        $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0770000001')])
            ->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('rejected.0.reason', fn ($r) => str_contains($r, 'already registered'));
        $this->sendSims($s, $order, [$this->pair('8994019999999999999', '0771111111')])
            ->assertOk()->assertJsonPath('accepted', 0)->assertJsonPath('rejected.0.reason', fn ($r) => str_contains($r, 'Already in the SIM list'));

        $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0771111111')])->assertOk()->assertJsonPath('accepted', 1);
        // The same SIM again, and the same number on another SIM, are both refused.
        $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0771111111')])->assertOk()->assertJsonPath('accepted', 0);
        $this->sendSims($s, $order, [$this->pair(self::ICCID_2, '0771111111')])->assertOk()->assertJsonPath('accepted', 0);
        // Several SIMs without an IMSI can live side by side (the unique IMSI index allows many NULLs); 3 = the manual one + 2 delivered.
        $this->sendSims($s, $order, [$this->pair(self::ICCID_2, '0771111112')])->assertOk()->assertJsonPath('accepted', 1);
        $this->assertSame(3, Sim::whereNull('imsi')->count());
    }

    public function test_adding_a_delivered_sim_again_on_add_sim_is_refused(): void
    {
        $s = $this->supplier();
        [, $order] = $this->simsOrder($s);
        $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0771234567')])->assertOk();

        $this->post(route('admin.stock.sim.store'), [
            'sim_type' => 'Dialog', 'sim_number' => '0771234567', 'iccid' => self::ICCID_1, 'sim_status' => 'Activated',
        ])->assertSessionHasErrors('iccid');
        $this->assertSame(1, Sim::count());
    }

    public function test_cancelling_a_sim_delivery_takes_the_sims_out_unless_they_are_in_use(): void
    {
        $s = $this->supplier();
        [, $order] = $this->simsOrder($s);
        $id = $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0771234567')])->json('delivery_id');

        Sim::where('iccid', self::ICCID_1)->update(['sim_status' => 'Activated']);
        $this->delete(route('admin.suppliers.deliveries.void', [$s->id, $id]), ['reason' => 'wrong box'])->assertSessionHasErrors('delivery_id');
        $this->assertSame(1, Sim::count());

        Sim::where('iccid', self::ICCID_1)->update(['sim_status' => 'Not Activated']);
        $this->delete(route('admin.suppliers.deliveries.void', [$s->id, $id]), ['reason' => 'wrong box'])->assertSessionHasNoErrors();
        $this->assertSame(0, Sim::count());
        $this->assertSame(0, SupplierDeliveryUnit::count());
    }

    public function test_receive_page_is_order_first_and_offers_only_orders_with_goods_still_to_come(): void
    {
        $s = $this->supplier();
        $this->get(route('admin.suppliers.deliveries', $s->id))->assertOk()->assertSee('Nothing is on order from')->assertDontSee('Select the purchase order');

        [, $order] = $this->simsOrder($s, 1);
        $this->get(route('admin.suppliers.deliveries', $s->id))->assertOk()
            ->assertSee('Select the purchase order')
            ->assertViewHas('openOrders', fn ($o) => $o->count() === 1 && $o->first()['lines'][0]['kind'] === 'SIM' && $o->first()['lines'][0]['remaining'] === 1);

        $this->sendSims($s, $order, [$this->pair(self::ICCID_1, '0771234567')])->assertOk();
        $this->get(route('admin.suppliers.deliveries', $s->id))->assertOk()->assertSee('Nothing is on order from');
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
