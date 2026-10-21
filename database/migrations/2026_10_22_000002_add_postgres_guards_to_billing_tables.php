<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Database-level protection for the billing tables (PostgreSQL only; skipped on the sqlite test DB,
     * where the same rules are enforced and tested in App\Services\Billing).
     *
     *  1. CHECK constraints: amounts and quantities make sense and totals add up.
     *  2. Invoices, credit notes, their lines and allocations can never be UPDATED or DELETED.
     *  3. Receipts can never be deleted. Only their status may move (pending > valid | rejected, valid >
     *     reversed) together with the approval / reversal columns; every other column is frozen.
     *
     * Even a SQL console session or a future careless code path cannot rewrite what was issued.
     * Run it on the staging database first. A superuser can still drop a trigger on purpose; that is a
     * deliberate, visible act, unlike an accidental UPDATE.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION billing_forbid_change() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Issued billing record in % cannot be % (correct it with a credit note or a reversal)',
        TG_TABLE_NAME, lower(TG_OP) USING ERRCODE = 'integrity_constraint_violation';
END;
$$ LANGUAGE plpgsql;
SQL);

        foreach (['invoices', 'invoice_lines', 'credit_notes', 'credit_note_lines', 'payment_allocations'] as $t) {
            DB::unprepared("CREATE TRIGGER {$t}_immutable BEFORE UPDATE OR DELETE ON {$t}
                            FOR EACH ROW EXECUTE FUNCTION billing_forbid_change()");
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION billing_guard_receipt() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'A payment receipt cannot be deleted (reverse it instead)'
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    IF (OLD.id, OLD.number, OLD.issuer_party_id, OLD.buyer_dealer_id, OLD.amount, OLD.method, OLD.reference,
        OLD.received_date, OLD.target_invoice_id, OLD.notes, OLD.recorded_by, OLD.recorded_by_name,
        OLD.snapshot::text, OLD.snapshot_sha256, OLD.previous_hash, OLD.idempotency_key, OLD.created_at)
       IS DISTINCT FROM
       (NEW.id, NEW.number, NEW.issuer_party_id, NEW.buyer_dealer_id, NEW.amount, NEW.method, NEW.reference,
        NEW.received_date, NEW.target_invoice_id, NEW.notes, NEW.recorded_by, NEW.recorded_by_name,
        NEW.snapshot::text, NEW.snapshot_sha256, NEW.previous_hash, NEW.idempotency_key, NEW.created_at)
    THEN
        RAISE EXCEPTION 'Only the status of a payment receipt can change'
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
           (OLD.status = 'pending' AND NEW.status IN ('valid', 'rejected'))
        OR (OLD.status = 'valid'   AND NEW.status = 'reversed')
    ) THEN
        RAISE EXCEPTION 'Receipt status cannot move from % to %', OLD.status, NEW.status
            USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    -- once reversed or rejected the row is final
    IF OLD.status IN ('reversed', 'rejected') AND (
        OLD.status, OLD.approved_by, OLD.approved_by_name, OLD.approved_at, OLD.rejection_reason,
        OLD.reversed_at, OLD.reversed_by, OLD.reversal_reason)
       IS DISTINCT FROM (
        NEW.status, NEW.approved_by, NEW.approved_by_name, NEW.approved_at, NEW.rejection_reason,
        NEW.reversed_at, NEW.reversed_by, NEW.reversal_reason) THEN
        RAISE EXCEPTION 'A % receipt is final', OLD.status USING ERRCODE = 'integrity_constraint_violation';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared('CREATE TRIGGER payment_receipts_guard BEFORE UPDATE OR DELETE ON payment_receipts
                        FOR EACH ROW EXECUTE FUNCTION billing_guard_receipt()');

        $checks = [
            ['invoices', 'invoices_amounts_ok', 'subtotal >= 0 AND tax_amount >= 0 AND total = subtotal + tax_amount'],
            ['invoice_lines', 'invoice_lines_ok', 'quantity > 0 AND unit_price >= 0 AND line_total = quantity * unit_price'],
            ['credit_notes', 'credit_notes_amounts_ok', 'subtotal > 0 AND tax_amount >= 0 AND total = subtotal + tax_amount'],
            ['credit_note_lines', 'credit_note_lines_ok', 'quantity > 0 AND unit_price >= 0 AND line_total = quantity * unit_price'],
            ['payment_receipts', 'payment_receipts_ok', "amount > 0 AND status IN ('pending','valid','rejected','reversed') AND method IN ('bank_transfer','cash','cheque','online')"],
            ['payment_allocations', 'payment_allocations_ok', 'amount > 0'],
            ['document_sequences', 'document_sequences_ok', "doc_type IN ('INV','RCT','CRN') AND last_number >= 0"],
        ];
        foreach ($checks as [$table, $name, $rule]) {
            DB::unprepared("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$rule})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS payment_receipts_guard ON payment_receipts');
        foreach (['invoices', 'invoice_lines', 'credit_notes', 'credit_note_lines', 'payment_allocations'] as $t) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$t}_immutable ON {$t}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS billing_guard_receipt()');
        DB::unprepared('DROP FUNCTION IF EXISTS billing_forbid_change()');

        foreach ([
            ['invoices', 'invoices_amounts_ok'], ['invoice_lines', 'invoice_lines_ok'],
            ['credit_notes', 'credit_notes_amounts_ok'], ['credit_note_lines', 'credit_note_lines_ok'],
            ['payment_receipts', 'payment_receipts_ok'], ['payment_allocations', 'payment_allocations_ok'],
            ['document_sequences', 'document_sequences_ok'],
        ] as [$table, $name]) {
            DB::unprepared("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
