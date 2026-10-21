<?php

namespace App\Services\Billing;

use App\Models\BillingParty;
use App\Models\CreditNote;
use App\Models\CreditNoteLine;
use App\Models\Dealer;
use App\Models\DealerTransferLedger;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\PaymentAllocation;
use App\Models\PaymentReceipt;
use App\Services\Audit;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place that writes billing tables. Every public write runs in one transaction that first locks the
 * issuer (company) row, so numbers are gapless, the hash chain cannot fork and two simultaneous payments
 * cannot both pass the "not more than owed" check.
 *
 * Rules in short:
 *  - Shalotrack (the company party) issues every document; the buyer is the dealer/distributor that RECEIVED
 *    the devices of a transfer.
 *  - An invoice is built from whole transfers (ledgers); the admin sets a unit price per transfer. A transfer
 *    is billed once; it can be billed again only after a credit note takes all of it back.
 *  - Issued documents are never changed. Mistakes: credit note (money) / reversal (receipt).
 *  - Money is integer cents inside, decimal(14,2) in the database.
 */
class Billing
{
    public const TZ = 'Asia/Colombo';

    public function __construct(private BillingSettings $settings)
    {
    }

    // ---------------------------------------------------------------- parties

    public function company(): BillingParty
    {
        $company = BillingParty::where('kind', BillingParty::KIND_COMPANY)->first();
        if (! $company || trim((string) $company->legal_name) === '' || trim((string) $company->address) === '') {
            throw new DomainException('Set up the company details (legal name and address) in Billing > Settings before issuing documents.');
        }

        return $company;
    }

    /** Party row for a dealer/distributor, created from the dealer record the first time it is needed. */
    public function partyFor(Dealer $dealer): BillingParty
    {
        return BillingParty::firstOrCreate(
            ['dealer_id' => $dealer->id],
            [
                'kind'       => BillingParty::KIND_DEALER,
                'legal_name' => (string) $dealer->full_name,
                'address'    => trim(implode(', ', array_filter([$dealer->region ? ucwords(str_replace('_', ' ', $dealer->region)) : null, $dealer->country, $dealer->pin_code]))) ?: null,
                'tax_number' => $dealer->vat_tin ?: ($dealer->tax_pan ?: null),
                'email'      => $dealer->contact_email,
            ]
        );
    }

    // ---------------------------------------------------------------- transfers to bill

    /** Transfers (not hidden) whose devices are not covered by a live invoice. */
    public function unbilledTransfers(?int $buyerDealerId = null)
    {
        return DealerTransferLedger::query()
            ->whereRaw($this->billedQuantitySql() . ' <= 0')
            ->when($buyerDealerId, fn ($q) => $q->where('dealer_transfer_ledgers.dealer_id', $buyerDealerId));
    }

    public function isBilled(DealerTransferLedger $ledger): bool
    {
        return $this->billedQuantity($ledger->id) > 0;
    }

    public function billedQuantity(int $ledgerId): int
    {
        $invoiced = (int) DB::table('invoice_lines')->where('transfer_ledger_id', $ledgerId)->sum('quantity');
        $credited = (int) DB::table('credit_note_lines as c')
            ->join('invoice_lines as i', 'i.id', '=', 'c.invoice_line_id')
            ->where('i.transfer_ledger_id', $ledgerId)
            ->sum('c.quantity');

        return $invoiced - $credited;
    }

    private function billedQuantitySql(): string
    {
        return '(coalesce((select sum(il.quantity) from invoice_lines il where il.transfer_ledger_id = dealer_transfer_ledgers.id), 0)'
            . ' - coalesce((select sum(cl.quantity) from credit_note_lines cl join invoice_lines il2 on il2.id = cl.invoice_line_id'
            . ' where il2.transfer_ledger_id = dealer_transfer_ledgers.id), 0))';
    }

    // ---------------------------------------------------------------- invoices

