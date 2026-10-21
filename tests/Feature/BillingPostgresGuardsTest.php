<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the PostgreSQL triggers and CHECK constraints from 2026_10_22_000002. Skipped on the default sqlite
 * test run. To run it against a THROWAWAY Postgres database (it drops and recreates the billing tables):
 *
 *   DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=billtest DB_USERNAME=... DB_PASSWORD=... \
 *   BILLING_PG_GUARD_TEST=yes-drop-billing-tables vendor/bin/phpunit tests/Feature/BillingPostgresGuardsTest.php
 */
class BillingPostgresGuardsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || getenv('BILLING_PG_GUARD_TEST') !== 'yes-drop-billing-tables') {
            $this->markTestSkipped('Needs a throwaway PostgreSQL database (see the class comment).');
        }

        foreach (['payment_allocations', 'payment_receipts', 'credit_note_lines', 'credit_notes', 'invoice_lines', 'invoices', 'document_sequences', 'billing_settings', 'billing_parties'] as $t) {
            DB::unprepared("DROP TABLE IF EXISTS {$t} CASCADE");
        }
        Schema::dropIfExists('dealer_transfer_ledgers');
        Schema::dropIfExists('dealers');
        Schema::create('dealers', function (Blueprint $t) {
            $t->id();
            $t->string('full_name');
            $t->timestamps();
        });
        Schema::create('dealer_transfer_ledgers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dealer_id');
            $t->timestamps();
        });
        (include database_path('migrations/2026_10_22_000001_create_wholesale_billing_tables.php'))->up();
        (include database_path('migrations/2026_10_22_000002_add_postgres_guards_to_billing_tables.php'))->up();

        DB::table('dealers')->insert(['id' => 1, 'full_name' => 'D']);
        DB::table('dealer_transfer_ledgers')->insert(['id' => 1, 'dealer_id' => 1]);
        DB::table('billing_parties')->insert(['id' => 1, 'kind' => 'company', 'legal_name' => 'Co']);
        DB::table('billing_parties')->insert(['id' => 2, 'kind' => 'dealer', 'dealer_id' => 1, 'legal_name' => 'D']);
    }

    private function invoice(): int
    {
        return DB::table('invoices')->insertGetId([
            'number' => 'SL-INV-2026-000001', 'issuer_party_id' => 1, 'buyer_party_id' => 2, 'buyer_dealer_id' => 1,
            'issue_date' => '2026-10-22', 'due_date' => '2026-11-21', 'subtotal' => 100, 'tax_amount' => 0, 'total' => 100,
            'snapshot' => '{}', 'snapshot_sha256' => str_repeat('a', 64),
        ]);
    }

    private function receipt(string $status = 'pending'): int
    {
        return DB::table('payment_receipts')->insertGetId([
            'number' => 'SL-RCT-2026-' . random_int(100000, 999999), 'issuer_party_id' => 1, 'buyer_dealer_id' => 1,
            'amount' => 50, 'method' => 'cash', 'received_date' => '2026-10-22', 'status' => $status,
            'snapshot' => '{}', 'snapshot_sha256' => str_repeat('b', 64),
        ]);
    }

    private function refused(callable $fn, string $needle = ''): void
    {
        DB::beginTransaction();
        try {
            $fn();
            $this->fail('The database accepted a change it must refuse.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_issued_invoice_and_lines_cannot_be_updated_or_deleted(): void
    {
        $id = $this->invoice();
        DB::table('invoice_lines')->insert(['invoice_id' => $id, 'transfer_ledger_id' => 1, 'description' => 'x', 'device_category' => 'GT06', 'quantity' => 2, 'unit_price' => 50, 'line_total' => 100]);

        $this->refused(fn () => DB::table('invoices')->where('id', $id)->update(['notes' => 'x']), 'cannot be update');
        $this->refused(fn () => DB::table('invoices')->where('id', $id)->delete(), 'cannot be delete');
        $this->refused(fn () => DB::table('invoice_lines')->update(['quantity' => 9]), 'cannot be update');
        $this->refused(fn () => DB::table('invoice_lines')->delete(), 'cannot be delete');
    }

    public function test_check_constraints(): void
    {
        $id = $this->invoice();
        $line = ['invoice_id' => $id, 'transfer_ledger_id' => 1, 'description' => 'x', 'device_category' => 'GT06'];
        $this->refused(fn () => DB::table('invoice_lines')->insert($line + ['quantity' => 0, 'unit_price' => 1, 'line_total' => 0]), 'invoice_lines_ok');
        $this->refused(fn () => DB::table('invoice_lines')->insert($line + ['quantity' => 2, 'unit_price' => 5, 'line_total' => 11]), 'invoice_lines_ok');
        $this->refused(fn () => DB::table('invoices')->insert([
            'number' => 'X', 'issuer_party_id' => 1, 'buyer_party_id' => 2, 'buyer_dealer_id' => 1, 'issue_date' => '2026-10-22', 'due_date' => '2026-10-22',
            'subtotal' => 100, 'tax_amount' => 10, 'total' => 100, 'snapshot' => '{}', 'snapshot_sha256' => str_repeat('a', 64),
        ]), 'invoices_amounts_ok');
        $this->refused(fn () => DB::table('payment_receipts')->insert([
            'number' => 'Y', 'issuer_party_id' => 1, 'buyer_dealer_id' => 1, 'amount' => 0, 'method' => 'cash', 'received_date' => '2026-10-22',
            'status' => 'valid', 'snapshot' => '{}', 'snapshot_sha256' => str_repeat('b', 64),
        ]), 'payment_receipts_ok');
    }

    public function test_receipts_only_move_status_along_allowed_paths_and_never_vanish(): void
    {
        $r = $this->receipt('pending');

        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->delete(), 'cannot be deleted');
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['amount' => 1]), 'Only the status');
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['reference' => 'changed']), 'Only the status');
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['status' => 'reversed']), 'cannot move');

        DB::table('payment_receipts')->where('id', $r)->update(['status' => 'valid', 'approved_by' => 'a']);       // allowed
        DB::table('payment_receipts')->where('id', $r)->update(['status' => 'reversed', 'reversal_reason' => 'x']); // allowed
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['status' => 'valid']), 'cannot move');
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $r)->update(['reversal_reason' => 'edited']), 'final');

        $rej = $this->receipt('pending');
        DB::table('payment_receipts')->where('id', $rej)->update(['status' => 'rejected', 'rejection_reason' => 'no']);
        $this->refused(fn () => DB::table('payment_receipts')->where('id', $rej)->update(['status' => 'valid']), 'cannot move');
    }

    public function test_allocations_and_credit_notes_are_append_only(): void
    {
        $inv = $this->invoice();
        $r = $this->receipt('valid');
        DB::table('payment_allocations')->insert(['receipt_id' => $r, 'invoice_id' => $inv, 'amount' => 10]);
        $this->refused(fn () => DB::table('payment_allocations')->delete(), 'cannot be delete');
        $this->refused(fn () => DB::table('payment_allocations')->update(['amount' => 1]), 'cannot be update');

        $cn = DB::table('credit_notes')->insertGetId([
            'number' => 'SL-CRN-2026-000001', 'invoice_id' => $inv, 'issuer_party_id' => 1, 'buyer_dealer_id' => 1, 'issue_date' => '2026-10-22',
            'subtotal' => 10, 'tax_amount' => 0, 'total' => 10, 'reason' => 'r', 'snapshot' => '{}', 'snapshot_sha256' => str_repeat('c', 64),
        ]);
        $this->refused(fn () => DB::table('credit_notes')->where('id', $cn)->delete(), 'cannot be delete');
    }

    public function test_down_removes_the_guards(): void
    {
        $id = $this->invoice();
        (include database_path('migrations/2026_10_22_000002_add_postgres_guards_to_billing_tables.php'))->down();
        DB::table('invoices')->where('id', $id)->update(['notes' => 'now allowed']);
        $this->assertSame('now allowed', DB::table('invoices')->where('id', $id)->value('notes'));
    }
}
