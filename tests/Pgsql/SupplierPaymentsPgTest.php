<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\Supplier\PurchaseOrders;
use App\Services\Supplier\SupplierPayments;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsSupplierData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * PAYMENTS TO A SUPPLIER, per purchase order, on real PostgreSQL (autotest schema).
 *
 *   POST   admin.suppliers.payments.store   record a payment
 *   DELETE admin.suppliers.payments.void    void a wrong payment (it is kept, never deleted)
 *   GET    admin.supplier.profile.show      where the totals and the payment list are shown
 *
 * Rules in simple words:
 *   - what is owed = the order total minus the live payments; the order is UNPAID, PART or PAID (CANCELLED owes nothing),
 *   - a payment can never take an order past its total, down to the last cent,
 *   - amount, date, method, reference and note are checked on the server before anything is saved,
 *   - an order of ANOTHER supplier, or a cancelled order, cannot be paid,
 *   - a wrong payment is voided with a reason: the record stays, the money goes back to "owed", and it cannot be voided twice,
 *   - an order with live payments cannot be edited, adjusted or cancelled until the payments are voided,
 *   - every payment and every void is written to the audit log,
 *   - only the company ADMIN may record or void.
 *
 * Everything made here is rolled back after each test. One login per test.
 */
class SupplierPaymentsPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsSupplierData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
        config(['supplier_deliveries.enabled' => true]);
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    /** A supplier with one order worth Rs. 2,400 (2 x 1,200). Made through the service, so no login is used up. */
    private function order(?Supplier $supplier = null): array
    {
        $supplier ??= $this->makeSupplier();
        $product = $this->makeDeviceProduct();
        $this->offer($supplier, $product, 1200, 0);
        $order = app(PurchaseOrders::class)->create($supplier->id, [
            ['product_id' => $product->id, 'type' => 'Device', 'order_qty' => 2, 'unit_price' => 1200, 'discount' => 0, 'face_value' => 0],
        ], null);

        return [$supplier, $order, $product];
    }

    private function pay(Supplier $s, SupplierInvoice $o, $amount, array $extra = [])
    {
        return $this->actingAs($this->admin())->post(route('admin.suppliers.payments.store', $s->id), array_merge([
            'supplier_invoice_id' => $o->id, 'amount' => $amount, 'paid_on' => now()->toDateString(), 'method' => 'BANK', 'reference' => 'TRX-1',
        ], $extra));
    }

    private function void(Supplier $s, SupplierPayment $p, string $reason = 'entered twice')
    {
        return $this->actingAs($this->admin())->delete(route('admin.suppliers.payments.void', [$s->id, $p->id]), ['reason' => $reason]);
    }

    private function summary(SupplierInvoice $o): array
    {
        return app(SupplierPayments::class)->summary($o->fresh());
    }

    // ================================================================== recording

    public function test_payments_reduce_what_is_owed_until_the_order_is_paid(): void
    {
        [$s, $o] = $this->order();
        $this->assertSame(['total_cents' => 240000, 'paid_cents' => 0, 'balance_cents' => 240000, 'status' => 'UNPAID'], $this->summary($o));

        $this->pay($s, $o, '1000.50')->assertSessionHasNoErrors()->assertSessionHas('success', 'Payment of Rs. 1,000.50 recorded.');
        $sum = $this->summary($o);
        $this->assertSame([100050, 139950, 'PART'], [$sum['paid_cents'], $sum['balance_cents'], $sum['status']]);

        $this->pay($s, $o, '1399.50')->assertSessionHasNoErrors();
        $this->assertSame('PAID', $this->summary($o)['status']);
        $this->assertSame(0, $this->summary($o)['balance_cents']);
        $this->assertSame(2, SupplierPayment::where('supplier_id', $s->id)->count());
    }

    public function test_a_payment_is_saved_with_all_its_details_and_who_recorded_it(): void
    {
        [$s, $o] = $this->order();

        $this->pay($s, $o, '250', ['method' => 'CHEQUE', 'reference' => '  CHQ-77  ', 'note' => 'First part', 'paid_on' => now()->subDays(3)->toDateString()])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.supplier.profile.show', $s->id));

        $p = SupplierPayment::where('supplier_id', $s->id)->firstOrFail();
        $this->assertSame($o->id, (int) $p->supplier_invoice_id);
        $this->assertSame('250.00', (string) $p->amount);
        $this->assertSame('CHEQUE', $p->method);
        $this->assertSame('CHQ-77', $p->reference, 'spaces around the reference are trimmed');
        $this->assertSame('First part', $p->note);
        $this->assertSame(now()->subDays(3)->toDateString(), $p->paid_on->toDateString());
        $this->assertSame((string) $this->admin()->admin_id, (string) $p->recorded_by);
        $this->assertNull($p->voided_at);
    }

    public function test_reference_and_note_are_optional_and_an_empty_one_is_stored_as_nothing(): void
    {
        [$s, $o] = $this->order();

        $this->pay($s, $o, '10', ['reference' => '   ', 'note' => ''])->assertSessionHasNoErrors();

        $p = SupplierPayment::where('supplier_id', $s->id)->firstOrFail();
        $this->assertNull($p->reference);
        $this->assertNull($p->note);
    }

    public function test_every_payment_method_is_accepted(): void
    {
        [$s, $o] = $this->order();

        foreach (array_keys(SupplierPayments::METHODS) as $method) {
            $this->pay($s, $o, '1', ['method' => $method])->assertSessionHasNoErrors();
        }

        $this->assertEqualsCanonicalizing(['CASH', 'BANK', 'CHEQUE', 'OTHER'], SupplierPayment::where('supplier_id', $s->id)->pluck('method')->all());
    }

    public function test_cents_add_up_exactly_with_no_rounding_drift(): void
    {
        [$s, $o] = $this->order();

        foreach (['0.10', '0.20', '0.01', '0.07'] as $a) {
            $this->pay($s, $o, $a)->assertSessionHasNoErrors();
        }

        $this->assertSame(38, $this->summary($o)['paid_cents']);
        $this->assertSame(240000 - 38, $this->summary($o)['balance_cents']);
    }

    public function test_the_payment_date_may_be_today_or_any_past_day(): void
    {
        [$s, $o] = $this->order();

        $this->pay($s, $o, '1', ['paid_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->pay($s, $o, '1', ['paid_on' => '2020-01-31'])->assertSessionHasNoErrors();

        $this->assertSame(2, SupplierPayment::where('supplier_id', $s->id)->count());
    }

    // ================================================================== never past the total

    public function test_a_payment_can_never_take_an_order_past_its_total(): void
    {
        [$s, $o] = $this->order();

        $this->pay($s, $o, '2400.01')->assertSessionHasErrors('amount');
        $this->pay($s, $o, '2400.00')->assertSessionHasNoErrors();
        $this->pay($s, $o, '0.01')->assertSessionHasErrors('amount');

        $this->assertSame(1, SupplierPayment::where('supplier_id', $s->id)->count());
    }

    public function test_the_error_for_too_much_says_how_much_is_still_owed(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '2000')->assertSessionHasNoErrors();

        $errors = $this->pay($s, $o, '500')->assertSessionHasErrors('amount')->baseResponse->getSession()->get('errors')->get('amount');

        $this->assertStringContainsString('Only Rs. 400.00 is still owed', $errors[0]);
        $this->assertStringContainsString($o->invoice_number, $errors[0]);
    }

    public function test_a_fully_paid_order_says_so_when_paid_again(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '2400')->assertSessionHasNoErrors();

        $errors = $this->pay($s, $o, '1')->assertSessionHasErrors('amount')->baseResponse->getSession()->get('errors')->get('amount');

        $this->assertStringContainsString('already fully paid', $errors[0]);
    }

    // ================================================================== bad input

    public static function badAmounts(): array
    {
        return [
            'text'              => ['abc'],
            'zero'              => ['0'],
            'zero with cents'   => ['0.00'],
            'negative'          => ['-5'],
            'three decimals'    => ['10.999'],
            'huge'              => ['99999999999999'],
            'empty'             => [''],
            'scientific'        => ['1e3'],
            'comma decimal'     => ['10,50'],
            'plus sign'         => ['+10'],
            'sql in the amount' => ["1; DROP TABLE supplier_payments;--"],
        ];
    }

    #[DataProvider('badAmounts')]
    public function test_a_bad_amount_is_refused_before_the_database(string $bad): void
    {
        [$s, $o] = $this->order();

        $this->pay($s, $o, $bad)->assertSessionHasErrors('amount');

        $this->assertSame(0, SupplierPayment::where('supplier_id', $s->id)->count());
    }

    public static function badDates(): array
    {
        return [
            'tomorrow'      => [fn () => now()->addDay()->toDateString()],
            'next year'     => [fn () => now()->addYear()->toDateString()],
            'a word'        => [fn () => 'yesterday'],
            'wrong format'  => [fn () => now()->format('d/m/Y')],
            'not a date'    => [fn () => '2026-13-45'],
            'empty'         => [fn () => ''],
        ];
    }

    #[DataProvider('badDates')]
    public function test_a_bad_payment_date_is_refused(\Closure $date): void
    {
        [$s, $o] = $this->order();

        $this->pay($s, $o, '10', ['paid_on' => $date()])->assertSessionHasErrors('paid_on');

        $this->assertSame(0, SupplierPayment::where('supplier_id', $s->id)->count());
    }

    public function test_a_missing_or_unknown_method_is_refused(): void
    {
        [$s, $o] = $this->order();

        foreach (['BITCOIN', 'bank', '', 'CASH; DROP'] as $bad) {
            $this->pay($s, $o, '10', ['method' => $bad])->assertSessionHasErrors('method');
        }

        $this->assertSame(0, SupplierPayment::where('supplier_id', $s->id)->count());
    }

    public function test_reference_and_note_have_length_limits(): void
    {
        [$s, $o] = $this->order();

        $this->pay($s, $o, '1', ['reference' => str_repeat('R', 101)])->assertSessionHasErrors('reference');
        $this->pay($s, $o, '1', ['note' => str_repeat('N', 501)])->assertSessionHasErrors('note');
        $this->assertSame(0, SupplierPayment::where('supplier_id', $s->id)->count());

        $this->pay($s, $o, '1', ['reference' => str_repeat('R', 100), 'note' => str_repeat('N', 500)])->assertSessionHasNoErrors();
        $this->assertSame(1, SupplierPayment::where('supplier_id', $s->id)->count());
    }

    public function test_the_order_must_be_chosen_and_must_be_a_plain_id(): void
    {
        [$s] = $this->order();

        foreach ([null, '', 'abc', '0', '-1', '99999999999999999999', '1 OR 1=1'] as $bad) {
            $this->actingAs($this->admin())->post(route('admin.suppliers.payments.store', $s->id), [
                'supplier_invoice_id' => $bad, 'amount' => '10', 'paid_on' => now()->toDateString(), 'method' => 'CASH',
            ])->assertSessionHasErrors('supplier_invoice_id');
        }

        $this->assertSame(0, SupplierPayment::where('supplier_id', $s->id)->count());
    }

    public function test_a_supplier_that_does_not_exist_or_a_text_id_gives_not_found(): void
    {
        [, $o] = $this->order();

        $this->actingAs($this->admin())->post(route('admin.suppliers.payments.store', 999999999), [
            'supplier_invoice_id' => $o->id, 'amount' => '10', 'paid_on' => now()->toDateString(), 'method' => 'CASH',
        ])->assertNotFound();
        $this->post('/admin/suppliers/abc/payments', ['amount' => '1'])->assertNotFound();
    }

    // ================================================================== other supplier / cancelled

    public function test_an_order_of_another_supplier_or_a_cancelled_one_cannot_be_paid(): void
    {
        [$s, $o] = $this->order();
        [$other] = $this->order();

        $this->pay($other, $o, '10')->assertSessionHasErrors('supplier_invoice_id');

        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'ordered by mistake'])->assertSessionHasNoErrors();
        $this->pay($s, $o, '10')->assertSessionHasErrors('supplier_invoice_id');

        $this->assertSame(0, SupplierPayment::whereIn('supplier_id', [$s->id, $other->id])->count());
    }

    public function test_a_cancelled_order_owes_nothing_and_is_not_counted_as_owed(): void
    {
        [$s, $o] = $this->order();
        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'ordered by mistake'])->assertSessionHasNoErrors();

        $this->assertSame(['total_cents' => 240000, 'paid_cents' => 0, 'balance_cents' => 0, 'status' => 'CANCELLED'], $this->summary($o));

        $totals = app(SupplierPayments::class)->forOrders(SupplierInvoice::where('supplier_id', $s->id)->get());
        $this->assertSame(0, $totals['owed_cents']);
        $this->assertSame(0, $totals['paid_cents']);
    }

    // ================================================================== voiding

    public function test_voiding_keeps_the_record_and_gives_the_balance_back(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '500')->assertSessionHasNoErrors();
        $payment = SupplierPayment::where('supplier_id', $s->id)->firstOrFail();

        $this->void($s, $payment, 'x')->assertSessionHasErrors('reason');
        $this->void($s, $payment, 'entered twice')->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame(1, SupplierPayment::where('supplier_id', $s->id)->count(), 'voided, not deleted');
        $fresh = $payment->fresh();
        $this->assertSame('entered twice', $fresh->void_reason);
        $this->assertNotNull($fresh->voided_at);
        $this->assertSame((string) $this->admin()->admin_id, (string) $fresh->voided_by);
        $this->assertSame(0, $this->summary($o)['paid_cents']);
        $this->assertSame('UNPAID', $this->summary($o)['status']);
    }

    public function test_a_voided_payment_cannot_be_voided_again(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '500')->assertSessionHasNoErrors();
        $payment = SupplierPayment::where('supplier_id', $s->id)->firstOrFail();
        $this->void($s, $payment)->assertSessionHasNoErrors();

        $this->void($s, $payment, 'again please')->assertSessionHasErrors('payment');

        $this->assertSame('entered twice', $payment->fresh()->void_reason, 'the first reason is not overwritten');
    }

    public function test_the_void_reason_must_be_between_3_and_200_letters(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '500')->assertSessionHasNoErrors();
        $payment = SupplierPayment::where('supplier_id', $s->id)->firstOrFail();

        foreach (['', 'ab', str_repeat('x', 201)] as $bad) {
            $this->void($s, $payment, $bad)->assertSessionHasErrors('reason');
        }
        $this->assertNull($payment->fresh()->voided_at);

        $this->void($s, $payment, str_repeat('x', 200))->assertSessionHasNoErrors();
        $this->assertNotNull($payment->fresh()->voided_at);
    }

    public function test_after_a_void_the_same_money_can_be_paid_again(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '2400')->assertSessionHasNoErrors();
        $this->pay($s, $o, '1')->assertSessionHasErrors('amount');

        $this->void($s, SupplierPayment::where('supplier_id', $s->id)->firstOrFail());
        $this->pay($s, $o, '2400')->assertSessionHasNoErrors();

        $this->assertSame('PAID', $this->summary($o)['status']);
        $this->assertSame(2, SupplierPayment::where('supplier_id', $s->id)->count());
    }

    public function test_a_payment_of_another_supplier_cannot_be_voided_through_this_one(): void
    {
        [$s, $o] = $this->order();
        [$other] = $this->order();
        $this->pay($s, $o, '100')->assertSessionHasNoErrors();
        $payment = SupplierPayment::where('supplier_id', $s->id)->firstOrFail();

        $this->actingAs($this->admin())->delete(route('admin.suppliers.payments.void', [$other->id, $payment->id]), ['reason' => 'wrong supplier'])->assertNotFound();

        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_a_payment_that_does_not_exist_gives_not_found(): void
    {
        [$s] = $this->order();

        $this->actingAs($this->admin())->delete(route('admin.suppliers.payments.void', [$s->id, 999999999]), ['reason' => 'nothing here'])->assertNotFound();
    }

    // ================================================================== locks on the order

    public function test_an_order_with_live_payments_cannot_be_edited_adjusted_or_cancelled_until_they_are_voided(): void
    {
        [$s, $o, $product] = $this->order();
        $this->pay($s, $o, '100')->assertSessionHasNoErrors();
        $item = $o->items()->firstOrFail();

        $this->actingAs($this->admin())->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'cancel it please'])->assertSessionHasErrors();
        $this->patch(route('admin.supplier-invoice.adjust', $o->id), ['item_id' => $item->id, 'order_qty' => 5, 'reason' => 'bigger order'])->assertSessionHasErrors('invoice');
        $this->put(route('admin.supplier-invoice.update', $o->id), ['items' => [
            ['product_id' => $product->id, 'type' => 'Device', 'order_qty' => 9, 'unit_price' => 1200, 'discount' => 0, 'face_value' => 0],
        ]])->assertSessionHasErrors('invoice');
        $this->get(route('admin.supplier-invoice.edit', $o->id))->assertRedirect()->assertSessionHas('error');
        $this->assertSame('ORDERED', $o->fresh()->status);
        $this->assertSame(2, (int) $item->fresh()->order_qty);

        $this->void($s, SupplierPayment::where('supplier_id', $s->id)->firstOrFail(), 'wrong order');
        $this->patch(route('admin.supplier-invoice.cancel', $o->id), ['reason' => 'cancel it please'])->assertSessionHasNoErrors();
        $this->assertSame('CANCELLED', $o->fresh()->status);
    }

    // ================================================================== the supplier profile page

    public function test_the_profile_page_shows_what_is_owed_what_is_paid_and_each_payment(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '1000.50', ['reference' => 'TRX-PROFILE'])->assertSessionHasNoErrors();

        $html = $this->get(route('admin.supplier.profile.show', $s->id))->assertOk()->getContent();

        $this->assertStringContainsString('Still Owed to Supplier', $html);
        $this->assertStringContainsString('Rs. 1,399.50', $html, 'the balance still owed');
        $this->assertStringContainsString('Paid so far: Rs. 1,000.50', $html);
        $this->assertStringContainsString('TRX-PROFILE', $html);
        $this->assertStringContainsString($o->invoice_number, $html);
    }

    public function test_a_voided_payment_is_shown_struck_out_with_its_reason_and_not_counted(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '700', ['reference' => 'TRX-VOIDED'])->assertSessionHasNoErrors();
        $this->void($s, SupplierPayment::where('supplier_id', $s->id)->firstOrFail(), 'bank bounced it');

        $html = $this->get(route('admin.supplier.profile.show', $s->id))->assertOk()->getContent();

        $this->assertStringContainsString('TRX-VOIDED', $html);
        $this->assertStringContainsString('Voided', $html);
        $this->assertStringContainsString('bank bounced it', $html);
        $this->assertStringContainsString('Paid so far: Rs. 0.00', $html);
    }

    public function test_text_typed_in_a_reference_or_note_is_shown_as_text_never_run(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '5', ['reference' => '<script>alert(1)</script>', 'note' => '<img src=x onerror=alert(2)>'])->assertSessionHasNoErrors();

        $html = $this->get(route('admin.supplier.profile.show', $s->id))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_the_totals_of_one_supplier_never_include_another_suppliers_payments(): void
    {
        [$s, $o] = $this->order();
        [$other, $otherOrder] = $this->order();
        $this->pay($s, $o, '100')->assertSessionHasNoErrors();
        $this->pay($other, $otherOrder, '2000')->assertSessionHasNoErrors();

        $totals = app(SupplierPayments::class)->forOrders(SupplierInvoice::where('supplier_id', $s->id)->get());

        $this->assertSame(10000, $totals['paid_cents']);
        $this->assertSame(240000 - 10000, $totals['owed_cents']);
        $this->assertArrayNotHasKey($otherOrder->id, $totals['orders']);
    }

    // ================================================================== audit log

    public function test_recording_and_voiding_are_written_to_the_audit_log(): void
    {
        [$s, $o] = $this->order();
        $this->pay($s, $o, '321.45', ['method' => 'CASH', 'reference' => 'AUD-1'])->assertSessionHasNoErrors();
        $payment = SupplierPayment::where('supplier_id', $s->id)->firstOrFail();
        $this->void($s, $payment, 'audit reason');

        $recorded = AuditLog::where('action', 'supplier_payment.recorded')->where('subject_id', (string) $payment->id)->firstOrFail();
        $this->assertSame('PO #' . $o->id, $recorded->subject_label);
        $this->assertSame((string) $this->admin()->admin_id, (string) $recorded->actor_id);
        $meta = $recorded->meta;
        $this->assertSame('321.45', (string) $meta['amount']);
        $this->assertSame('CASH', $meta['method']);
        $this->assertSame('AUD-1', $meta['reference']);
        $this->assertSame($s->id, (int) $meta['supplier_id']);

        $voided = AuditLog::where('action', 'supplier_payment.voided')->where('subject_id', (string) $payment->id)->firstOrFail();
        $this->assertSame('audit reason', $voided->meta['reason']);
        $this->assertSame('321.45', (string) $voided->meta['amount']);
    }

    public function test_a_refused_payment_leaves_no_audit_entry(): void
    {
        [$s, $o] = $this->order();
        $before = AuditLog::where('action', 'supplier_payment.recorded')->count();

        $this->pay($s, $o, '99999')->assertSessionHasErrors('amount');

        $this->assertSame($before, AuditLog::where('action', 'supplier_payment.recorded')->count());
    }

    // ================================================================== who may use it

    public static function otherRoles(): array
    {
        return ['dealer' => ['DEALER'], 'distributor' => ['DISTRIBUTOR'], 'finance' => ['FINANCE'], 'technician' => ['TECHNICIAN'], 'supplier' => ['SUPPLIER']];
    }

    #[DataProvider('otherRoles')]
    public function test_only_the_company_admin_can_record_or_void_a_payment(string $role): void
    {
        [$s, $o] = $this->order();
        $payment = app(SupplierPayments::class)->record($o->id, '100', now()->toDateString(), 'BANK', null, null, null);

        $user = $this->makeAdmin($role);

        $this->actingAs($user)->post(route('admin.suppliers.payments.store', $s->id), [
            'supplier_invoice_id' => $o->id, 'amount' => '100', 'paid_on' => now()->toDateString(), 'method' => 'BANK',
        ])->assertForbidden();
        $this->actingAs($user)->delete(route('admin.suppliers.payments.void', [$s->id, $payment->id]), ['reason' => 'nope nope'])->assertForbidden();

        $this->assertSame(1, SupplierPayment::where('supplier_id', $s->id)->count());
        $this->assertNull($payment->fresh()->voided_at);
    }

    public function test_a_visitor_who_is_not_signed_in_cannot_record_or_void(): void
    {
        $this->post(route('admin.suppliers.payments.store', 1), ['amount' => '1'])->assertRedirect();
        $this->delete(route('admin.suppliers.payments.void', [1, 1]), ['reason' => 'nope nope'])->assertRedirect();
    }
}