    /**
     * Totals for the review screen and for issuing. Nothing is written.
     *
     * @param  array<int|string,string> $prices ledger id => unit price ("1250.00")
     * @return array{lines: array<int,array>, subtotal:int, tax_rate:?string, tax_label:?string, tax:int, total:int}
     */
    public function quote(Dealer $buyer, array $prices): array
    {
        if ($prices === []) {
            throw new DomainException('Select at least one transfer to invoice.');
        }

        $ledgers = DealerTransferLedger::query()
            ->whereIn('id', array_map('intval', array_keys($prices)))
            ->with(['items', 'devices'])
            ->get()->keyBy('id');

        $lines = [];
        $subtotal = 0;
        foreach ($prices as $ledgerId => $price) {
            $ledger = $ledgers->get((int) $ledgerId);
            if (! $ledger || (int) $ledger->dealer_id !== (int) $buyer->id) {
                throw new DomainException('One of the selected transfers does not belong to this buyer.');
            }
            if ($this->isBilled($ledger)) {
                throw new DomainException('Transfer TR-' . str_pad((string) $ledger->id, 6, '0', STR_PAD_LEFT) . ' is already on an invoice.');
            }
            try {
                $unit = Money::toCents($price);
            } catch (\InvalidArgumentException) {
                throw new DomainException('Enter a valid price (numbers only, up to 2 decimals) for every selected transfer.');
            }
            $total = $unit * (int) $ledger->quantity;
            $subtotal += $total;

            $imeis = $ledger->items->isNotEmpty()
                ? $ledger->items->map(fn ($i) => ['imei' => $i->imei_number, 'sim' => $i->sim_number])->all()
                : $ledger->devices->map(fn ($d) => ['imei' => $d->imei_number ?? null, 'sim' => $d->sim_number ?? null])->all();

            $lines[] = [
                'ledger_id'   => (int) $ledger->id,
                'reference'   => 'TR-' . str_pad((string) $ledger->id, 6, '0', STR_PAD_LEFT),
                'transferred' => $ledger->created_at?->clone()->setTimezone(self::TZ)->format('Y-m-d H:i'),
                'category'    => (string) $ledger->device_category,
                'quantity'    => (int) $ledger->quantity,
                'unit_price'  => $unit,
                'line_total'  => $total,
                'devices'     => $imeis,
            ];
        }

        $rate = $this->settings->taxEnabled() ? Money::fromCents(Money::toCents($this->settings->get('tax_rate'))) : null;
        $tax = $rate !== null ? Money::percentOf($subtotal, $rate) : 0;

        return [
            'lines'     => $lines,
            'subtotal'  => $subtotal,
            'tax_rate'  => $rate,
            'tax_label' => $rate !== null ? $this->settings->get('tax_label') : null,
            'tax'       => $tax,
            'total'     => $subtotal + $tax,
        ];
    }

