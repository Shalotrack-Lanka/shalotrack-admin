<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DeviceType;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\Supplier\SupplierPayments;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PurchasingSchema;
use Tests\TestCase;

/**
 * What has been paid to a supplier, per purchase order: the last step of order -> delivery -> stock -> payment.
 */
class SupplierPaymentsTest extends TestCase
{
    use PurchasingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        config(['supplier_deliveries.enabled' => true]);
        $this->buildPurchasingSchema();
        Admin::forceCreate(['admin_id' => 'adm-1', 'username' => 'admin', 'full_name' => 'Admin', 'role' => 'ADMIN', 'status' => 'ACTIVE']);
        $this->actingAs(Admin::find('adm-1'));
    }

    /** A supplier with one order worth Rs. 2,400 (2 x 1,200). */
    private function order(string $name = 'Suwen test'): array
    {
        $s = Supplier::create(['name' => $name, 'status' => 'Active']);
        $t = DeviceType::create(['device_category' => 'Shalotrack', 'model' => 'V5']);
        $p = Product::create(['product_name' => 'Shalotrack V5', 'device_type_id' => $t->id]);
        $s->products()->attach($p->id, ['price' => 1200, 'qty' => 0]);
        $this->post(route('admin.supplier-invoice.store'), ['supplier_id' => $s->id, 'items' => [
            ['product_id' => $p->id, 'type' => 'Device', 'order_qty' => 2, 'unit_price' => 1200, 'discount' => 0, 'face_value' => 0],
        ]])->assertSessionHasNoErrors();

        return [$s, SupplierInvoice::where('supplier_id', $s->id)->firstOrFail()];
    }

    private function pay(Supplier $s, SupplierInvoice $o, $amount, array $extra = [])
    {
        return $this->post(route('admin.suppliers.payments.store', $s->id), array_merge([
            'supplier_invoice_id' => $o->id, 'amount' => $amount, 'paid_on' => now()->toDateString(), 'method' => 'BANK', 'reference' => 'TRX-1',
        ], $extra));
    }

    private function summary(SupplierInvoice $o): array
    {
        return app(SupplierPayments::class)->summary($o->fresh());
    }

    public function test_payments_reduce_what_is_owed_until_the_order_is_paid(): void
    {
        [$s, $o] = $this->order();
        $this->assertSame(['total_cents' => 240000, 'paid_cents' => 0, 'balance_cents' => 240000, 'status' => 'UNPAID'], $this->summary($o));

        $this->pay($s, $o, '1000.50')->assertSessionHasNoErrors()->assertSessionHas('success');
        $sum = $this->summary($o);
        $this->assertSame([100050, 139950, 'PART'], [$sum['paid_cents'], $sum['balance_cents'], $sum['status']]);

        $this->pay($s, $o, '1399.50')->assertSessionHasNoErrors();
        $this->assertSame('PAID', $this->summary($o)['status']);
        $this->assertSame(2, SupplierPayment::count());

        $this->get(route('admin.supplier.profile.show', $s->id))->assertOk()->assertSee('Still Owed to Supplier')->assertSee('TRX-1');
    }

    public function test_a_payment_can_never_take_an_order_past_its_total(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '2400.01')->assertSessionHasErrors('amount');
        $this->pay($s, $o, '2400.00')->assertSessionHasNoErrors();
        $this->pay($s, $o, '0.01')->assertSessionHasErrors('amount');
        $this->assertSame(1, SupplierPayment::count());
    }

    public function test_bad_amounts_dates_and_methods_are_refused_before_the_database(): void
    {
        [$s, $o] = $this->order();
        foreach (['abc', '0', '-5', '10.999', '99999999999999', ''] as $bad) {
            $this->pay($s, $o, $bad)->assertSessionHasErrors('amount');
        }
        $this->pay($s, $o, '10', ['paid_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('paid_on');
        $this->pay($s, $o, '10', ['paid_on' => 'yesterday'])->assertSessionHasErrors('paid_on');
        $this->pay($s, $o, '10', ['method' => 'BITCOIN'])->assertSessionHasErrors('method');
        $this->assertSame(0, SupplierPayment::count());
    }

    public function test_an_order_of_another_supplier_or_a_cancelled_one_cannot_be_paid(): void
    {
        [$s, $o] = $this->order();
        [$other] = $this->order('Other supplier');

        $this->pay($other, $o, '10')->assertSessionHasErrors('supplier_invoice_id');

        $this->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'ordered by mistake'])->assertSessionHasNoErrors();
        $this->pay($s, $o, '10')->assertSessionHasErrors('supplier_invoice_id');
        $this->assertSame(0, SupplierPayment::count());
    }

    public function test_voiding_keeps_the_record_and_gives_the_balance_back(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '500')->assertSessionHasNoErrors();
        $payment = SupplierPayment::firstOrFail();

        $this->delete(route('admin.suppliers.payments.void', [$s->id, $payment->id]), ['reason' => 'x'])->assertSessionHasErrors('reason');
        $this->delete(route('admin.suppliers.payments.void', [$s->id, $payment->id]), ['reason' => 'entered twice'])->assertSessionHasNoErrors();

        $this->assertSame(1, SupplierPayment::count(), 'voided, not deleted');
        $this->assertSame('entered twice', $payment->fresh()->void_reason);
        $this->assertSame(0, $this->summary($o)['paid_cents']);

        // A voided payment cannot be voided again.
        $this->delete(route('admin.suppliers.payments.void', [$s->id, $payment->id]), ['reason' => 'again'])->assertSessionHasErrors('payment');
    }

    public function test_an_order_with_live_payments_cannot_be_edited_or_cancelled_until_they_are_voided(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '100')->assertSessionHasNoErrors();

        $this->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'cancel it please'])->assertSessionHasErrors();
        $this->assertSame('ORDERED', $o->fresh()->status);

        $this->delete(route('admin.suppliers.payments.void', [$s->id, SupplierPayment::firstOrFail()->id]), ['reason' => 'wrong order']);
        $this->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'cancel it please'])->assertSessionHasNoErrors();
        $this->assertSame('CANCELLED', $o->fresh()->status);
    }

    public function test_only_admins_can_record_or_void_payments(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '100')->assertSessionHasNoErrors();
        $payment = SupplierPayment::firstOrFail();

        Admin::forceCreate(['admin_id' => 'dl-1', 'username' => 'dealer', 'full_name' => 'Dealer', 'role' => 'DEALER', 'status' => 'ACTIVE']);
        $this->actingAs(Admin::find('dl-1'));

        $this->pay($s, $o, '100')->assertForbidden();
        $this->delete(route('admin.suppliers.payments.void', [$s->id, $payment->id]), ['reason' => 'nope nope'])->assertForbidden();
        $this->assertSame(1, SupplierPayment::count());
        $this->assertNull($payment->fresh()->voided_at);
    }
}
