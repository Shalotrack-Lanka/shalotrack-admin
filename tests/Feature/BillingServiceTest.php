<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PaymentReceipt;
use App\Models\Dealer;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingSettings;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Concerns\BillingTestSchema;
use Tests\TestCase;

/** Step 3 core: numbering, invoices, credit notes, receipts, balances, hash chain. */
class BillingServiceTest extends TestCase
{
    use BillingTestSchema;

    private Billing $billing;
    private int $dist;
    private int $dealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildBillingSchema();
        $this->seedCompany();
        $this->billing = app(Billing::class);
        $this->dist = $this->makeDealer('Dist Alpha', 'distributor');
        $this->dealer = $this->makeDealer('Alpha One', 'retailer', $this->dist);
        $this->actingAs($this->admin('maker'));
    }

    private function switchTo($admin): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($admin);
    }

    private function buyer(?int $id = null): Dealer
    {
        return Dealer::findOrFail($id ?? $this->dealer);
    }

    private function invoice(int $qty = 2, string $price = '1000.00', ?int $buyer = null): Invoice
    {
        $ledger = $this->makeLedger($buyer ?? $this->dealer, 'GT06', $qty);

        return $this->billing->issueInvoice($this->buyer($buyer), [$ledger->id => $price]);
    }

    public function test_money_is_exact_in_cents(): void
    {
        $this->assertSame(30, Money::toCents('0.1') + Money::toCents('0.2'));
        $this->assertSame('1250.50', Money::fromCents(Money::toCents('1,250.5')));
        $this->assertSame(1000, Money::percentOf(12500, '8.00'));
        foreach (['-1', '1.234', 'abc', '', '1e5'] as $bad) {
            try {
                Money::toCents($bad);
                $this->fail("accepted $bad");
            } catch (\InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_numbers_are_gapless_per_type_and_year_format(): void
    {
        $a = $this->invoice();
        $b = $this->invoice();
        $year = now('Asia/Colombo')->format('Y');
        $this->assertSame("SL-INV-$year-000001", $a->number);
        $this->assertSame("SL-INV-$year-000002", $b->number);

        $r = $this->billing->recordReceipt($this->buyer(), '100.00', 'cash', null, now('Asia/Colombo')->toDateString());
        $this->assertSame("SL-RCT-$year-000001", $r->number);
    }

    public function test_a_failed_issue_does_not_burn_a_number(): void
    {
        $this->invoice();
        $other = $this->makeLedger($this->makeDealer('Someone else'), 'GT06', 1);
        try {
            $this->billing->issueInvoice($this->buyer(), [$other->id => '10.00']);   // not this buyer's transfer
        } catch (DomainException) {
        }
        $this->assertStringEndsWith('000002', $this->invoice()->number);
    }

    public function test_invoice_totals_snapshot_and_audit(): void
    {
        $inv = $this->invoice(3, '1250.50');
        $this->assertSame('3751.50', $inv->total);
        $this->assertSame(['LKR', 3751.5], [$inv->currency, (float) $inv->subtotal]);
        $this->assertSame('Alpha One', $inv->snapshot['buyer']['legal_name']);
        $this->assertCount(3, $inv->snapshot['lines'][0]['devices']);
        $this->assertSame(1, InvoiceLine::where('invoice_id', $inv->id)->count());
        $this->assertSame(1, AuditLog::where('action', 'invoice.issued')->where('subject_label', $inv->number)->count());
        $this->assertTrue($inv->due_date->equalTo($inv->issue_date->clone()->addDays(30)));
    }

    public function test_tax_off_by_default_and_frozen_when_on(): void
    {
        $inv = $this->invoice(1, '1000.00');
        $this->assertNull($inv->tax_rate);
        $this->assertSame('0.00', $inv->tax_amount);

        app(BillingSettings::class)->save(['tax_enabled' => '1', 'tax_rate' => '8.00', 'tax_label' => 'VAT'], 'x');
        $taxed = $this->invoice(1, '1000.00');
        $this->assertSame('8.00', $taxed->tax_rate);
        $this->assertSame('80.00', $taxed->tax_amount);
        $this->assertSame('1080.00', $taxed->total);

        app(BillingSettings::class)->save(['tax_rate' => '18.00'], 'x');   // later change never touches the old invoice
        $this->assertEquals(80.0, $taxed->fresh()->tax_amount);
    }

    public function test_a_transfer_can_only_be_billed_once_and_wrong_buyer_is_refused(): void
    {
        $ledger = $this->makeLedger($this->dealer, 'GT06', 2);
        $this->billing->issueInvoice($this->buyer(), [$ledger->id => '10.00']);
        $this->expectException(DomainException::class);
        $this->billing->issueInvoice($this->buyer(), [$ledger->id => '10.00']);
    }

    public function test_bad_prices_and_zero_value_rules(): void
    {
        $ledger = $this->makeLedger($this->dealer, 'GT06', 2);
        foreach (['abc', '-5', '1.999', ''] as $bad) {
            try {
                $this->billing->issueInvoice($this->buyer(), [$ledger->id => $bad]);
                $this->fail("accepted price $bad");
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
        try {
            $this->billing->issueInvoice($this->buyer(), [$ledger->id => '0.00']);
            $this->fail('zero invoice without reason');
        } catch (DomainException) {
        }
        $z = $this->billing->issueInvoice($this->buyer(), [$ledger->id => '0'], null, 'Replacement units');
        $this->assertSame('0.00', $z->total);
    }

    public function test_company_must_be_set_up_first(): void
    {
        DB::table('billing_parties')->where('kind', 'company')->update(['address' => '']);
        $ledger = $this->makeLedger($this->dealer);
        $this->expectException(DomainException::class);
        $this->billing->issueInvoice($this->buyer(), [$ledger->id => '10']);
    }

    public function test_same_idempotency_key_issues_one_invoice(): void
    {
        $ledger = $this->makeLedger($this->dealer);
        $a = $this->billing->issueInvoice($this->buyer(), [$ledger->id => '10'], null, null, 'key-1');
        $b = $this->billing->issueInvoice($this->buyer(), [$ledger->id => '10'], null, null, 'key-1');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, Invoice::count());
    }

    public function test_issued_documents_cannot_be_changed_or_deleted(): void
    {
        $inv = $this->invoice();
        foreach ([fn () => $inv->update(['notes' => 'x']), fn () => $inv->delete(), fn () => $inv->lines()->first()->update(['quantity' => 9]), fn () => $inv->lines()->first()->delete()] as $try) {
            try {
                $try();
                $this->fail('mutation allowed');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }
        $r = $this->billing->recordReceipt($this->buyer(), '10.00', 'cash', null, now('Asia/Colombo')->toDateString());
        $this->expectException(LogicException::class);
        $r->update(['amount' => 5]);
    }

    public function test_receipt_allocation_overpayment_and_balance(): void
    {
        $i1 = $this->invoice(1, '1000.00');
        $i2 = $this->invoice(1, '500.00');
        $today = now('Asia/Colombo')->toDateString();

        $this->billing->recordReceipt($this->buyer(), '1200.00', 'bank_transfer', 'REF1', $today);
        $acc = $this->billing->account($this->dealer);
        $this->assertSame([150000, 120000, 30000], [$acc['invoiced'], $acc['paid'], $acc['balance']]);
        $this->assertSame(0, $this->billing->invoiceOutstanding($i1));          // oldest first
        $this->assertSame(30000, $this->billing->invoiceOutstanding($i2));

        $this->expectException(DomainException::class);                          // more than owed
        $this->billing->recordReceipt($this->buyer(), '300.01', 'cash', null, $today);
    }

    public function test_chosen_invoice_is_paid_first_and_cannot_exceed_it(): void
    {
        $i1 = $this->invoice(1, '1000.00');
        $i2 = $this->invoice(1, '500.00');
        $today = now('Asia/Colombo')->toDateString();
        $this->billing->recordReceipt($this->buyer(), '500.00', 'cash', null, $today, $i2->id);
        $this->assertSame(0, $this->billing->invoiceOutstanding($i2));
        $this->assertSame(100000, $this->billing->invoiceOutstanding($i1));
        $this->expectException(DomainException::class);
        $this->billing->recordReceipt($this->buyer(), '1.00', 'cash', null, $today, $i2->id);
    }

    public function test_receipt_input_rules_and_double_submit(): void
    {
        $this->invoice(1, '1000.00');
        $today = now('Asia/Colombo')->toDateString();
        foreach ([['0', 'cash', null, $today], ['10', 'bank_transfer', '', $today], ['10', 'cash', null, now('Asia/Colombo')->addDay()->toDateString()], ['10', 'gold', null, $today]] as [$amt, $m, $ref, $d]) {
            try {
                $this->billing->recordReceipt($this->buyer(), $amt, $m, $ref, $d);
                $this->fail("accepted $amt $m");
            } catch (DomainException) {
                $this->assertTrue(true);
            }
        }
        $a = $this->billing->recordReceipt($this->buyer(), '10', 'cash', null, $today, null, null, 'rk');
        $b = $this->billing->recordReceipt($this->buyer(), '10', 'cash', null, $today, null, null, 'rk');
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, PaymentReceipt::count());
    }

    public function test_reversal_restores_the_balance_and_keeps_the_record(): void
    {
        $inv = $this->invoice(1, '1000.00');
        $r = $this->billing->recordReceipt($this->buyer(), '1000.00', 'cash', null, now('Asia/Colombo')->toDateString());
        $this->assertSame(0, $this->billing->account($this->dealer)['balance']);

        $this->billing->reverseReceipt($r, 'Cheque bounced');
        $this->assertSame(100000, $this->billing->account($this->dealer)['balance']);
        $this->assertSame('reversed', $r->fresh()->status);
        $this->assertSame(1, PaymentReceipt::count());
        $this->assertSame(1, AuditLog::where('action', 'receipt.reversed')->count());

        $this->expectException(DomainException::class);   // cannot reverse twice
        $this->billing->reverseReceipt($r, 'again');
    }

    public function test_approval_mode_and_maker_cannot_approve(): void
    {
        $this->invoice(1, '1000.00');
        app(BillingSettings::class)->save(['approval_required' => '1'], 'x');
        $r = $this->billing->recordReceipt($this->buyer(), '400.00', 'cash', null, now('Asia/Colombo')->toDateString());
        $this->assertSame('pending', $r->status);
        $this->assertSame(100000, $this->billing->account($this->dealer)['balance']);   // not counted yet
        $this->assertSame(40000, $this->billing->account($this->dealer)['pending']);

        try {
            $this->billing->approveReceipt($r);
            $this->fail('maker approved own payment');
        } catch (DomainException) {
        }

        $this->switchTo($this->admin('checker'));
        $this->billing->approveReceipt($r);
        $this->assertSame('valid', $r->fresh()->status);
        $this->assertSame(60000, $this->billing->account($this->dealer)['balance']);
        $this->assertSame('Checker', $r->fresh()->approved_by_name);
    }

    public function test_rejection_needs_reason_and_another_person(): void
    {
        $this->invoice(1, '1000.00');
        app(BillingSettings::class)->save(['approval_required' => '1'], 'x');
        $r = $this->billing->recordReceipt($this->buyer(), '400.00', 'cash', null, now('Asia/Colombo')->toDateString());
        $this->switchTo($this->admin('checker'));
        try {
            $this->billing->rejectReceipt($r, ' ');
            $this->fail('no reason');
        } catch (DomainException) {
        }
        $this->billing->rejectReceipt($r, 'Slip does not match');
        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertSame(100000, $this->billing->account($this->dealer)['balance']);
    }

    public function test_credit_note_partial_then_rest_with_exact_tax(): void
    {
        app(BillingSettings::class)->save(['tax_enabled' => '1', 'tax_rate' => '8.00'], 'x');
        $inv = $this->invoice(3, '333.33');            // subtotal 999.99, tax 80.00 (79.9992 -> 80.00)
        $this->assertSame('80.00', $inv->tax_amount);
        $line = $inv->lines()->first();

        $c1 = $this->billing->issueCreditNote($inv, [$line->id => 1], 'Wrong quantity');
        $this->assertSame('333.33', $c1->subtotal);
        $this->assertSame('26.67', $c1->tax_amount);   // 8% of 333.33 = 26.6664 -> 26.67

        $c2 = $this->billing->issueCreditNote($inv, [$line->id => 2], 'Rest');
        $this->assertSame('53.33', $c2->tax_amount);   // exact remainder: 80.00 - 26.67
        $this->assertSame(0, $this->billing->account($this->dealer)['balance']);
        $this->assertSame(0, $this->billing->billedQuantity($line->transfer_ledger_id));

        $this->expectException(DomainException::class);
        $this->billing->issueCreditNote($inv, [$line->id => 1], 'too much');
    }

    public function test_fully_credited_transfer_can_be_invoiced_again(): void
    {
        $inv = $this->invoice(2, '100.00');
        $line = $inv->lines()->first();
        $this->billing->issueCreditNote($inv, [$line->id => 2], 'Billed in error');
        $again = $this->billing->issueInvoice($this->buyer(), [$line->transfer_ledger_id => '90.00']);
        $this->assertSame('180.00', $again->total);
    }

    public function test_unbilled_list_and_lock(): void
    {
        $a = $this->makeLedger($this->dealer, 'GT06', 1);
        $b = $this->makeLedger($this->dealer, 'JM-VL', 1);
        $this->billing->issueInvoice($this->buyer(), [$a->id => '10']);
        $ids = $this->billing->unbilledTransfers()->pluck('id')->all();
        $this->assertSame([$b->id], $ids);
        $this->assertTrue($a->fresh()->isLocked());   // invoiced transfers cannot be edited or hidden
    }

    public function test_statement_has_opening_running_and_closing_balance(): void
    {
        $inv = $this->invoice(1, '1000.00');
        $this->billing->recordReceipt($this->buyer(), '300.00', 'cash', null, now('Asia/Colombo')->toDateString());
        $today = now('Asia/Colombo')->toDateString();
        $s = $this->billing->statement($this->dealer, $today, $today);
        $this->assertSame(0, $s['opening']);
        $this->assertSame(70000, $s['closing']);
        $this->assertSame(['Invoice', 'Payment'], array_column($s['rows'], 'type'));
        $this->assertSame([100000, 70000], array_column($s['rows'], 'balance'));

        $later = now('Asia/Colombo')->addDay()->toDateString();
        $s2 = $this->billing->statement($this->dealer, $later, $later);
        $this->assertSame([70000, 70000, []], [$s2['opening'], $s2['closing'], $s2['rows']]);
    }

    public function test_verify_is_clean_then_detects_edit_delete_and_gap(): void
    {
        $i1 = $this->invoice();
        $i2 = $this->invoice();
        $i3 = $this->invoice();
        $this->billing->recordReceipt($this->buyer(), '10', 'cash', null, now('Asia/Colombo')->toDateString());
        $this->assertSame([], $this->billing->verify());

        if (DB::getDriverName() === 'pgsql') {   // on Postgres the trigger refuses the tampering itself
            $this->expectException(\Illuminate\Database\QueryException::class);
            DB::table('invoices')->where('id', $i3->id)->update(['total' => 1.00, 'subtotal' => 1.00]);

            return;
        }
        DB::table('invoices')->where('id', $i3->id)->update(['total' => 1.00, 'subtotal' => 1.00]);   // sneaky edit
        $p = $this->billing->verify();
        $this->assertNotEmpty(array_filter($p, fn ($x) => str_contains($x, $i3->number) && str_contains($x, 'snapshot')));
        DB::table('invoices')->where('id', $i3->id)->update(['total' => $i3->total, 'subtotal' => $i3->subtotal]);

        DB::table('invoice_lines')->where('invoice_id', $i2->id)->delete();
        DB::table('invoices')->where('id', $i2->id)->delete();   // removed document
        $p = $this->billing->verify();
        $this->assertNotEmpty(array_filter($p, fn ($x) => str_contains($x, $i3->number) && str_contains($x, 'chain link')));
        $this->assertNotEmpty(array_filter($p, fn ($x) => str_contains($x, $i3->number) && str_contains($x, 'gap')));
    }

    public function test_snapshot_survives_party_changes(): void
    {
        $inv = $this->invoice();
        DB::table('billing_parties')->where('dealer_id', $this->dealer)->update(['legal_name' => 'Renamed Ltd', 'address' => 'New address']);
        $this->assertSame('Alpha One', $inv->fresh()->snapshot['buyer']['legal_name']);
        $this->assertSame([], $this->billing->verify());
    }

    public function test_every_write_is_audited(): void
    {
        $inv = $this->invoice(1, '100.00');
        $r = $this->billing->recordReceipt($this->buyer(), '50.00', 'cash', null, now('Asia/Colombo')->toDateString());
        $this->billing->issueCreditNote($inv, [$inv->lines()->first()->id => 1], 'oops');
        $this->billing->reverseReceipt($r, 'bounced');
        foreach (['invoice.issued', 'receipt.recorded', 'credit_note.issued', 'receipt.reversed'] as $a) {
            $this->assertSame(1, AuditLog::where('action', $a)->where('category', 'money')->count(), $a);
        }
    }
}