    public function issueInvoice(
        Dealer $buyer,
        array $prices,
        ?string $notes = null,
        ?string $zeroReason = null,
        ?string $idempotencyKey = null,
    ): Invoice {
        if ($idempotencyKey && ($existing = Invoice::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }

        $invoice = DB::transaction(function () use ($buyer, $prices, $notes, $zeroReason, $idempotencyKey) {
            $company = $this->lockCompany();
            $buyerParty = $this->partyFor($buyer);

            $quote = $this->quote($buyer, $prices);
            if ($quote['total'] === 0 && trim((string) $zeroReason) === '') {
                throw new DomainException('A zero-value invoice needs a written reason (for example: replacement, demo).');
            }

            $now = Carbon::now(self::TZ);
            $issueDate = $now->toDateString();
            $dueDate = $now->clone()->addDays($this->settings->termsDays())->toDateString();
            $number = $this->nextNumber($company, 'INV', (int) $now->format('Y'));
            $previous = $this->previousHash('invoices', $company->id);
            $user = auth()->user();

            $snapshot = [
                'type'        => 'invoice',
                'number'      => $number,
                'issue_date'  => $issueDate,
                'due_date'    => $dueDate,
                'currency'    => 'LKR',
                'issuer'      => $company->toSnapshot(),
                'buyer'       => $buyerParty->toSnapshot(),
                'lines'       => $quote['lines'],
                'subtotal'    => $quote['subtotal'],
                'tax_rate'    => $quote['tax_rate'],
                'tax_label'   => $quote['tax_label'],
                'tax'         => $quote['tax'],
                'total'       => $quote['total'],
                'notes'       => $notes,
                'zero_reason' => $quote['total'] === 0 ? $zeroReason : null,
                'footer'      => $this->settings->get('invoice_footer'),
                'issued_by'   => $user?->full_name ?: $user?->username,
            ];

            $invoice = Invoice::create([
                'number'          => $number,
                'issuer_party_id' => $company->id,
                'buyer_party_id'  => $buyerParty->id,
                'buyer_dealer_id' => $buyer->id,
                'issue_date'      => $issueDate,
                'due_date'        => $dueDate,
                'currency'        => 'LKR',
                'subtotal'        => Money::fromCents($quote['subtotal']),
                'tax_rate'        => $quote['tax_rate'],
                'tax_label'       => $quote['tax_label'],
                'tax_amount'      => Money::fromCents($quote['tax']),
                'total'           => Money::fromCents($quote['total']),
                'notes'           => $notes,
                'zero_reason'     => $snapshot['zero_reason'],
                'issued_by'       => $user?->admin_id,
                'issued_by_name'  => $user ? mb_substr((string) ($user->full_name ?: $user->username), 0, 100) : null,
                'snapshot'        => $snapshot,
                'snapshot_sha256' => self::hash($previous, $snapshot),
                'previous_hash'   => $previous,
                'idempotency_key' => $idempotencyKey,
                'created_at'      => now(),
            ]);

            foreach ($quote['lines'] as $line) {
                InvoiceLine::create([
                    'invoice_id'         => $invoice->id,
                    'transfer_ledger_id' => $line['ledger_id'],
                    'description'        => mb_substr($line['category'] . ' (' . $line['reference'] . ')', 0, 200),
                    'device_category'    => mb_substr($line['category'], 0, 100),
                    'quantity'           => $line['quantity'],
                    'unit_price'         => Money::fromCents($line['unit_price']),
                    'line_total'         => Money::fromCents($line['line_total']),
                    'created_at'         => now(),
                ]);
            }

            return $invoice;
        });

        Audit::record('invoice.issued', 'invoice', $invoice->id, $invoice->number, [], [
            'buyer' => $buyer->full_name, 'buyer_dealer_id' => $buyer->id, 'total' => (string) $invoice->total,
            'transfers' => array_map('intval', array_keys($prices)),
        ]);

        return $invoice;
    }

    // ---------------------------------------------------------------- credit notes

    /** Quantity of each invoice line that can still be credited: line id => qty. */
    public function creditableLines(Invoice $invoice): array
    {
        $credited = DB::table('credit_note_lines')
            ->whereIn('invoice_line_id', $invoice->lines()->pluck('id'))
            ->selectRaw('invoice_line_id, sum(quantity) as q')->groupBy('invoice_line_id')
            ->pluck('q', 'invoice_line_id');

        $out = [];
        foreach ($invoice->lines as $line) {
            $out[$line->id] = (int) $line->quantity - (int) ($credited[$line->id] ?? 0);
        }

        return $out;
    }

    /** @param array<int|string,int|string> $quantities invoice line id => quantity to credit */
    public function issueCreditNote(Invoice $invoice, array $quantities, string $reason, ?string $idempotencyKey = null): CreditNote
    {
        if ($idempotencyKey && ($existing = CreditNote::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('A credit note needs a reason.');
        }

        $note = DB::transaction(function () use ($invoice, $quantities, $reason, $idempotencyKey) {
            $company = $this->lockCompany();
            $invoice = Invoice::with('lines')->findOrFail($invoice->id);
            $creditable = $this->creditableLines($invoice);

            $lines = [];
            $subtotal = 0;
            foreach ($quantities as $lineId => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $line = $invoice->lines->firstWhere('id', (int) $lineId);
                if (! $line || $qty > ($creditable[$line->id] ?? 0)) {
                    throw new DomainException('You cannot credit more than was invoiced and not yet credited.');
                }
                $unit = Money::toCents((string) $line->unit_price);
                $lines[] = ['line' => $line, 'qty' => $qty, 'unit' => $unit, 'total' => $unit * $qty];
                $subtotal += $unit * $qty;
            }
            if ($lines === []) {
                throw new DomainException('Choose at least one device quantity to credit.');
            }

            $tax = 0;
            if ($invoice->tax_rate !== null && (float) $invoice->tax_rate > 0) {
                $remainingAfter = 0;
                foreach ($invoice->lines as $l) {
                    $took = collect($lines)->firstWhere(fn ($x) => $x['line']->id === $l->id)['qty'] ?? 0;
                    $remainingAfter += $creditable[$l->id] - $took;
                }
                $taxCreditedBefore = (int) round(((float) DB::table('credit_notes')->where('invoice_id', $invoice->id)->sum('tax_amount')) * 100);
                $tax = $remainingAfter === 0
                    ? Money::toCents((string) $invoice->tax_amount) - $taxCreditedBefore   // last credit takes the exact rest
                    : Money::percentOf($subtotal, (string) $invoice->tax_rate);
            }
            $total = $subtotal + $tax;

            $now = Carbon::now(self::TZ);
            $number = $this->nextNumber($company, 'CRN', (int) $now->format('Y'));
            $previous = $this->previousHash('credit_notes', $company->id);
            $user = auth()->user();

            $snapshot = [
                'type'       => 'credit_note',
                'number'     => $number,
                'issue_date' => $now->toDateString(),
                'currency'   => 'LKR',
                'invoice'    => $invoice->number,
                'issuer'     => $invoice->snapshot['issuer'] ?? null,
                'buyer'      => $invoice->snapshot['buyer'] ?? null,
                'lines'      => array_map(fn ($x) => [
                    'invoice_line_id' => $x['line']->id,
                    'description'     => $x['line']->description,
                    'quantity'        => $x['qty'],
                    'unit_price'      => $x['unit'],
                    'line_total'      => $x['total'],
                ], $lines),
                'subtotal'   => $subtotal,
                'tax'        => $tax,
                'total'      => $total,
                'reason'     => $reason,
                'issued_by'  => $user?->full_name ?: $user?->username,
            ];

            $note = CreditNote::create([
                'number'          => $number,
                'invoice_id'      => $invoice->id,
                'issuer_party_id' => $company->id,
                'buyer_dealer_id' => $invoice->buyer_dealer_id,
                'issue_date'      => $now->toDateString(),
                'subtotal'        => Money::fromCents($subtotal),
                'tax_amount'      => Money::fromCents($tax),
                'total'           => Money::fromCents($total),
                'reason'          => mb_substr($reason, 0, 255),
                'issued_by'       => $user?->admin_id,
                'issued_by_name'  => $user ? mb_substr((string) ($user->full_name ?: $user->username), 0, 100) : null,
                'snapshot'        => $snapshot,
                'snapshot_sha256' => self::hash($previous, $snapshot),
                'previous_hash'   => $previous,
                'idempotency_key' => $idempotencyKey,
                'created_at'      => now(),
            ]);

            foreach ($lines as $x) {
                CreditNoteLine::create([
                    'credit_note_id'  => $note->id,
                    'invoice_line_id' => $x['line']->id,
                    'quantity'        => $x['qty'],
                    'unit_price'      => Money::fromCents($x['unit']),
                    'line_total'      => Money::fromCents($x['total']),
                    'created_at'      => now(),
                ]);
            }

            return $note;
        });

        Audit::record('credit_note.issued', 'credit_note', $note->id, $note->number, [], [
            'invoice' => $invoice->number, 'total' => (string) $note->total, 'reason' => $reason,
        ]);

        return $note;
    }

    // ---------------------------------------------------------------- receipts

    public function recordReceipt(
        Dealer $buyer,
        string $amount,
        string $method,
        ?string $reference,
        string $receivedDate,
        ?int $targetInvoiceId = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
    ): PaymentReceipt {
        if ($idempotencyKey && ($existing = PaymentReceipt::where('idempotency_key', $idempotencyKey)->first())) {
            return $existing;
        }
        if (! isset(PaymentReceipt::METHODS[$method])) {
            throw new DomainException('Choose a payment method.');
        }
        try {
            $cents = Money::toCents($amount);
        } catch (\InvalidArgumentException) {
            throw new DomainException('Enter a valid amount (numbers only, up to 2 decimals).');
        }
        if ($cents <= 0) {
            throw new DomainException('The amount must be more than zero.');
        }
        $reference = $reference !== null ? trim($reference) : null;
        if ($method !== 'cash' && ($reference === null || $reference === '')) {
            throw new DomainException('Enter the bank / cheque / transaction reference for this payment.');
        }
        $received = Carbon::parse($receivedDate, self::TZ)->startOfDay();
        if ($received->gt(Carbon::now(self::TZ)->endOfDay())) {
            throw new DomainException('The received date cannot be in the future.');
        }

        $receipt = DB::transaction(function () use ($buyer, $cents, $method, $reference, $received, $targetInvoiceId, $notes, $idempotencyKey) {
            $company = $this->lockCompany();

            $owed = $this->outstandingTotal($buyer->id) - $this->pendingTotal($buyer->id);
            if ($cents > $owed) {
                throw new DomainException('This payment (' . Money::format($cents) . ') is more than the buyer currently owes (' . Money::format(max(0, $owed)) . '). Check the amount.');
            }
            if ($targetInvoiceId) {
                $target = Invoice::where('buyer_dealer_id', $buyer->id)->find($targetInvoiceId);
                if (! $target) {
                    throw new DomainException('That invoice does not belong to this buyer.');
                }
                if ($cents > $this->invoiceOutstanding($target)) {
                    throw new DomainException('This payment is more than the selected invoice still owes.');
                }
            }

            $approval = $this->settings->approvalRequired();
            $now = Carbon::now(self::TZ);
            $number = $this->nextNumber($company, 'RCT', (int) $now->format('Y'));
            $previous = $this->previousHash('payment_receipts', $company->id);
            $user = auth()->user();
            $buyerParty = $this->partyFor($buyer);

            $snapshot = [
                'type'          => 'receipt',
                'number'        => $number,
                'currency'      => 'LKR',
                'issuer'        => $company->toSnapshot(),
                'payer'         => $buyerParty->toSnapshot(),
                'amount'        => $cents,
                'method'        => $method,
                'reference'     => $reference,
                'received_date' => $received->toDateString(),
                'invoice_id'    => $targetInvoiceId,
                'notes'         => $notes,
                'recorded_by'   => $user?->full_name ?: $user?->username,
                'recorded_at'   => $now->format('Y-m-d H:i'),
            ];

            $receipt = PaymentReceipt::create([
                'number'           => $number,
                'issuer_party_id'  => $company->id,
                'buyer_dealer_id'  => $buyer->id,
                'amount'           => Money::fromCents($cents),
                'method'           => $method,
                'reference'        => $reference !== null ? mb_substr($reference, 0, 120) : null,
                'received_date'    => $received->toDateString(),
                'target_invoice_id' => $targetInvoiceId,
                'notes'            => $notes,
                'status'           => $approval ? PaymentReceipt::PENDING : PaymentReceipt::VALID,
                'recorded_by'      => $user?->admin_id,
                'recorded_by_name' => $user ? mb_substr((string) ($user->full_name ?: $user->username), 0, 100) : null,
                'snapshot'         => $snapshot,
                'snapshot_sha256'  => self::hash($previous, $snapshot),
                'previous_hash'    => $previous,
                'idempotency_key'  => $idempotencyKey,
                'created_at'       => now(),
            ]);

            if (! $approval) {
                $this->allocate($receipt);
            }

            return $receipt;
        });

        Audit::record('receipt.recorded', 'receipt', $receipt->id, $receipt->number, [], [
            'buyer' => $buyer->full_name, 'buyer_dealer_id' => $buyer->id, 'amount' => (string) $receipt->amount,
            'method' => $method, 'status' => $receipt->status,
        ]);

        return $receipt;
    }

    public function approveReceipt(PaymentReceipt $receipt): PaymentReceipt
    {
        $user = auth()->user();

        DB::transaction(function () use ($receipt, $user) {
            $this->lockCompany();
            $fresh = PaymentReceipt::findOrFail($receipt->id);
            if ($fresh->status !== PaymentReceipt::PENDING) {
                throw new DomainException('This receipt is no longer waiting for approval.');
            }
            if ($user && (string) $fresh->recorded_by === (string) $user->admin_id) {
                throw new DomainException('You recorded this payment, so someone else must approve it.');
            }
            $this->allocate($fresh);   // refuses when the buyer no longer owes this much
            $fresh->update([
                'status'           => PaymentReceipt::VALID,
                'approved_by'      => $user?->admin_id,
                'approved_by_name' => $user ? mb_substr((string) ($user->full_name ?: $user->username), 0, 100) : null,
                'approved_at'      => now(),
            ]);
            $receipt->setRawAttributes($fresh->getAttributes(), true);
        });

        Audit::record('receipt.approved', 'receipt', $receipt->id, $receipt->number, [], ['amount' => (string) $receipt->amount]);

        return $receipt;
    }

    public function rejectReceipt(PaymentReceipt $receipt, string $reason): PaymentReceipt
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Give a reason for rejecting this payment.');
        }
        $user = auth()->user();

        DB::transaction(function () use ($receipt, $reason, $user) {
            $this->lockCompany();
            $fresh = PaymentReceipt::findOrFail($receipt->id);
            if ($fresh->status !== PaymentReceipt::PENDING) {
                throw new DomainException('This receipt is no longer waiting for approval.');
            }
            if ($user && (string) $fresh->recorded_by === (string) $user->admin_id) {
                throw new DomainException('You recorded this payment, so someone else must decide on it.');
            }
            $fresh->update([
                'status'           => PaymentReceipt::REJECTED,
                'rejection_reason' => mb_substr($reason, 0, 255),
                'approved_by'      => $user?->admin_id,
                'approved_by_name' => $user ? mb_substr((string) ($user->full_name ?: $user->username), 0, 100) : null,
                'approved_at'      => now(),
            ]);
            $receipt->setRawAttributes($fresh->getAttributes(), true);
        });

        Audit::record('receipt.rejected', 'receipt', $receipt->id, $receipt->number, [], ['reason' => $reason]);

        return $receipt;
    }

