<?php

namespace App\Services\Supplier;

use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Services\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * What the company has PAID each supplier, per purchase order. Closes the purchasing chain:
 *
 *   purchase order (what we owe)  ->  delivery (what arrived)  ->  stock-in  ->  PAYMENT (what we have paid)
 *
 * Money is handled in integer cents. A payment can never take an order past its total (no overpaying), cannot be
 * made on a cancelled order, and is never edited or deleted: a wrong payment is voided with a reason and stays on
 * record. An order with live payments cannot be edited or cancelled (void them first), so "paid" can never
 * disagree with the order's total. Every record and void is written to the audit log.
 */
class SupplierPayments
{
    public const METHODS = ['CASH' => 'Cash', 'BANK' => 'Bank transfer', 'CHEQUE' => 'Cheque', 'OTHER' => 'Other'];

    private const LIMIT_CENTS = 999999999999;

    private static bool $ready = false;

    /** True once the supplier_payments table exists (migration 2026_11_09_000006). Until then the screen hides itself. */
    public static function ready(): bool
    {
        if (self::$ready) {
            return true;
        }

        try {
            return self::$ready = Schema::hasTable('supplier_payments');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** Paid so far on one order (voided payments do not count), in cents. */
    public function paidCents(int $invoiceId): int
    {
        if (! self::ready()) {
            return 0;
        }

        return (int) round(((float) SupplierPayment::where('supplier_invoice_id', $invoiceId)->whereNull('voided_at')->sum('amount')) * 100);
    }

    public function hasLivePayments(int $invoiceId): bool
    {
        return self::ready() && SupplierPayment::where('supplier_invoice_id', $invoiceId)->whereNull('voided_at')->exists();
    }

    /**
     * @return array{total_cents:int, paid_cents:int, balance_cents:int, status:string}  status: UNPAID, PART, PAID or CANCELLED
     */
    public function summary(SupplierInvoice $order, ?int $paidCents = null): array
    {
        $total = self::cents($order->grand_total);
        $paid = $paidCents ?? $this->paidCents($order->id);

        if ($order->isCancelled()) {
            return ['total_cents' => $total, 'paid_cents' => $paid, 'balance_cents' => 0, 'status' => 'CANCELLED'];
        }

        $balance = max(0, $total - $paid);

        return [
            'total_cents'   => $total,
            'paid_cents'    => $paid,
            'balance_cents' => $balance,
            'status'        => $paid <= 0 ? 'UNPAID' : ($balance === 0 ? 'PAID' : 'PART'),
        ];
    }

    /**
     * One query for the whole supplier: order id => summary, plus what is owed in total (cents).
     *
     * @param  iterable<SupplierInvoice> $orders
     * @return array{orders: array<int,array<string,mixed>>, owed_cents: int, paid_cents: int}
     */
    public function forOrders(iterable $orders): array
    {
        $orders = collect($orders);
        $paid = self::ready() && $orders->isNotEmpty()
            ? SupplierPayment::whereIn('supplier_invoice_id', $orders->pluck('id'))->whereNull('voided_at')
                ->selectRaw('supplier_invoice_id, SUM(amount) AS total')->groupBy('supplier_invoice_id')->pluck('total', 'supplier_invoice_id')
            : collect();

        $out = [];
        $owed = 0;
        $paidAll = 0;
        foreach ($orders as $order) {
            $s = $this->summary($order, (int) round(((float) ($paid[$order->id] ?? 0)) * 100));
            $out[$order->id] = $s;
            $owed += $s['balance_cents'];
            $paidAll += $s['status'] === 'CANCELLED' ? 0 : $s['paid_cents'];
        }

        return ['orders' => $out, 'owed_cents' => $owed, 'paid_cents' => $paidAll];
    }

    /**
     * Record a payment. $amount is a validated decimal string from the form.
     *
     * @throws ValidationException
     */
    public function record(int $invoiceId, mixed $amount, string $paidOn, string $method, ?string $reference, ?string $note, ?string $adminId): SupplierPayment
    {
        if (! self::ready()) {
            throw ValidationException::withMessages(['amount' => 'Recording payments needs the latest database update.']);
        }
        if (! isset(self::METHODS[$method])) {
            throw ValidationException::withMessages(['method' => 'Choose how it was paid.']);
        }

        $cents = self::cents($amount);
        if ($cents < 1 || $cents > self::LIMIT_CENTS) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }
        if ($paidOn > now()->toDateString()) {
            throw ValidationException::withMessages(['paid_on' => 'A payment cannot be dated in the future.']);
        }

        $payment = DB::transaction(function () use ($invoiceId, $cents, $paidOn, $method, $reference, $note, $adminId) {
            // Locked, so two admins paying the same order at the same moment cannot together pay more than it is worth.
            $order = SupplierInvoice::whereKey($invoiceId)->lockForUpdate()->first();

            if (! $order) {
                throw ValidationException::withMessages(['supplier_invoice_id' => 'Purchase order not found.']);
            }
            if ($order->isCancelled()) {
                throw ValidationException::withMessages(['supplier_invoice_id' => "Purchase order {$order->invoice_number} is cancelled; nothing is owed on it."]);
            }

            $s = $this->summary($order);
            if ($cents > $s['balance_cents']) {
                throw ValidationException::withMessages(['amount' => $s['balance_cents'] === 0
                    ? "Purchase order {$order->invoice_number} is already fully paid."
                    : sprintf('Only Rs. %s is still owed on %s. You cannot pay more than that.', number_format($s['balance_cents'] / 100, 2), $order->invoice_number)]);
            }

            return SupplierPayment::forceCreate([
                'supplier_id'         => $order->supplier_id,
                'supplier_invoice_id' => $order->id,
                'amount'              => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100),
                'paid_on'             => $paidOn,
                'method'              => $method,
                'reference'           => $reference !== null ? (mb_substr(trim($reference), 0, 100) ?: null) : null,
                'note'                => $note !== null ? (mb_substr(trim($note), 0, 500) ?: null) : null,
                'recorded_by'         => $adminId,
            ]);
        });

        Audit::record('supplier_payment.recorded', 'supplier_payment', $payment->id, 'PO #' . $payment->supplier_invoice_id, [], [
            'supplier_id' => $payment->supplier_id,
            'amount'      => (string) $payment->amount,
            'method'      => $method,
            'reference'   => $payment->reference,
        ]);

        return $payment;
    }

    /** @throws ValidationException */
    public function void(int $paymentId, string $reason, ?string $adminId): void
    {
        DB::transaction(function () use ($paymentId, $reason, $adminId) {
            $payment = SupplierPayment::whereKey($paymentId)->lockForUpdate()->first();

            if (! $payment) {
                throw ValidationException::withMessages(['payment' => 'Payment not found.']);
            }
            if ($payment->isVoided()) {
                throw ValidationException::withMessages(['payment' => 'This payment is already voided.']);
            }

            $payment->forceFill(['voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 200), 'voided_by' => $adminId])->save();

            Audit::record('supplier_payment.voided', 'supplier_payment', $payment->id, 'PO #' . $payment->supplier_invoice_id, [], [
                'amount' => (string) $payment->amount,
                'reason' => $reason,
            ]);
        });
    }
}
