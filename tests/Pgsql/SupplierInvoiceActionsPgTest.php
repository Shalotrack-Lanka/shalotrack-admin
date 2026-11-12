<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Services\Supplier\PurchaseOrders;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * CHANGING A PURCHASE ORDER AFTER IT IS SAVED, on real PostgreSQL (autotest schema).
 * (Creating an order, the history page and the PDF are tested in PurchaseOrderTest.)
 *
 *   GET   admin.supplier-invoice.edit      the order form, filled with the saved order
 *   PUT   admin.supplier-invoice.update    replace the lines (only while nothing has been received)
 *   PATCH admin.supplier-invoice.adjust    change ONE line's quantity after goods arrived, never below what arrived
 *   PATCH admin.supplier-invoice.cancel    cancel with a reason (kept, marked CANCELLED, never deleted)
 *   GET   supplier.orders.download         the SUPPLIER's own portal: download an order's PDF (own orders only)
 *
 * Rules in simple words:
 *   - an order can be edited or cancelled only while nothing has been received and nothing has been paid,
 *   - after goods arrive, only a line's quantity may change: with a reason, never below what already arrived,
 *     and the price, discount and face value never change (the server works the new totals out),
 *   - a cancelled order cannot be edited, adjusted, cancelled again, received against or paid,
 *   - every change is written to the audit log with before and after,
 *   - a supplier signed in to the portal can download ONLY their own orders,
 *   - only the company ADMIN may use the admin actions.
 *
 * Everything made here is rolled back after each test. One login per test (orders are made through the service).
 */
class SupplierInvoiceActionsPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsSupplierData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
        config(['supplier_deliveries.enabled' => true, 'supplier_deliveries.allow_typed_stock' => false, 'supplier_deliveries.require_purchase_order' => true]);
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function line(Product $product, int $qty = 3, float $price = 100, float $discount = 10, float $face = 5, string $type = 'Device'): array
    {
        return ['product_id' => $product->id, 'type' => $type, 'order_qty' => $qty, 'unit_price' => $price, 'discount' => $discount, 'face_value' => $face];
    }

    /** A supplier with a device product and one order of 3 x 100, 10% off, +5 face value = 275.00. */
    private function order(?Supplier $supplier = null, ?\App\Models\DeviceType $type = null): array
    {
        $supplier ??= $this->makeSupplier();
        $type ??= $this->makeDeviceType();
        $this->supplies($supplier, $type);
        $product = Product::where('device_type_id', $type->id)->firstOrFail();
        $order = app(PurchaseOrders::class)->create($supplier->id, [$this->line($product)], null);

        return [$supplier, $order, $product, $type];
    }

    private function receive(Supplier $supplier, \App\Models\DeviceType $type, SupplierInvoice $order, int $count)
    {
        $imeis = [];
        for ($i = 0; $i < $count; $i++) {
            $imeis[] = $this->validImei();
        }

        return $this->actingAs($this->admin())->postJson(route('admin.suppliers.deliveries.store', $supplier->id), [
            'kind' => 'DEVICE', 'device_type_id' => $type->id, 'purchase_order_id' => $order->id, 'scans' => $imeis,
        ]);
    }

    private function total(SupplierInvoice $o): string
    {
        return (string) $o->fresh()->grand_total;
    }

    // ================================================================== edit page

    public function test_the_edit_page_is_filled_with_the_saved_order(): void
    {
        [$s, $o] = $this->order();

        $html = $this->actingAs($this->admin())->get(route('admin.supplier-invoice.edit', $o->id))->assertOk()->getContent();

        $this->assertStringContainsString($o->invoice_number, $html);
        $this->assertStringContainsString('Edit Purchase Order ' . $o->invoice_number, $html);
        $this->assertStringContainsString($s->name, $html);
        $this->assertStringContainsString(route('admin.supplier-invoice.update', $o->id), $html);
        $this->assertMatchesRegularExpression('/name="_method"\s+value="PUT"/', $html, 'the form saves over the order with PUT');
        $this->assertStringContainsString('An order can be changed until goods are received', $html);
    }

    public function test_an_order_with_received_goods_cannot_be_opened_for_editing(): void
    {
        [$s, $o, , $type] = $this->order();
        $this->receive($s, $type, $o, 1)->assertOk();

        $this->get(route('admin.supplier-invoice.edit', $o->id))
            ->assertRedirect(route('admin.supplier-invoice', ['supplier_id' => $s->id]))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Goods have been received'));
    }

    public function test_a_cancelled_order_cannot_be_opened_for_editing(): void
    {
        [$s, $o] = $this->order();
        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'ordered by mistake'])->assertSessionHasNoErrors();

        $this->get(route('admin.supplier-invoice.edit', $o->id))
            ->assertRedirect(route('admin.supplier-invoice', ['supplier_id' => $s->id]))
            ->assertSessionHas('error', 'This purchase order is cancelled.');
    }

    public static function badIds(): array
    {
        return ['text' => ['abc'], 'sql' => ['1%20OR%201=1'], 'too long' => ['99999999999999999999'], 'missing' => ['999999999']];
    }

    #[DataProvider('badIds')]
    public function test_a_bad_or_missing_order_id_is_a_clean_404_on_every_action(string $id): void
    {
        $this->actingAs($this->admin());

        $this->get("/admin/supplier/invoice/{$id}/edit")->assertNotFound();
        $this->put("/admin/supplier/invoice/{$id}", ['items' => []])->assertNotFound();
        $this->patch("/admin/supplier/invoice/{$id}/adjust", ['item_id' => 1, 'order_qty' => 1, 'reason' => 'valid reason'])->assertNotFound();
        $this->patch("/admin/supplier/invoice/{$id}/cancel", ['reason' => 'valid reason'])->assertNotFound();
    }

    // ================================================================== update

    public function test_an_order_can_be_edited_and_the_server_works_out_the_new_total(): void
    {
        [$s, $o, $product] = $this->order();
        $number = $o->invoice_number;

        $this->actingAs($this->admin())->put(route('admin.supplier-invoice.update', $o->id), ['items' => [
            $this->line($product, 5, 200, 0, 0),
        ], 'grand_total' => '1'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.supplier-invoice', ['supplier_id' => $s->id]))
            ->assertSessionHas('success', "Purchase Order {$number} updated.");

        $fresh = $o->fresh();
        $this->assertSame('1000.00', (string) $fresh->grand_total, 'a total sent by the browser is ignored');
        $this->assertSame($number, $fresh->invoice_number);
        $this->assertSame($s->id, (int) $fresh->supplier_id);
        $this->assertSame((string) $this->admin()->admin_id, (string) $fresh->updated_by);
        $this->assertSame('ORDERED', $fresh->status);
    }

    public function test_editing_replaces_the_lines_with_the_new_ones(): void
    {
        [$s, $o, $product] = $this->order();
        $second = $this->makePlainProduct('Autotest extra product');
        $this->offer($s, $second, 50, 0);

        $this->actingAs($this->admin())->put(route('admin.supplier-invoice.update', $o->id), ['items' => [
            $this->line($product, 1, 100, 0, 0),
            $this->line($second, 4, 50, 0, 0, 'Other'),
        ]])->assertSessionHasNoErrors();

        $lines = SupplierInvoiceItem::where('supplier_invoice_id', $o->id)->orderBy('id')->get();
        $this->assertCount(2, $lines);
        $this->assertSame([1, 4], $lines->pluck('order_qty')->map(fn ($q) => (int) $q)->all());
        $this->assertSame(['100.00', '200.00'], $lines->pluck('net_amount')->map(fn ($n) => (string) $n)->all());
        $this->assertSame('300.00', $this->total($o));
    }

    public function test_editing_works_out_discount_and_face_value_to_the_cent(): void
    {
        [, $o, $product] = $this->order();

        $this->actingAs($this->admin())->put(route('admin.supplier-invoice.update', $o->id), ['items' => [
            $this->line($product, 3, 33.33, 12.5, 7.77),
        ]])->assertSessionHasNoErrors();

        // 3 x 33.33 = 99.99, 12.5% off = 12.50 (rounded), so 87.49 + 7.77 = 95.26
        $this->assertSame('95.26', $this->total($o));
    }

    public function test_a_product_not_on_the_suppliers_list_cannot_be_put_on_an_edit(): void
    {
        [, $o] = $this->order();
        $stranger = $this->makePlainProduct('Autotest not on the list');

        $this->actingAs($this->admin())->put(route('admin.supplier-invoice.update', $o->id), ['items' => [$this->line($stranger)]])
            ->assertSessionHasErrors('items.0.product_id');

        $this->assertSame('275.00', $this->total($o), 'nothing changed');
    }

    public static function badEdits(): array
    {
        return [
            'no lines'            => [fn ($p) => [], 'items'],
            'quantity zero'       => [fn ($p) => [['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 0, 'unit_price' => 1]], 'items.0.order_qty'],
            'quantity too big'    => [fn ($p) => [['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 1000001, 'unit_price' => 1]], 'items.0.order_qty'],
            'quantity decimal'    => [fn ($p) => [['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 1.5, 'unit_price' => 1]], 'items.0.order_qty'],
            'negative price'      => [fn ($p) => [['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => -1]], 'items.0.unit_price'],
            'price too big'       => [fn ($p) => [['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 10000000]], 'items.0.unit_price'],
            'discount over 100'   => [fn ($p) => [['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1, 'discount' => 101]], 'items.0.discount'],
            'negative face value' => [fn ($p) => [['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1, 'face_value' => -1]], 'items.0.face_value'],
            'no type'             => [fn ($p) => [['product_id' => $p->id, 'order_qty' => 1, 'unit_price' => 1]], 'items.0.type'],
            'text product id'     => [fn ($p) => [['product_id' => 'abc', 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]], 'items.0.product_id'],
            'unknown product'     => [fn ($p) => [['product_id' => 999999999, 'type' => 'Device', 'order_qty' => 1, 'unit_price' => 1]], 'items.0.product_id'],
            'line too large'      => [fn ($p) => [['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 1000000, 'unit_price' => 9999999]], 'items.0.unit_price'],
        ];
    }

    #[DataProvider('badEdits')]
    public function test_a_bad_edit_is_refused_and_the_saved_order_stays_as_it_was(\Closure $build, string $field): void
    {
        [, $o, $product] = $this->order();

        $this->actingAs($this->admin())->put(route('admin.supplier-invoice.update', $o->id), ['items' => $build($product)])->assertSessionHasErrors($field);

        $this->assertSame('275.00', $this->total($o));
        $this->assertSame(1, SupplierInvoiceItem::where('supplier_invoice_id', $o->id)->count());
        $this->assertSame(3, (int) SupplierInvoiceItem::where('supplier_invoice_id', $o->id)->value('order_qty'));
    }

    public function test_more_than_200_lines_are_refused(): void
    {
        [, $o, $product] = $this->order();

        $this->actingAs($this->admin())->put(route('admin.supplier-invoice.update', $o->id), ['items' => array_fill(0, 201, $this->line($product, 1, 1, 0, 0))])
            ->assertSessionHasErrors('items');

        $this->assertSame('275.00', $this->total($o));
    }

    public function test_an_order_with_received_goods_or_a_cancelled_one_cannot_be_updated(): void
    {
        [$s, $o, $product, $type] = $this->order();
        [, $cancelled, $cProduct] = $this->order();
        $this->receive($s, $type, $o, 1)->assertOk();
        $this->patch(route('admin.supplier-invoice.cancel', $cancelled->id), ['reason' => 'ordered by mistake'])->assertSessionHasNoErrors();

        $this->put(route('admin.supplier-invoice.update', $o->id), ['items' => [$this->line($product, 9)]])->assertSessionHasErrors('invoice');
        $this->put(route('admin.supplier-invoice.update', $cancelled->id), ['items' => [$this->line($cProduct, 9)]])->assertSessionHasErrors('invoice');

        $this->assertSame('275.00', $this->total($o));
        $this->assertSame('275.00', $this->total($cancelled));
    }

    public function test_an_edit_is_written_to_the_audit_log_with_before_and_after(): void
    {
        [, $o, $product] = $this->order();

        $this->actingAs($this->admin())->put(route('admin.supplier-invoice.update', $o->id), ['items' => [$this->line($product, 2, 100, 0, 0)]])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'supplier_po.updated')->where('subject_id', (string) $o->id)->firstOrFail();
        $this->assertSame($o->invoice_number, $log->subject_label);
        $this->assertSame((string) $this->admin()->admin_id, (string) $log->actor_id);
        $this->assertEquals(['from' => '275.00', 'to' => '200.00'], $log->changes['total']);
        $this->assertCount(1, $log->meta['lines_before']);
        $this->assertCount(1, $log->meta['lines_after']);
    }

    // ================================================================== adjust (after goods arrived)

    public function test_after_goods_arrive_a_line_quantity_can_go_up_or_down_but_never_below_what_arrived(): void
    {
        [$s, $o, , $type] = $this->order();
        $this->receive($s, $type, $o, 2)->assertOk();
        $item = $o->items()->firstOrFail();
        $adjust = fn (int $qty, string $why = 'supplier agreed') => $this->actingAs($this->admin())
            ->patch(route('admin.supplier-invoice.adjust', $o->id), ['item_id' => $item->id, 'order_qty' => $qty, 'reason' => $why]);

        $adjust(1)->assertSessionHasErrors('order_qty');
        $this->assertSame(3, (int) $item->fresh()->order_qty);

        // 3 x 100 - 10% + 5 = 275.00 ; the price, discount and face value stay as they were
        $adjust(2)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(2, (int) $item->fresh()->order_qty);
        $this->assertSame('185.00', $this->total($o));   // 200 - 20 + 5

        $adjust(5)->assertSessionHasNoErrors();
        $this->assertSame('455.00', $this->total($o));   // 500 - 50 + 5
        $this->assertSame('455.00', (string) $item->fresh()->net_amount);
        $this->assertSame(['100.00', '10.00', '5.00'], [(string) $item->fresh()->unit_price, (string) $item->fresh()->discount, (string) $item->fresh()->face_value]);
    }

    public function test_an_adjusted_quantity_is_a_new_limit_for_the_next_delivery(): void
    {
        [$s, $o, , $type] = $this->order();
        $this->receive($s, $type, $o, 3)->assertOk();
        $this->receive($s, $type, $o, 1)->assertStatus(422);

        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.adjust', $o->id), ['item_id' => $o->items()->firstOrFail()->id, 'order_qty' => 4, 'reason' => 'one more box'])
            ->assertSessionHasNoErrors();

        $this->receive($s, $type, $o, 1)->assertOk();
    }

    public function test_the_adjust_form_is_checked_before_anything_changes(): void
    {
        [, $o] = $this->order();
        $item = $o->items()->firstOrFail();
        $good = ['item_id' => $item->id, 'order_qty' => 4, 'reason' => 'valid reason'];

        foreach ([
            ['item_id' => null], ['item_id' => 'abc'], ['item_id' => 0],
            ['order_qty' => 0], ['order_qty' => 1000001], ['order_qty' => 'x'], ['order_qty' => 2.5],
            ['reason' => ''], ['reason' => 'abcd'], ['reason' => str_repeat('r', 201)],
        ] as $bad) {
            $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.adjust', $o->id), array_merge($good, $bad))->assertSessionHasErrors(array_keys($bad));
        }

        $this->assertSame(3, (int) $item->fresh()->order_qty);
        $this->assertSame('275.00', $this->total($o));
    }

    public function test_a_line_of_another_order_cannot_be_adjusted_through_this_one(): void
    {
        [, $o] = $this->order();
        [, $other] = $this->order();
        $otherItem = $other->items()->firstOrFail();

        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.adjust', $o->id), ['item_id' => $otherItem->id, 'order_qty' => 9, 'reason' => 'sneaky change'])
            ->assertSessionHasErrors('item_id');

        $this->assertSame(3, (int) $otherItem->fresh()->order_qty);
    }

    public function test_a_cancelled_order_cannot_be_adjusted(): void
    {
        [, $o] = $this->order();
        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'ordered by mistake'])->assertSessionHasNoErrors();

        $this->patch(route('admin.supplier-invoice.adjust', $o->id), ['item_id' => $o->items()->firstOrFail()->id, 'order_qty' => 9, 'reason' => 'too late now'])
            ->assertSessionHasErrors('invoice');

        $this->assertSame(3, (int) $o->items()->firstOrFail()->order_qty);
    }

    public function test_an_adjustment_is_written_to_the_audit_log_with_the_reason_and_what_had_arrived(): void
    {
        [$s, $o, , $type] = $this->order();
        $this->receive($s, $type, $o, 2)->assertOk();
        $item = $o->items()->firstOrFail();

        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.adjust', $o->id), ['item_id' => $item->id, 'order_qty' => 4, 'reason' => 'supplier sent extra'])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'supplier_po.quantity_adjusted')->where('subject_id', (string) $o->id)->firstOrFail();
        $this->assertEquals(['from' => '3', 'to' => '4'], $log->changes['qty']);
        $this->assertEquals(['from' => '275.00', 'to' => '365.00'], $log->changes['total']);   // 400 - 40 + 5
        $this->assertSame('supplier sent extra', $log->meta['reason']);
        $this->assertSame($item->id, (int) $log->meta['item_id']);
        $this->assertSame(2, (int) $log->meta['received_so_far']);
        $this->assertSame((string) $this->admin()->admin_id, (string) $log->actor_id);
    }

    // ================================================================== cancel

    public function test_an_order_is_cancelled_with_a_reason_and_kept(): void
    {
        [, $o] = $this->order();

        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => '  ordered by mistake  '])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', "Purchase Order {$o->invoice_number} was cancelled.");

        $fresh = $o->fresh();
        $this->assertSame('CANCELLED', $fresh->status);
        $this->assertSame('ordered by mistake', $fresh->cancel_reason);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame((string) $this->admin()->admin_id, (string) $fresh->updated_by);
        $this->assertSame(1, SupplierInvoiceItem::where('supplier_invoice_id', $o->id)->count(), 'lines are kept');
        $this->assertSame('275.00', (string) $fresh->grand_total);
    }

    public function test_a_cancelled_order_cannot_be_cancelled_again_and_the_first_reason_stays(): void
    {
        [, $o] = $this->order();
        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'first reason'])->assertSessionHasNoErrors();

        $this->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'second reason'])->assertSessionHasErrors('invoice');

        $this->assertSame('first reason', $o->fresh()->cancel_reason);
    }

    public function test_the_cancel_reason_must_be_between_3_and_200_letters(): void
    {
        [, $o] = $this->order();

        foreach ([null, '', 'ab', str_repeat('x', 201), ['array']] as $bad) {
            $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => $bad])->assertSessionHasErrors('reason');
        }
        $this->assertSame('ORDERED', $o->fresh()->status);

        $this->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => str_repeat('x', 200)])->assertSessionHasNoErrors();
        $this->assertSame('CANCELLED', $o->fresh()->status);
    }

    public function test_an_order_with_received_goods_cannot_be_cancelled(): void
    {
        [$s, $o, , $type] = $this->order();
        $this->receive($s, $type, $o, 1)->assertOk();

        $this->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'too late to cancel'])->assertSessionHasErrors('invoice');

        $this->assertSame('ORDERED', $o->fresh()->status);
    }

    public function test_a_cancelled_order_cannot_be_received_against_and_is_not_offered_as_open(): void
    {
        [$s, $o, , $type] = $this->order();
        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'ordered by mistake'])->assertSessionHasNoErrors();

        $this->receive($s, $type, $o, 1)->assertStatus(422);

        $this->assertCount(0, app(PurchaseOrders::class)->openOrders($s->id));
    }

    public function test_a_cancelled_order_stays_in_the_history_marked_as_cancelled(): void
    {
        [$s, $o] = $this->order();
        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'ordered by mistake'])->assertSessionHasNoErrors();

        $html = $this->get(route('admin.supplier-invoice', ['supplier_id' => $s->id]))->assertOk()->getContent();

        $this->assertStringContainsString($o->invoice_number, $html);
        $this->assertMatchesRegularExpression('/cancelled/i', $html);
    }

    public function test_a_cancel_is_written_to_the_audit_log(): void
    {
        [, $o] = $this->order();

        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'ordered by mistake'])->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'supplier_po.cancelled')->where('subject_id', (string) $o->id)->firstOrFail();
        $this->assertSame($o->invoice_number, $log->subject_label);
        $this->assertEquals(['from' => 'ORDERED', 'to' => 'CANCELLED'], $log->changes['status']);
        $this->assertSame('ordered by mistake', $log->meta['reason']);
        $this->assertSame((string) $this->admin()->admin_id, (string) $log->actor_id);
    }

    public function test_a_refused_change_leaves_no_audit_entry(): void
    {
        [, $o] = $this->order();
        $before = AuditLog::where('action', 'like', 'supplier_po.%')->count();

        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'ab'])->assertSessionHasErrors('reason');
        $this->patch(route('admin.supplier-invoice.adjust', $o->id), ['item_id' => 1, 'order_qty' => 0, 'reason' => 'valid reason'])->assertSessionHasErrors('order_qty');

        $this->assertSame($before, AuditLog::where('action', 'like', 'supplier_po.%')->count());
    }

    public function test_the_cancel_action_is_limited_to_30_tries_a_minute(): void
    {
        [, $o] = $this->order();
        $this->actingAs($this->admin());

        for ($i = 0; $i < 30; $i++) {
            $this->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => ''])->assertSessionHasErrors('reason');
        }

        $this->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => ''])->assertStatus(429);
        $this->assertSame('ORDERED', $o->fresh()->status);
    }

    // ================================================================== the supplier's own portal download

    private function portalUser(Supplier $supplier): Admin
    {
        return $this->makeAdmin('SUPPLIER', ['supplier_id' => $supplier->id]);
    }

    public function test_a_supplier_can_download_their_own_order_as_a_pdf(): void
    {
        [$s, $o] = $this->order();

        $response = $this->actingAs($this->portalUser($s))->get(route('supplier.orders.download', $o->id))->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString($o->invoice_number . '.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_a_supplier_cannot_download_another_suppliers_order(): void
    {
        [$mine] = $this->order();
        [, $theirs] = $this->order();

        $this->actingAs($this->portalUser($mine))->get(route('supplier.orders.download', $theirs->id))->assertNotFound();
    }

    public function test_a_supplier_login_with_no_supplier_attached_gets_nothing(): void
    {
        [, $o] = $this->order();

        $this->actingAs($this->makeAdmin('SUPPLIER', ['supplier_id' => null]))->get(route('supplier.orders.download', $o->id))->assertNotFound();
    }

    public function test_a_bad_order_id_in_the_supplier_portal_is_a_clean_404(): void
    {
        [$s] = $this->order();
        $this->actingAs($this->portalUser($s));

        foreach (['abc', '999999999', '99999999999999999999'] as $id) {
            $this->get("/admin/supplier/orders/{$id}/download")->assertNotFound();
        }
    }

    public static function notSupplierRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'admin' => ['ADMIN']];
    }

    #[DataProvider('notSupplierRoles')]
    public function test_only_a_supplier_login_can_use_the_portal_download(string $role): void
    {
        [, $o] = $this->order();

        $this->actingAs($this->makeAdmin($role))->get(route('supplier.orders.download', $o->id))->assertForbidden();
    }

    // ================================================================== who may use the admin actions

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_can_edit_adjust_or_cancel_an_order(string $role): void
    {
        [, $o, $product] = $this->order();
        $item = $o->items()->firstOrFail();
        $user = $this->makeAdmin($role);

        $this->actingAs($user)->get(route('admin.supplier-invoice.edit', $o->id))->assertForbidden();
        $this->actingAs($user)->put(route('admin.supplier-invoice.update', $o->id), ['items' => [$this->line($product, 9)]])->assertForbidden();
        $this->actingAs($user)->patch(route('admin.supplier-invoice.adjust', $o->id), ['item_id' => $item->id, 'order_qty' => 9, 'reason' => 'valid reason'])->assertForbidden();
        $this->actingAs($user)->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'valid reason'])->assertForbidden();

        $this->assertSame('ORDERED', $o->fresh()->status);
        $this->assertSame('275.00', $this->total($o));
        $this->assertSame(3, (int) $item->fresh()->order_qty);
    }

    public function test_a_visitor_who_is_not_signed_in_is_sent_to_login(): void
    {
        $this->get('/admin/supplier/invoice/1/edit')->assertRedirect();
        $this->put('/admin/supplier/invoice/1', ['items' => []])->assertRedirect();
        $this->patch('/admin/supplier/invoice/1/adjust', [])->assertRedirect();
        $this->patch('/admin/supplier/invoice/1/cancel', [])->assertRedirect();
        $this->get('/admin/supplier/orders/1/download')->assertRedirect();
    }
}