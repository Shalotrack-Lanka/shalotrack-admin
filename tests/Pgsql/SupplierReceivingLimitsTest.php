<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Sim;
use App\Models\Stock;
use App\Models\SupplierDelivery;
use App\Models\SupplierInvoice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * RECEIVING GOODS from a supplier, on real PostgreSQL.
 *
 * Rules in simple words:
 *   - Goods arrive AGAINST A PURCHASE ORDER, and the order is the limit: received can never pass ordered. A delivery
 *     that is too big is refused whole (nothing saved). A cancelled delivery gives its quantity back to the order.
 *   - A SIM delivery (ICCID + 10-digit SIM number + 15-digit IMSI + SIM type) puts every SIM into the SIM list as
 *     "Not Activated". Only a supplier that supplies SIM cards (a product with SIM in its name) can deliver them.
 *   - Stock on Manage Stock comes only from a received delivery: typing a quantity and the Excel import are closed,
 *     and the stock added is exactly the number of scanned units.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class SupplierReceivingLimitsTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsSupplierData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
        // The suite pins the delivery feature off; these tests are about it, so switch it on for the test.
        config(['supplier_deliveries.enabled' => true, 'supplier_deliveries.allow_typed_stock' => false, 'supplier_deliveries.require_purchase_order' => true]);
        $this->actingAs($this->makeAdmin('ADMIN'));
    }

    private function order(\App\Models\Supplier $supplier, \App\Models\Product $product, int $qty, string $type): SupplierInvoice
    {
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $supplier->id, 'items' => [
            ['product_id' => $product->id, 'type' => $type, 'order_qty' => $qty, 'unit_price' => 100, 'discount' => 0, 'face_value' => 0],
        ]])->assertSessionHasNoErrors();

        return SupplierInvoice::where('supplier_id', $supplier->id)->latest('id')->firstOrFail();
    }

    private function receiveDevices(\App\Models\Supplier $supplier, \App\Models\DeviceType $type, ?SupplierInvoice $order, array $imeis)
    {
        return $this->postJson(route('admin.suppliers.deliveries.store', $supplier->id), array_filter([
            'kind' => 'DEVICE', 'device_type_id' => $type->id, 'purchase_order_id' => $order?->id, 'scans' => $imeis,
        ], fn ($v) => $v !== null));
    }

    public function test_the_order_is_a_hard_limit_and_a_cancelled_delivery_gives_the_quantity_back(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $this->supplies($supplier, $type);
        $product = \App\Models\Product::where('device_type_id', $type->id)->firstOrFail();
        $order = $this->order($supplier, $product, 1, 'Device');
        [$a, $b] = [$this->validImei(), $this->validImei()];

        $this->receiveDevices($supplier, $type, null, [$a])->assertStatus(422)->assertJsonValidationErrors('purchase_order_id');
        $this->receiveDevices($supplier, $type, $order, [$a, $b])->assertStatus(422)->assertJsonValidationErrors('scans');
        $this->assertSame(0, SupplierDelivery::where('supplier_id', $supplier->id)->count());

        $id = $this->receiveDevices($supplier, $type, $order, [$a])->assertOk()->assertJsonPath('accepted', 1)->json('delivery_id');
        $this->receiveDevices($supplier, $type, $order, [$b])->assertStatus(422)->assertJsonValidationErrors('scans');

        $this->delete(route('admin.suppliers.deliveries.void', [$supplier->id, $id]), ['reason' => 'wrong box']);
        $this->receiveDevices($supplier, $type, $order, [$b])->assertOk()->assertJsonPath('accepted', 1);
    }

    public function test_stock_is_added_only_from_a_delivery_and_equals_the_scanned_units(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $this->supplies($supplier, $type);
        $order = $this->order($supplier, \App\Models\Product::where('device_type_id', $type->id)->firstOrFail(), 2, 'Device');

        $this->post(route('admin.stock.store'), ['supplier_id' => $supplier->id, 'device_type_id' => $type->id, 'stock_in' => 5])
            ->assertSessionHasErrors('delivery_id');

        $id = $this->receiveDevices($supplier, $type, $order, [$this->validImei(), $this->validImei()])->assertOk()->json('delivery_id');
        $this->post(route('admin.stock.store'), ['supplier_id' => $supplier->id, 'device_type_id' => $type->id, 'delivery_id' => $id, 'stock_in' => 99])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, (int) Stock::where('device_type_id', $type->id)->value('company_available_stock'));
    }

    public function test_a_sim_delivery_fills_the_sim_list_and_needs_a_supplier_that_supplies_sims(): void
    {
        $supplier = $this->makeSupplier();
        $sim = $this->makePlainProduct('Autotest Mobitel SIM card');
        $this->offer($supplier, $sim);
        $order = $this->order($supplier, $sim, 1, 'SIM');
        $identity = $this->simIdentity();

        $this->postJson(route('admin.suppliers.deliveries.store', $supplier->id), [
            'kind' => 'SIM', 'sim_type' => 'Mobitel', 'purchase_order_id' => $order->id,
            'scans' => [['iccid' => $identity['iccid'], 'sim_number' => $identity['sim_number'], 'imsi' => $identity['imsi']]],
        ])->assertOk()->assertJsonPath('accepted', 1);

        $row = Sim::where('iccid', $identity['iccid'])->firstOrFail();
        $this->assertSame('Not Activated', $row->sim_status);
        $this->assertSame('mobitel', $row->sim_type);

        $devicesOnly = $this->makeSupplier();
        $this->postJson(route('admin.suppliers.deliveries.store', $devicesOnly->id), [
            'kind' => 'SIM', 'sim_type' => 'Mobitel', 'scans' => [['iccid' => $identity['iccid'], 'sim_number' => $identity['sim_number'], 'imsi' => $identity['imsi']]],
        ])->assertStatus(422)->assertJsonValidationErrors('kind');
    }

    public function test_payments_are_capped_by_the_order_and_voided_not_deleted_and_audited(): void
    {
        $supplier = $this->makeSupplier();
        $type = $this->makeDeviceType();
        $this->supplies($supplier, $type);
        $order = $this->order($supplier, \App\Models\Product::where('device_type_id', $type->id)->firstOrFail(), 2, 'Device');   // 2 x 100 = 200
        $pay = fn ($amount) => $this->post(route('admin.suppliers.payments.store', $supplier->id), [
            'supplier_invoice_id' => $order->id, 'amount' => $amount, 'paid_on' => now()->toDateString(), 'method' => 'BANK', 'reference' => 'AUTOTEST',
        ]);

        $pay('200.01')->assertSessionHasErrors('amount');
        $pay('abc')->assertSessionHasErrors('amount');
        $pay('120.00')->assertSessionHasNoErrors();
        $pay('80.01')->assertSessionHasErrors('amount');
        $pay('80.00')->assertSessionHasNoErrors();
        $this->assertSame('PAID', app(\App\Services\Supplier\SupplierPayments::class)->summary($order->fresh())['status']);

        $payment = \App\Models\SupplierPayment::where('supplier_invoice_id', $order->id)->orderBy('id')->firstOrFail();
        $this->delete(route('admin.suppliers.payments.void', [$supplier->id, $payment->id]), ['reason' => 'entered twice'])->assertSessionHasNoErrors();
        $this->assertSame(2, \App\Models\SupplierPayment::where('supplier_invoice_id', $order->id)->count());
        $this->assertNotNull($payment->fresh()->voided_at);

        $actions = \App\Models\AuditLog::whereIn('action', ['supplier_payment.recorded', 'supplier_payment.voided'])->pluck('action')->all();
        $this->assertContains('supplier_payment.recorded', $actions);
        $this->assertContains('supplier_payment.voided', $actions);
    }
}
