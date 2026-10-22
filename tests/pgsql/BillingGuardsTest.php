<?php

namespace Tests\Pgsql;

use App\Models\Dealer;
use App\Models\DealerTransferLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\PgsqlTestCase;

/**
 * The PostgreSQL guards on the wholesale billing tables (migration 2026_10_22_000002).
 *
 * Issued invoices, credit notes and payment allocations are evidence: the DATABASE itself must refuse to
 * change or delete them, whatever the application code does. Only PostgreSQL has these triggers and CHECK
 * constraints, so only this suite (not the SQLite one) can prove them.
 *
 * Each forbidden change is tried inside its own savepoint so one refusal does not abort the next check, and
 * everything is rolled back by DatabaseTransactions.
 */
class BillingGuardsTest extends PgsqlTestCase
{
    use DatabaseTransactions;

    private int $companyParty;
    private int $dealerParty;
    private int $dealerId;
    private int $ledgerId;

    protected function setUp(): void
    {
        parent::setUp();

        $dealer = Dealer::create([
            'full_name'     => 'Autotest Billing Dealer',
            'dealer_status' => 'retailer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => 'autotest+billing@example.test',
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
        $this->dealerId = (int) $dealer->id;

        $this->ledgerId = (int) DealerTransferLedger::create([
            'dealer_id'           => $dealer->id,
            'device_category'     => 'AUTOTEST-CAT',
            'quantity'            => 2,
            'created_by_admin_id' => 'autotest',
        ])->id;

        $this->companyParty = (int) DB::table('billing_parties')->insertGetId(['kind' => 'company', 'legal_name' => 'Autotest Co']);
        $this->dealerParty  = (int) DB::table('billing_parties')->insertGetId(['kind' => 'dealer', 'dealer_id' => $dealer->id, 'legal_name' => 'Autotest Dealer']);
    }

    private function invoice(): int
    {
        return (int) DB::table('invoices')->insertGetId([
            'number'          => 'AUTOTEST-INV-' . random_int(100000, 999999),
            'issuer_party_id' => $this->companyParty,
            'buyer_party_id'  => $this->dealerParty,
            'buyer_dealer_id' => $this->dealerId,
            'issue_date'      => '2026-10-22',
            'due_date'        => '2026-11-21',
            'subtotal'        => 100,
            'tax_amount'      => 0,
            'total'           => 100,
            'snapshot'        => '{}',
            'snapshot_sha256' => str_repeat('a', 64),
        ]);
    }

    private function receipt(string $status = 'pending'): int
    {
        return (int) DB::table('payment_receipts')->insertGetId([
            'number'          => 'AUTOTEST-RCT-' . random_int(100000, 999999),
            'issuer_party_id' => $this->companyParty,
            'buyer_dealer_id' => $this->dealerId,
            'amount'          => 50,
            'method'          => 'cash',
            'received_date'   => '2026-10-22',
            'status'          => $status,
            'snapshot'        => '{}',
            'snapshot_sha256' => str_repeat('b', 64),
        ]);
    }

    /** Run a change the database must refuse; the message must contain $needle. */
    private function refused(callable $change, string $needle): void
    {
        DB::beginTransaction();
        try {
            $change();
            $this->fail('The database accepted a change it must refuse.');
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_an_issued_invoice_and_its_lines_cannot_be_updated_or_deleted(): void
    {
        $id = $this->invoice();
        $lineId = DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $id, 'transfer_ledger_id' => $this->ledgerId, 'description' => 'x',
            'device_category' => 'GT06', 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100,
        ]);

        $this->refused(fn () => DB::table('invoices')->where('id', $id)->update(['notes' => 'x']), 'cannot be update');
        $this->refused(fn () => DB::table('invoices')->where('id', $id)->delete(), 'cannot be delete');
        $this->refused(fn () => DB::table('invoice_lines')->where('id', $lineId)->update(['quantity' => 9]), 'cannot be update');
        $this->refused(fn () => DB::table('invoice_lines')->where('id', $lineId)->delete(), 'cannot be delete');

        $this->assertSame(1, DB::table('invoices')->where('id', $id)->count(), 'the invoice must still be there');
    }

    public function test_the_money_rules_are_enforced_by_check_constraints(): void
    {
        $id   = $this->invoice();
        $line = ['invoice_id' => $id, 'transfer_ledger_id' => $this->ledgerId, 'description' => 'x', 'device_category' => 'GT06'];

        // quantity must be positive
        $this->refused(fn () => DB::table('invoice_lines')->insert($line + ['quantity' => 0, 'unit_price' => 1, 'line_total' => 0]), 'invoice_lines_ok');
        // the line total must equal quantity x unit price
        $this->refused(fn () => DB::table('invoice_lines')->insert($line + ['quantity' => 2, 'unit_price' => 5, 'line_total' => 11]), 'invoice_lines_ok');

        // an invoice total must equal subtotal + tax
        $this->refused(fn () => DB::table('invoices')->insert([
            'number' => 'AUTOTEST-BAD-' . random_int(100000, 999999), 'issuer_party_id' => $this->companyParty,
            'buyer_party_id' => $this->dealerParty, 'buyer_dealer_id' => $this->dealerId,
            'issue_date' => '2026-10-22', 'due_date' => '2026-10-22',
            'subtotal' => 100, 'tax_amount' => 10, 'total' => 100, 'snapshot' => '{}', 'snapshot_sha256' => str_repeat('a', 64),
        ]), 'invoices_amounts_ok');

        // a receipt must be for more than zero
        $this->refused(fn () => DB::table('payment_receipts')->insert([
            'number' => 'AUTOTEST-BAD-' . random_int(100000, 999999), 'issuer_party_id' => $this->companyParty,
            'buyer_dealer_id' => $this->dealerId, 'amount' => 0, 'method' => 'cash', 'received_date' => '2026-10-22',
            'status' => 'valid', 'snapshot' => '{}', 'snapshot_sha256' => str_repeat('b', 64),
        ]), 'payment_receipts_ok');
    }

    public function test_a_receipt_only_moves_along_the_allowed_status_path_and_never_vanishes(): void
    {
        $r = $this->receipt('pending');

        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->delete(), 'cannot be deleted');
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['amount' => 1]), 'Only the status');
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['reference' => 'changed']), 'Only the status');
        // pending -> reversed is not an allowed step
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['status' => 'reversed']), 'cannot move');

        // pending -> valid -> reversed is the allowed path
        DB::table('payment_receipts')->where('id', $r)->update(['status' => 'valid', 'approved_by' => 'autotest']);
        DB::table('payment_receipts')->where('id', $r)->update(['status' => 'reversed', 'reversal_reason' => 'autotest']);
        $this->assertSame('reversed', DB::table('payment_receipts')->where('id', $r)->value('status'));

        // once reversed it is final
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['status' => 'valid']), 'cannot move');
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['reversal_reason' => 'edited']), 'final');

        // a rejected receipt cannot be revived
        $rejected = $this->receipt('pending');
        DB::table('payment_receipts')->where('id', $rejected)->update(['status' => 'rejected', 'rejection_reason' => 'autotest']);
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $rejected)->update(['status' => 'valid']), 'cannot move');
    }

    public function test_allocations_and_credit_notes_are_append_only(): void
    {
        $invoice = $this->invoice();
        $receipt = $this->receipt('valid');

        $allocationId = DB::table('payment_allocations')->insertGetId(['receipt_id' => $receipt, 'invoice_id' => $invoice, 'amount' => 10]);
        $this->refused(fn () => DB::table('payment_allocations')->where('id', $allocationId)->delete(), 'cannot be delete');
        $this->refused(fn () => DB::table('payment_allocations')->where('id', $allocationId)->update(['amount' => 1]), 'cannot be update');

        $creditNote = DB::table('credit_notes')->insertGetId([
            'number' => 'AUTOTEST-CRN-' . random_int(100000, 999999), 'invoice_id' => $invoice, 'issuer_party_id' => $this->companyParty,
            'buyer_dealer_id' => $this->dealerId, 'issue_date' => '2026-10-22',
            'subtotal' => 10, 'tax_amount' => 0, 'total' => 10, 'reason' => 'autotest',
            'snapshot' => '{}', 'snapshot_sha256' => str_repeat('c', 64),
        ]);
        $this->refused(fn () => DB::table('credit_notes')->where('id', $creditNote)->delete(), 'cannot be delete');
        $this->refused(fn () => DB::table('credit_notes')->where('id', $creditNote)->update(['reason' => 'edited']), 'cannot be update');
    }
}