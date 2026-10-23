<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\AuditLog;
use App\Models\BillingParty;
use App\Models\Dealer;
use App\Models\DealerTransferItem;
use App\Models\DealerTransferLedger;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PaymentReceipt;
use App\Models\SetupShalotrackDevice;
use App\Services\Billing\Billing;
use App\Services\Billing\BillingSettings;
use App\Support\Money;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * Wholesale billing, start to finish, on real PostgreSQL (the autotest schema):
 * invoice numbers, totals, tax, "a transfer is billed once", payments (oldest invoice first, never more than
 * owed), approval by a second person, reversals, credit notes, statements and the tamper check (verify()).
 *
 * These call the Billing service directly (no web page), exactly as the admin screens do. The same rules are
 * covered on SQLite in tests/Feature/BillingServiceTest.php; this file proves they also hold on Postgres.
 *
 * Safe by design: only rows created here are used (company "AUTOTEST", buyers "Autotest Buyer ...", devices with
 * IMEI 99900000 4xx) and DatabaseTransactions rolls everything back at the end of every test.
 */
class BillingFlowTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private const CATEGORY = 'AUTOTEST-GT06';

    private Billing $billing;
    private Dealer $buyer;
    private \App\Models\Admin $maker;
    private int $imeiSeq = 400;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->seedCompany();
        $this->billing = app(Billing::class);
        $this->buyer   = $this->makeBuyer('a');
        $this->maker   = $this->makeAdmin('ADMIN');
        $this->actingAs($this->maker);
    }

    // ------------------------------------------------------------------ helpers

    /** The company that issues the documents. The autotest schema starts empty, so it is created here (rolled back later). */
    private function seedCompany(): void
    {
        $details = [
            'legal_name'   => 'AUTOTEST Shalotrack Lanka (Pvt) Ltd',
            'address'      => '1 Autotest Road, Colombo',
            'tax_number'   => 'TIN-AUTOTEST',
            'bank_details' => 'Autotest Bank 000111',
        ];

        $company = BillingParty::where('kind', BillingParty::KIND_COMPANY)->first();
        $company
            ? $company->forceFill($details)->save()
            : BillingParty::create(['kind' => BillingParty::KIND_COMPANY] + $details);
    }

    private function makeBuyer(string $suffix): Dealer
    {
        return Dealer::create([
            'full_name'     => 'Autotest Buyer ' . $suffix,
            'dealer_status' => 'retailer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+buyer-{$suffix}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    /** A transfer to $to with $qty real devices inside it (device rows are needed because of the foreign key). */
    private function ledger(Dealer $to, int $qty = 2): DealerTransferLedger
    {
        $ledger = DealerTransferLedger::create([
            'dealer_id'           => $to->id,
            'from_dealer_id'      => null,
            'device_category'     => self::CATEGORY,
            'quantity'            => $qty,
            'created_by_admin_id' => 'autotest',
        ]);

        for ($i = 0; $i < $qty; $i++) {
            $n = (string) $this->imeiSeq++;

            $device = SetupShalotrackDevice::create([
                'device_category' => self::CATEGORY,
                'imei_number'     => '99900000' . $n,
                'sim_number'      => '07200000' . $n,
                'iccid'           => '8994200000' . $n,
                'status'          => DeviceStatus::Activated->value,
                'dealer_id'       => $to->id,
            ]);

            DealerTransferItem::create([
                'ledger_id'   => $ledger->id,
                'device_id'   => $device->shdevice_id,
                'imei_number' => $device->imei_number,
                'sim_number'  => $device->sim_number,
            ]);
        }

        return $ledger;
    }

    /** Make a new transfer for the buyer and invoice it. */
    private function issue(int $qty = 2, string $price = '1000.00', ?Dealer $to = null): Invoice
    {
        $to ??= $this->buyer;
        $ledger = $this->ledger($to, $qty);

        return $this->billing->issueInvoice($to, [$ledger->id => $price]);
    }

    private function pay(string $amount, ?int $invoiceId = null, ?string $key = null, string $method = 'cash', ?string $reference = null): PaymentReceipt
    {
        return $this->billing->recordReceipt($this->buyer, $amount, $method, $reference, $this->today(), $invoiceId, null, $key);
    }

    private function today(): string
    {
        return now('Asia/Colombo')->toDateString();
    }

    /** Money from the database (string "1000.00") as whole cents. */
    private function cents(mixed $value): int
    {
        return Money::dbToCents($value);
    }

    /** "SL-INV-2026-000007" -> 7 */
    private function seq(string $number): int
    {
        return (int) substr($number, -6);
    }

    private function balance(?Dealer $who = null): int
    {
        return $this->billing->account(($who ?? $this->buyer)->id)['balance'];
    }

    /** The action must be refused with a DomainException (a friendly message), optionally containing $part. */
    private function refuses(callable $action, string $part = ''): void
    {
        try {
            $action();
        } catch (DomainException $e) {
            if ($part !== '') {
                $this->assertStringContainsString($part, $e->getMessage());
            }
            $this->assertTrue(true);

            return;
        }

        $this->fail('The service accepted something it must refuse.');
    }

    /** Log in as somebody else without any web request (no password-hash logout can happen here). */
    private function switchTo(\App\Models\Admin $admin): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($admin);
    }

    // ------------------------------------------------------------------ numbering

    public function test_numbers_follow_the_format_and_never_skip(): void
    {
        $a = $this->issue();
        $b = $this->issue();
        $year = now('Asia/Colombo')->format('Y');

        $this->assertMatchesRegularExpression("/^SL-INV-{$year}-\\d{6}$/", $a->number);
        $this->assertSame($this->seq($a->number) + 1, $this->seq($b->number), 'invoice numbers must be consecutive');

        // Every document type has its own counter.
        $receipt = $this->pay('100.00');
        $this->assertMatchesRegularExpression("/^SL-RCT-{$year}-\\d{6}$/", $receipt->number);

        $credit = $this->billing->issueCreditNote($a, [$a->lines()->first()->id => 1], 'Autotest credit');
        $this->assertMatchesRegularExpression("/^SL-CRN-{$year}-\\d{6}$/", $credit->number);
    }

    public function test_a_failed_issue_never_burns_a_number(): void
    {
        $first = $this->issue();
        $seq   = $this->seq($first->number);

        // 1) Refused by a business rule: somebody else's transfer.
        $stranger = $this->makeBuyer('stranger');
        $foreign  = $this->ledger($stranger, 1);
        $this->refuses(fn () => $this->billing->issueInvoice($this->buyer, [$foreign->id => '10.00']));

        // 2) Refused by the DATABASE after the number was already taken: the idempotency key is longer than
        //    its 64-character column, so the insert fails. The number must go back (savepoint rollback).
        $ledger = $this->ledger($this->buyer, 1);
        try {
            $this->billing->issueInvoice($this->buyer, [$ledger->id => '10.00'], null, null, str_repeat('k', 65));
            $this->fail('A 65-character idempotency key should have been refused by the database.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }

        $next = $this->billing->issueInvoice($this->buyer, [$ledger->id => '10.00']);

        $this->assertSame($seq + 1, $this->seq($next->number), 'no number may be lost after a failed attempt');
        $this->assertSame(2, Invoice::where('buyer_dealer_id', $this->buyer->id)->count(), 'the failed attempts left nothing behind');
    }

    // ------------------------------------------------------------------ invoices

    public function test_an_invoice_has_the_right_totals_snapshot_lines_and_audit_entry(): void
    {
        $invoice = $this->issue(3, '1250.50');

        $fresh = $invoice->fresh();
        $this->assertSame(375150, $this->cents($fresh->total));
        $this->assertSame(375150, $this->cents($fresh->subtotal));
        $this->assertSame(0, $this->cents($fresh->tax_amount));
        $this->assertSame('LKR', $fresh->currency);

        $this->assertSame($this->buyer->full_name, $fresh->snapshot['buyer']['legal_name']);
        $this->assertCount(3, $fresh->snapshot['lines'][0]['devices'], 'all three devices are listed on the invoice');
        $this->assertSame(1, InvoiceLine::where('invoice_id', $invoice->id)->count());

        $this->assertSame(1, AuditLog::where('action', 'invoice.issued')->where('subject_label', $invoice->number)->count());

        $this->assertSame(30, (int) $fresh->issue_date->diffInDays($fresh->due_date), 'default payment terms are 30 days');
    }

    public function test_tax_is_off_by_default_and_is_frozen_on_the_invoice_when_on(): void
    {
        $plain = $this->issue(1, '1000.00');
        $this->assertNull($plain->fresh()->tax_rate);
        $this->assertSame(0, $this->cents($plain->fresh()->tax_amount));

        $settings = app(BillingSettings::class);
        $settings->save(['tax_enabled' => '1', 'tax_rate' => '8.00', 'tax_label' => 'VAT'], $this->maker->admin_id);

        $taxed = $this->issue(1, '1000.00');
        $this->assertEquals(8.0, (float) $taxed->fresh()->tax_rate);
        $this->assertSame(8000, $this->cents($taxed->fresh()->tax_amount));
        $this->assertSame(108000, $this->cents($taxed->fresh()->total));

        // A later rate change must never touch an invoice that is already issued.
        $settings->save(['tax_rate' => '18.00'], $this->maker->admin_id);
        $this->assertSame(8000, $this->cents($taxed->fresh()->tax_amount));
    }

    public function test_a_transfer_can_only_be_billed_once(): void
    {
        $ledger = $this->ledger($this->buyer, 2);
        $this->billing->issueInvoice($this->buyer, [$ledger->id => '10.00']);

        $this->refuses(fn () => $this->billing->issueInvoice($this->buyer, [$ledger->id => '10.00']), 'already on an invoice');

        $this->assertSame(1, Invoice::where('buyer_dealer_id', $this->buyer->id)->count());
    }

    public function test_a_transfer_of_another_buyer_cannot_be_put_on_this_buyers_invoice(): void
    {
        $other   = $this->makeBuyer('other');
        $foreign = $this->ledger($other, 1);

        $this->refuses(fn () => $this->billing->issueInvoice($this->buyer, [$foreign->id => '10.00']), 'does not belong to this buyer');

        $this->assertSame(0, Invoice::count());
    }

    public function test_bad_prices_and_the_zero_value_rule(): void
    {
        $ledger = $this->ledger($this->buyer, 2);

        foreach (['abc', '-5', '1.999', ''] as $bad) {
            $this->refuses(fn () => $this->billing->issueInvoice($this->buyer, [$ledger->id => $bad]), 'valid price');
        }

        // Zero is only allowed with a written reason.
        $this->refuses(fn () => $this->billing->issueInvoice($this->buyer, [$ledger->id => '0.00']), 'needs a written reason');
        $this->refuses(fn () => $this->billing->issueInvoice($this->buyer, [$ledger->id => '0.00'], null, '   '), 'needs a written reason');

        $this->assertSame(0, Invoice::count(), 'nothing was issued by the refused attempts');

        $zero = $this->billing->issueInvoice($this->buyer, [$ledger->id => '0'], null, 'Replacement units');
        $this->assertSame(0, $this->cents($zero->fresh()->total));
        $this->assertSame('Replacement units', $zero->fresh()->zero_reason);
    }

    public function test_nothing_can_be_issued_until_the_company_details_are_complete(): void
    {
        BillingParty::where('kind', BillingParty::KIND_COMPANY)->update(['address' => '']);
        $ledger = $this->ledger($this->buyer, 1);

        $this->refuses(fn () => $this->billing->issueInvoice($this->buyer, [$ledger->id => '10.00']), 'company details');

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_double_click_with_the_same_key_issues_only_one_invoice(): void
    {
        $ledger = $this->ledger($this->buyer, 1);

        $a = $this->billing->issueInvoice($this->buyer, [$ledger->id => '10.00'], null, null, 'autotest-key-1');
        $b = $this->billing->issueInvoice($this->buyer, [$ledger->id => '99.00'], null, null, 'autotest-key-1');

        $this->assertSame($a->id, $b->id, 'the second click returns the first invoice');
        $this->assertSame(1, Invoice::where('buyer_dealer_id', $this->buyer->id)->count());
        $this->assertSame(1000, $this->cents($a->fresh()->total), 'the second price is ignored');
    }

    public function test_issued_documents_cannot_be_changed_or_deleted_through_the_models(): void
    {
        $invoice = $this->issue();
        $actions = [
            fn () => $invoice->update(['notes' => 'x']),
            fn () => $invoice->delete(),
            fn () => $invoice->lines()->first()->update(['quantity' => 9]),
            fn () => $invoice->lines()->first()->delete(),
        ];

        foreach ($actions as $action) {
            try {
                $action();
                $this->fail('An issued invoice was changed.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        }

        $receipt = $this->pay('10.00');
        $this->expectException(LogicException::class);
        $receipt->update(['amount' => 5]);
    }

    public function test_unbilled_transfers_list_and_the_lock_on_billed_ones(): void
    {
        $billed   = $this->ledger($this->buyer, 1);
        $unbilled = $this->ledger($this->buyer, 1);
        $this->billing->issueInvoice($this->buyer, [$billed->id => '10.00']);

        $this->assertEquals([$unbilled->id], $this->billing->unbilledTransfers($this->buyer->id)->pluck('id')->all());
        $this->assertTrue($billed->fresh()->isLocked(), 'an invoiced transfer can no longer be edited or hidden');
    }

    // ------------------------------------------------------------------ payments

    public function test_a_payment_goes_to_the_oldest_invoice_first_and_never_exceeds_what_is_owed(): void
    {
        $first  = $this->issue(1, '1000.00');
        $second = $this->issue(1, '500.00');

        $this->pay('1200.00', null, null, 'bank_transfer', 'AUTOTEST-REF-1');

        $account = $this->billing->account($this->buyer->id);
        $this->assertSame([150000, 120000, 30000], [$account['invoiced'], $account['paid'], $account['balance']]);
        $this->assertSame(0, $this->billing->invoiceOutstanding($first), 'the oldest invoice is settled first');
        $this->assertSame(30000, $this->billing->invoiceOutstanding($second));

        $this->refuses(fn () => $this->pay('300.01'), 'more than the buyer currently owes');

        $this->assertSame(30000, $this->balance(), 'the refused payment changed nothing');
        $this->assertSame(1, PaymentReceipt::where('buyer_dealer_id', $this->buyer->id)->count());
    }

    public function test_the_chosen_invoice_is_paid_first_and_cannot_be_overpaid(): void
    {
        $first  = $this->issue(1, '1000.00');
        $second = $this->issue(1, '500.00');

        $this->pay('500.00', $second->id);

        $this->assertSame(0, $this->billing->invoiceOutstanding($second));
        $this->assertSame(100000, $this->billing->invoiceOutstanding($first));

        $this->refuses(fn () => $this->pay('1.00', $second->id), 'more than the selected invoice still owes');
    }

    public function test_a_payment_cannot_be_aimed_at_another_buyers_invoice(): void
    {
        $this->issue(1, '1000.00');
        $other      = $this->makeBuyer('b');
        $otherBill  = $this->issue(1, '700.00', $other);

        $this->refuses(fn () => $this->pay('100.00', $otherBill->id), 'does not belong to this buyer');

        $this->assertSame(70000, $this->balance($other), 'the other buyer is untouched');
    }

    public function test_payment_input_rules_and_the_double_submit_guard(): void
    {
        $this->issue(1, '1000.00');
        $tomorrow = now('Asia/Colombo')->addDay()->toDateString();

        $bad = [
            ['0',      'cash',          null, $this->today()],   // zero
            ['-5',     'cash',          null, $this->today()],   // negative
            ['10.999', 'cash',          null, $this->today()],   // 3 decimals
            ['abc',    'cash',          null, $this->today()],   // not a number
            ['10',     'bank_transfer', '',   $this->today()],   // bank payment without a reference
            ['10',     'cash',          null, $tomorrow],        // date in the future
            ['10',     'gold',          null, $this->today()],   // unknown method
        ];

        foreach ($bad as [$amount, $method, $reference, $date]) {
            $this->refuses(fn () => $this->billing->recordReceipt($this->buyer, $amount, $method, $reference, $date));
        }
        $this->assertSame(0, PaymentReceipt::where('buyer_dealer_id', $this->buyer->id)->count());

        $a = $this->pay('10.00', null, 'autotest-rk');
        $b = $this->pay('10.00', null, 'autotest-rk');
        $this->assertSame($a->id, $b->id, 'the same key records the payment once');
        $this->assertSame(1, PaymentReceipt::where('buyer_dealer_id', $this->buyer->id)->count());
    }

    public function test_reversing_a_payment_restores_the_balance_and_keeps_the_record(): void
    {
        $this->issue(1, '1000.00');
        $receipt = $this->pay('1000.00');
        $this->assertSame(0, $this->balance());

        $this->refuses(fn () => $this->billing->reverseReceipt($receipt, '  '), 'reason');

        $this->billing->reverseReceipt($receipt, 'Cheque bounced');

        $this->assertSame(100000, $this->balance());
        $this->assertSame('reversed', $receipt->fresh()->status);
        $this->assertSame(1, PaymentReceipt::where('buyer_dealer_id', $this->buyer->id)->count(), 'the receipt is never deleted');
        $this->assertSame(1, AuditLog::where('action', 'receipt.reversed')->where('subject_label', $receipt->number)->count());

        $this->refuses(fn () => $this->billing->reverseReceipt($receipt, 'again'), 'Only a valid receipt');

        // The buyer can pay again once the first payment is reversed.
        $this->pay('1000.00');
        $this->assertSame(0, $this->balance());
    }

    // ------------------------------------------------------------------ approval (two people)

    public function test_with_approval_on_a_payment_waits_and_the_person_who_recorded_it_cannot_approve_it(): void
    {
        $this->issue(1, '1000.00');
        app(BillingSettings::class)->save(['approval_required' => '1'], $this->maker->admin_id);

        $receipt = $this->pay('400.00');
        $this->assertSame('pending', $receipt->status);

        $account = $this->billing->account($this->buyer->id);
        $this->assertSame(100000, $account['balance'], 'a waiting payment is not counted yet');
        $this->assertSame(40000, $account['pending']);

        $this->refuses(fn () => $this->billing->approveReceipt($receipt), 'someone else must approve');
        $this->refuses(fn () => $this->billing->rejectReceipt($receipt, 'Autotest'), 'someone else must decide');

        $this->switchTo($this->makeAdmin('ADMIN'));
        $this->billing->approveReceipt($receipt);

        $this->assertSame('valid', $receipt->fresh()->status);
        $this->assertSame(60000, $this->balance());
        $this->assertNotNull($receipt->fresh()->approved_by);

        $this->refuses(fn () => $this->billing->approveReceipt($receipt), 'no longer waiting');
    }

    public function test_waiting_payments_count_against_what_the_buyer_owes(): void
    {
        $this->issue(1, '1000.00');
        app(BillingSettings::class)->save(['approval_required' => '1'], $this->maker->admin_id);

        $this->pay('700.00');

        $this->refuses(fn () => $this->pay('400.00'), 'more than the buyer currently owes');
        $this->pay('300.00');   // exactly what is left to cover is accepted

        $this->assertSame(2, PaymentReceipt::where('buyer_dealer_id', $this->buyer->id)->count());
    }

    public function test_rejecting_a_payment_needs_a_reason_and_a_second_person(): void
    {
        $this->issue(1, '1000.00');
        app(BillingSettings::class)->save(['approval_required' => '1'], $this->maker->admin_id);
        $receipt = $this->pay('400.00');

        $this->switchTo($this->makeAdmin('ADMIN'));

        $this->refuses(fn () => $this->billing->rejectReceipt($receipt, ' '), 'reason');

        $this->billing->rejectReceipt($receipt, 'Slip does not match');

        $this->assertSame('rejected', $receipt->fresh()->status);
        $this->assertSame(100000, $this->balance(), 'a rejected payment never counts');
    }

    public function test_approval_is_refused_when_the_debt_was_cleared_in_the_meantime(): void
    {
        $invoice = $this->issue(2, '500.00');   // 1000.00 in total
        app(BillingSettings::class)->save(['approval_required' => '1'], $this->maker->admin_id);
        $receipt = $this->pay('800.00');

        // Before anybody approves, the whole invoice is credited back.
        $line = $invoice->lines()->first();
        $this->billing->issueCreditNote($invoice, [$line->id => 2], 'Billed in error');
        $this->assertSame(0, $this->balance());

        $this->switchTo($this->makeAdmin('ADMIN'));
        $this->refuses(fn () => $this->billing->approveReceipt($receipt), 'no longer owes');

        $this->assertSame('pending', $receipt->fresh()->status, 'the payment stays waiting; it was not applied');
    }

    // ------------------------------------------------------------------ credit notes

    public function test_credit_notes_are_partial_then_the_rest_with_exact_tax(): void
    {
        app(BillingSettings::class)->save(['tax_enabled' => '1', 'tax_rate' => '8.00'], $this->maker->admin_id);

        $invoice = $this->issue(3, '333.33');   // subtotal 999.99, tax 80.00 (79.9992 rounded)
        $this->assertSame(8000, $this->cents($invoice->fresh()->tax_amount));
        $line = $invoice->lines()->first();

        $first = $this->billing->issueCreditNote($invoice, [$line->id => 1], 'Wrong quantity');
        $this->assertSame(33333, $this->cents($first->fresh()->subtotal));
        $this->assertSame(2667, $this->cents($first->fresh()->tax_amount));      // 8% of 333.33 = 26.6664 -> 26.67

        $second = $this->billing->issueCreditNote($invoice, [$line->id => 2], 'The rest');
        $this->assertSame(5333, $this->cents($second->fresh()->tax_amount));     // exact remainder 80.00 - 26.67

        $this->assertSame(0, $this->balance(), 'the two credit notes cancel the invoice exactly, not a cent more');
        $this->assertSame(0, $this->billing->billedQuantity($line->transfer_ledger_id));

        $this->refuses(fn () => $this->billing->issueCreditNote($invoice, [$line->id => 1], 'Too much'), 'cannot credit more');
        $this->refuses(fn () => $this->billing->issueCreditNote($invoice, [$line->id => 1], ' '), 'needs a reason');
    }

    public function test_a_fully_credited_transfer_can_be_invoiced_again(): void
    {
        $invoice = $this->issue(2, '100.00');
        $line    = $invoice->lines()->first();

        $this->billing->issueCreditNote($invoice, [$line->id => 2], 'Billed in error');

        $again = $this->billing->issueInvoice($this->buyer, [$line->transfer_ledger_id => '90.00']);
        $this->assertSame(18000, $this->cents($again->fresh()->total));
    }

    // ------------------------------------------------------------------ statement, tamper check, audit

    public function test_the_statement_shows_opening_running_and_closing_balance(): void
    {
        $this->issue(1, '1000.00');
        $this->pay('300.00');
        $today = $this->today();

        $statement = $this->billing->statement($this->buyer->id, $today, $today);
        $this->assertSame(0, $statement['opening']);
        $this->assertSame(70000, $statement['closing']);
        $this->assertSame(['Invoice', 'Payment'], array_column($statement['rows'], 'type'));
        $this->assertSame([100000, 70000], array_column($statement['rows'], 'balance'));

        $later = now('Asia/Colombo')->addDay()->toDateString();
        $after = $this->billing->statement($this->buyer->id, $later, $later);
        $this->assertSame([70000, 70000, []], [$after['opening'], $after['closing'], $after['rows']]);
    }

    public function test_the_tamper_check_is_clean_after_a_full_flow_on_postgres(): void
    {
        app(BillingSettings::class)->save(['tax_enabled' => '1', 'tax_rate' => '8.00'], $this->maker->admin_id);

        $one = $this->issue(2, '500.00');
        $this->issue(1, '250.00');
        $receipt = $this->pay('400.00', null, null, 'bank_transfer', 'AUTOTEST-REF-2');
        $this->billing->issueCreditNote($one, [$one->lines()->first()->id => 1], 'Autotest credit');
        $this->billing->reverseReceipt($receipt, 'Autotest reversal');

        $this->assertSame([], $this->billing->verify(), 'every hash, link, number and total must still match');
    }

    public function test_an_issued_invoice_keeps_its_old_buyer_details_when_the_party_changes(): void
    {
        $invoice = $this->issue();

        BillingParty::where('dealer_id', $this->buyer->id)->update(['legal_name' => 'Renamed Autotest Ltd', 'address' => 'New address']);

        $this->assertSame($this->buyer->full_name, $invoice->fresh()->snapshot['buyer']['legal_name']);
        $this->assertSame([], $this->billing->verify());
    }

    public function test_every_money_write_is_audited(): void
    {
        $invoice = $this->issue(1, '100.00');
        $receipt = $this->pay('50.00');
        $credit  = $this->billing->issueCreditNote($invoice, [$invoice->lines()->first()->id => 1], 'Autotest');
        $this->billing->reverseReceipt($receipt, 'Autotest reversal');

        $expected = [
            'invoice.issued'     => $invoice->number,
            'receipt.recorded'   => $receipt->number,
            'credit_note.issued' => $credit->number,
            'receipt.reversed'   => $receipt->number,
        ];

        foreach ($expected as $action => $label) {
            $this->assertSame(
                1,
                AuditLog::where('action', $action)->where('category', 'money')->where('subject_label', $label)->count(),
                "audit entry missing for {$action}"
            );
        }
    }
}