    public function reverseReceipt(PaymentReceipt $receipt, string $reason): PaymentReceipt
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Give a reason for reversing this payment.');
        }
        $user = auth()->user();

        DB::transaction(function () use ($receipt, $reason, $user) {
            $this->lockCompany();
            $fresh = PaymentReceipt::findOrFail($receipt->id);
            if ($fresh->status !== PaymentReceipt::VALID) {
                throw new DomainException('Only a valid receipt can be reversed.');
            }
            $fresh->update([
                'status'          => PaymentReceipt::REVERSED,
                'reversed_at'     => now(),
                'reversed_by'     => $user?->admin_id,
                'reversal_reason' => mb_substr($reason, 0, 255),
            ]);
            $receipt->setRawAttributes($fresh->getAttributes(), true);
        });

        Audit::record('receipt.reversed', 'receipt', $receipt->id, $receipt->number, [], ['amount' => (string) $receipt->amount, 'reason' => $reason]);

        return $receipt;
    }

    /** Spreads a receipt over the buyer's invoices: the chosen invoice first, then oldest due date first. */
    private function allocate(PaymentReceipt $receipt): void
    {
        $left = Money::toCents((string) $receipt->amount);

        $invoices = Invoice::where('buyer_dealer_id', $receipt->buyer_dealer_id)
            ->orderByRaw('case when id = ? then 0 else 1 end', [(int) $receipt->target_invoice_id])
            ->orderBy('due_date')->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            if ($left <= 0) {
                break;
            }
            $open = $this->invoiceOutstanding($invoice);
            if ($open <= 0) {
                continue;
            }
            $take = min($open, $left);
            PaymentAllocation::create([
                'receipt_id' => $receipt->id,
                'invoice_id' => $invoice->id,
                'amount'     => Money::fromCents($take),
                'created_at' => now(),
            ]);
            $left -= $take;
        }

        if ($left > 0) {
            throw new DomainException('The buyer no longer owes this much, so the payment cannot be applied. Check the amount.');
        }
    }

    // ---------------------------------------------------------------- balances (always derived)

    public function invoiceOutstanding(Invoice $invoice): int
    {
        $total = Money::toCents((string) $invoice->total);
        $credited = $this->cents(DB::table('credit_notes')->where('invoice_id', $invoice->id)->sum('total'));
        $paid = $this->cents(DB::table('payment_allocations as a')
            ->join('payment_receipts as r', 'r.id', '=', 'a.receipt_id')
            ->where('a.invoice_id', $invoice->id)->where('r.status', PaymentReceipt::VALID)->sum('a.amount'));

        return $total - $credited - $paid;
    }

    /** What the buyer owes in total (never negative here; credit balances show in account()). */
    public function outstandingTotal(int $buyerDealerId): int
    {
        return max(0, $this->account($buyerDealerId)['balance']);
    }

    public function pendingTotal(int $buyerDealerId): int
    {
        return $this->cents(PaymentReceipt::where('buyer_dealer_id', $buyerDealerId)->where('status', PaymentReceipt::PENDING)->sum('amount'));
    }

    /** @return array{invoiced:int, credited:int, paid:int, balance:int, overdue:int, pending:int} cents */
    public function account(int $buyerDealerId): array
    {
        $invoiced = $this->cents(DB::table('invoices')->where('buyer_dealer_id', $buyerDealerId)->sum('total'));
        $credited = $this->cents(DB::table('credit_notes')->where('buyer_dealer_id', $buyerDealerId)->sum('total'));
        $paid = $this->cents(DB::table('payment_receipts')->where('buyer_dealer_id', $buyerDealerId)->where('status', PaymentReceipt::VALID)->sum('amount'));

        $overdue = 0;
        $today = Carbon::now(self::TZ)->toDateString();
        foreach (Invoice::where('buyer_dealer_id', $buyerDealerId)->where('due_date', '<', $today)->get() as $inv) {
            $overdue += max(0, $this->invoiceOutstanding($inv));
        }

        return [
            'invoiced' => $invoiced,
            'credited' => $credited,
            'paid'     => $paid,
            'balance'  => $invoiced - $credited - $paid,
            'overdue'  => $overdue,
            'pending'  => $this->pendingTotal($buyerDealerId),
        ];
    }

    /**
     * Statement for a date range (Sri Lanka days, inclusive): opening balance, lines, closing balance.
     *
     * @return array{opening:int, closing:int, rows: array<int,array>}
     */
    public function statement(int $buyerDealerId, string $from, string $to): array
    {
        $events = [];
        foreach (Invoice::where('buyer_dealer_id', $buyerDealerId)->get() as $i) {
            $events[] = ['date' => $i->issue_date->toDateString(), 'ord' => 1, 'id' => $i->id, 'type' => 'Invoice', 'number' => $i->number, 'note' => '', 'amount' => Money::toCents((string) $i->total)];
        }
        foreach (CreditNote::where('buyer_dealer_id', $buyerDealerId)->get() as $c) {
            $events[] = ['date' => $c->issue_date->toDateString(), 'ord' => 2, 'id' => $c->id, 'type' => 'Credit note', 'number' => $c->number, 'note' => (string) $c->reason, 'amount' => -Money::toCents((string) $c->total)];
        }
        foreach (PaymentReceipt::where('buyer_dealer_id', $buyerDealerId)->whereIn('status', [PaymentReceipt::VALID, PaymentReceipt::REVERSED])->get() as $r) {
            $events[] = ['date' => $r->received_date->toDateString(), 'ord' => 3, 'id' => $r->id, 'type' => 'Payment', 'number' => $r->number, 'note' => PaymentReceipt::METHODS[$r->method] . ($r->reference ? ' · ' . $r->reference : ''), 'amount' => -Money::toCents((string) $r->amount)];
            if ($r->status === PaymentReceipt::REVERSED && $r->reversed_at) {
                $events[] = ['date' => $r->reversed_at->clone()->setTimezone(self::TZ)->toDateString(), 'ord' => 4, 'id' => $r->id, 'type' => 'Payment reversed', 'number' => $r->number, 'note' => (string) $r->reversal_reason, 'amount' => Money::toCents((string) $r->amount)];
            }
        }
        usort($events, fn ($a, $b) => [$a['date'], $a['ord'], $a['id']] <=> [$b['date'], $b['ord'], $b['id']]);

        $opening = 0;
        $rows = [];
        $running = 0;
        foreach ($events as $e) {
            if ($e['date'] < $from) {
                $opening += $e['amount'];
                continue;
            }
            if ($e['date'] > $to) {
                continue;
            }
            $running = ($rows === [] ? $opening : $running) + $e['amount'];
            $rows[] = $e + ['balance' => $running];
        }

        return ['opening' => $opening, 'closing' => $rows === [] ? $opening : $running, 'rows' => $rows];
    }

    // ---------------------------------------------------------------- integrity

    /**
     * Recomputes the whole chain. Returns a list of problems (empty = intact): changed snapshot, broken
     * link (a document removed or inserted), a gap in the numbers, totals that no longer match the snapshot.
     *
     * @return array<int,string>
     */
    public function verify(): array
    {
        $problems = [];
        $tables = ['invoices' => 'INV', 'credit_notes' => 'CRN', 'payment_receipts' => 'RCT'];

        foreach ($tables as $table => $code) {
            $prevByIssuer = [];
            $expectNo = [];
            foreach (DB::table($table)->orderBy('issuer_party_id')->orderBy('id')->cursor() as $row) {
                $snapshot = json_decode($row->snapshot, true);
                if (! is_array($snapshot)) {
                    $problems[] = "$row->number: snapshot unreadable";
                    continue;
                }
                if (self::hash($row->previous_hash, $snapshot) !== $row->snapshot_sha256) {
                    $problems[] = "$row->number: content does not match its stored hash (altered)";
                }
                if (($prevByIssuer[$row->issuer_party_id] ?? null) !== $row->previous_hash) {
                    $problems[] = "$row->number: chain link broken (a document before it was removed or inserted)";
                }
                $prevByIssuer[$row->issuer_party_id] = $row->snapshot_sha256;

                if (($snapshot['number'] ?? null) !== $row->number) {
                    $problems[] = "$row->number: number differs from its snapshot";
                }
                if (preg_match('/-(\d{4})-(\d{6})$/', $row->number, $m)) {
                    $key = $row->issuer_party_id . '|' . $m[1];
                    $expectNo[$key] = ($expectNo[$key] ?? 0) + 1;
                    if ((int) $m[2] !== $expectNo[$key]) {
                        $problems[] = "$row->number: numbering gap or reuse (expected " . $expectNo[$key] . ')';
                        $expectNo[$key] = (int) $m[2];
                    }
                }

                $problems = array_merge($problems, $this->columnProblems($table, $row, $snapshot));
            }
        }

        return $problems;
    }

    private function columnProblems(string $table, object $row, array $snapshot): array
    {
        $out = [];
        if ($table === 'invoices') {
            if ($this->cents($row->total) !== (int) ($snapshot['total'] ?? -1) || $this->cents($row->subtotal) !== (int) ($snapshot['subtotal'] ?? -1) || $this->cents($row->tax_amount) !== (int) ($snapshot['tax'] ?? -1)) {
                $out[] = "$row->number: stored totals differ from the issued snapshot";
            }
            $lines = $this->cents(DB::table('invoice_lines')->where('invoice_id', $row->id)->sum('line_total'));
            if ($lines !== $this->cents($row->subtotal)) {
                $out[] = "$row->number: invoice lines no longer add up to the subtotal";
            }
        } elseif ($table === 'credit_notes') {
            if ($this->cents($row->total) !== (int) ($snapshot['total'] ?? -1)) {
                $out[] = "$row->number: stored total differs from the issued snapshot";
            }
        } else {
            if ($this->cents($row->amount) !== (int) ($snapshot['amount'] ?? -1)) {
                $out[] = "$row->number: stored amount differs from the issued snapshot";
            }
            $alloc = $this->cents(DB::table('payment_allocations')->where('receipt_id', $row->id)->sum('amount'));
            if ($row->status === PaymentReceipt::PENDING || $row->status === PaymentReceipt::REJECTED) {
                if ($alloc !== 0) {
                    $out[] = "$row->number: a $row->status receipt has allocations";
                }
            } elseif ($alloc !== $this->cents($row->amount)) {
                $out[] = "$row->number: allocations do not add up to the receipt amount";
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- internals

    /** Canonical hash input: previous hash, then the snapshot as JSON. */
    public static function hash(?string $previous, array $snapshot): string
    {
        return hash('sha256', ($previous ?? '') . '|' . json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** Row lock on the company party: the single mutex for everything that issues or records money. */
    private function lockCompany(): BillingParty
    {
        $this->company();   // readiness check (throws a friendly message)

        return BillingParty::where('kind', BillingParty::KIND_COMPANY)->lockForUpdate()->firstOrFail();
    }

    private function nextNumber(BillingParty $issuer, string $type, int $year): string
    {
        DB::table('document_sequences')->insertOrIgnore([
            'issuer_party_id' => $issuer->id, 'doc_type' => $type, 'year' => $year,
            'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $row = DB::table('document_sequences')
            ->where(['issuer_party_id' => $issuer->id, 'doc_type' => $type, 'year' => $year])
            ->lockForUpdate()->first();

        $next = (int) $row->last_number + 1;
        DB::table('document_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('%s-%s-%d-%06d', config('billing.number_prefix', 'SL'), $type, $year, $next);
    }

    private function previousHash(string $table, int $issuerId): ?string
    {
        return DB::table($table)->where('issuer_party_id', $issuerId)->orderByDesc('id')->value('snapshot_sha256');
    }

    /** DB decimal (string/float from any driver) -> integer cents. */
    private function cents(mixed $value): int
    {
        return Money::dbToCents($value);
    }
}
