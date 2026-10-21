<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wholesale billing: what Shalotrack charges a distributor or dealer for DEVICES it sends them.
     * (Separate from package_payments / commission_entries, which are about customer subscriptions.)
     *
     * Everything here is append-only evidence:
     *  - invoices / credit notes / receipts are never edited or deleted after they exist (a mistake is
     *    corrected by a credit note and a new invoice; a wrong receipt is reversed, never removed),
     *  - every document keeps a frozen JSON snapshot of what was printed, its SHA-256 and the hash of the
     *    previous document of the same series, so an altered or removed row can be detected,
     *  - money is decimal(14,2) LKR; the application does the arithmetic in integer cents,
     *  - foreign keys are RESTRICT so no parent delete can wipe billing history.
     * The matching database guards (triggers, CHECK constraints) are in the next migration, PostgreSQL only.
     */
    public function up(): void
    {
        // Who appears on a document. One row for the company (dealer_id null), one per distributor/dealer.
        Schema::create('billing_parties', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);                         // company | dealer
            $table->foreignId('dealer_id')->nullable()->unique()->constrained('dealers')->restrictOnDelete();
            $table->string('legal_name', 200);
            $table->text('address')->nullable();
            $table->string('tax_number', 60)->nullable();       // VAT / TIN / business registration
            $table->string('phone', 40)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('bank_details')->nullable();           // company only: printed on invoices
            $table->timestamps();
        });

        // Switches and defaults. Every change is written to the audit log.
        Schema::create('billing_settings', function (Blueprint $table) {
            $table->string('key', 40)->primary();
            $table->text('value')->nullable();
            $table->string('updated_by', 64)->nullable();
            $table->timestamps();
        });

        // Gapless numbering: one row per issuer, document type and year, locked while a number is taken.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issuer_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->string('doc_type', 3);                      // INV | RCT | CRN
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['issuer_party_id', 'doc_type', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();             // SL-INV-2026-000001
            $table->foreignId('issuer_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->foreignId('buyer_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->foreignId('buyer_dealer_id')->constrained('dealers')->restrictOnDelete();
            $table->date('issue_date');                         // Sri Lanka calendar day
            $table->date('due_date');
            $table->string('currency', 3)->default('LKR');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_rate', 5, 2)->nullable();      // null = no tax on this invoice
            $table->string('tax_label', 30)->nullable();
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->text('notes')->nullable();
            $table->string('zero_reason', 255)->nullable();     // required when total is 0
            $table->string('issued_by', 64)->nullable();
            $table->string('issued_by_name', 100)->nullable();
            $table->json('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['buyer_dealer_id', 'issue_date']);
            $table->index('due_date');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('transfer_ledger_id')->constrained('dealer_transfer_ledgers')->restrictOnDelete();
            $table->string('description', 200);
            $table->string('device_category', 100);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamp('created_at')->nullable();

            $table->index('invoice_id');
            $table->index('transfer_ledger_id');
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();             // SL-CRN-2026-000001
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('issuer_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->foreignId('buyer_dealer_id')->constrained('dealers')->restrictOnDelete();
            $table->date('issue_date');
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('reason', 255);
            $table->string('issued_by', 64)->nullable();
            $table->string('issued_by_name', 100)->nullable();
            $table->json('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['buyer_dealer_id', 'issue_date']);
            $table->index('invoice_id');
        });

        Schema::create('credit_note_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->restrictOnDelete();
            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamp('created_at')->nullable();

            $table->index('credit_note_id');
            $table->index('invoice_line_id');
        });

        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();             // SL-RCT-2026-000001
            $table->foreignId('issuer_party_id')->constrained('billing_parties')->restrictOnDelete();
            $table->foreignId('buyer_dealer_id')->constrained('dealers')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method', 20);                       // bank_transfer | cash | cheque | online
            $table->string('reference', 120)->nullable();
            $table->date('received_date');
            $table->foreignId('target_invoice_id')->nullable()->constrained('invoices')->restrictOnDelete();
            $table->text('notes')->nullable();
            // pending (waiting for approval) | valid | rejected | reversed
            $table->string('status', 10);
            $table->string('recorded_by', 64)->nullable();
            $table->string('recorded_by_name', 100)->nullable();
            $table->string('approved_by', 64)->nullable();
            $table->string('approved_by_name', 100)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversed_by', 64)->nullable();
            $table->string('reversal_reason', 255)->nullable();
            $table->json('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->char('previous_hash', 64)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamp('created_at')->nullable();

            $table->index(['buyer_dealer_id', 'status']);
        });

        // How a valid receipt was spread over invoices. Counts only while its receipt is valid.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained('payment_receipts')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamp('created_at')->nullable();

            $table->unique(['receipt_id', 'invoice_id']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        foreach ([
            'payment_allocations', 'payment_receipts', 'credit_note_lines', 'credit_notes',
            'invoice_lines', 'invoices', 'document_sequences', 'billing_settings', 'billing_parties',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
