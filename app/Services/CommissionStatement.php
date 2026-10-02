<?php

namespace App\Services;

use App\Exceptions\CommissionPayoutException;
use App\Models\ActivatedDevice;
use App\Models\Dealer;
use App\Models\DealerCommissionPayout;
use App\Models\DealerCommissionRate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly dealer commission statements and payouts.
 *
 * The ledger (dealer_commission_ledger) is append-only and records commission the moment a dealer
 * assigns a device to a customer. That is NOT when money is owed. This class decides what is
 * PAYABLE and records what has actually been PAID.
 *
 * Rules (agreed with the product owner):
 *   - PAYABLE: the sale is still standing (not reversed), the device is bound to the customer
 *     (an activated_devices row), the subscription is Paid, and its start date is at least
 *     PAYABLE_AFTER_DAYS before the END of the statement month. Everything else is PENDING and is
 *     shown but never paid.
 *   - PERIOD: a calendar month in Sri Lanka time. A statement can only be paid once the month has ended.
 *   - CLAWBACK: if a sale that was already paid out is later reversed (unassigned, returned), the
 *     reversal is a negative line on the next statement, so the dealer is never paid for a sale
 *     that fell through.
 *   - PAID ONCE: paying a statement stamps payout_id on every ledger row it covered; a stamped row
 *     can never appear again. unique(dealer_id, period) stops the same month being paid twice.
 *   - NEGATIVE NET: a statement that nets to zero or less cannot be paid; its lines simply stay
 *     unpaid and roll into the next month.
 *
 * Known limits (shown to staff on the page):
 *   - Only sales recorded since the ledger went live are included. There is no backfill.
 *   - "Paid for 30 days" uses the CURRENT subscription start date that staff enter. If staff change
 *     it on a renewal, the 30 days restart for commission not yet paid out.
 *
 * Money is handled in whole cents (ints) so sums never pick up floating point error.
 */
class CommissionStatement
{
    public const PAYABLE_AFTER_DAYS = 30;
    public const TZ = 'Asia/Colombo';

    /** A reversal is matched to the sale it cancels if their timestamps are this close (they are written together). */
    private const PAIR_TOLERANCE_SECONDS = 5;

    /**
     * @return array{month:string,label:string,start:Carbon,endExclusive:Carbon,ended:bool}|null  null = not a valid 'YYYY-MM'
     */
    public static function period(?string $month): ?array
    {
        if (! is_string($month) || ! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m)) {
            return null;
        }

        $year = (int) $m[1];
        if ($year < 2020 || $year > 2100) {
            return null;
        }

        $local = Carbon::create($year, (int) $m[2], 1, 0, 0, 0, self::TZ);

        return [
            'month'        => $month,
            'label'        => $local->format('F Y'),
            'start'        => $local->copy()->utc(),
            'endExclusive' => $local->copy()->addMonth()->utc(),
            'ended'        => $local->copy()->addMonth()->lte(now()),
        ];
    }

    /** The most recent month that has fully ended, in Sri Lanka time ('YYYY-MM'). */
    public static function lastEndedMonth(): string
    {
        return now(self::TZ)->startOfMonth()->subMonth()->format('Y-m');
    }

    /**
     * False only when old fixed-rate ledger rows are still waiting to be paid AND no fixed rate was ever
     * configured (their amounts are then the unapproved LKR 1,000 fallback). Package-margin commission
     * does not use rates at all, so with no such leftover rows there is nothing to warn about.
     */
    public function ratesConfigured(): bool
    {
        if (DealerCommissionRate::query()->exists()) {
            return true;
        }

        return ! DB::table('dealer_commission_ledger')
            ->where('entry_type', 'earned')
            ->whereNull('reversed_at')
            ->whereNull('payout_id')
            ->exists();
    }

    /**
     * One dealer's statement for a month. If it has been paid, this is the frozen paid statement;
     * otherwise it is computed live.
     *
     * @return array<string,mixed>
     */
    public function forDealer(Dealer $dealer, string $month): array
    {
        $period = self::period($month) ?? throw new CommissionPayoutException('Invalid month.');

        $payout = DealerCommissionPayout::where('dealer_id', $dealer->id)->where('period', $month)->first();

        return $payout ? $this->paid($dealer, $period, $payout) : $this->compute($dealer, $period);
    }

    /**
     * Every dealer with something to show for the month (paid, payable, clawed back or pending).
     *
     * @return array<int, array<string,mixed>>
     */
    public function summary(string $month): array
    {
        $rows = [];

        foreach (Dealer::orderBy('full_name')->get() as $dealer) {
            $s = $this->forDealer($dealer, $month);

            if ($s['payout'] === null && $s['lines'] === [] && $s['pending'] === []) {
                continue;
            }

            $rows[] = $s;
        }

        return $rows;
    }

    /**
     * Pay a statement: recomputes it from scratch (nothing from the browser is trusted), claims
     * every ledger row it covers, and records the payout, all in one transaction.
     */
    public function markPaid(
        Dealer $dealer,
        string $month,
        string $reference,
        Carbon $paidOn,
        string $paidByAdminId,
        string $paidByName,
        ?string $notes = null
    ): DealerCommissionPayout {
        $period = self::period($month) ?? throw new CommissionPayoutException('Invalid month.');

        if (! $period['ended']) {
            throw new CommissionPayoutException($period['label'] . ' has not ended yet. A statement can only be paid after its month is over.');
        }

        try {
            return DB::transaction(function () use ($dealer, $period, $month, $reference, $paidOn, $paidByAdminId, $paidByName, $notes) {
                // Serialise payouts per dealer so two staff members cannot interleave.
                DB::table('dealers')->where('id', $dealer->id)->lockForUpdate()->first();

                if (DealerCommissionPayout::where('dealer_id', $dealer->id)->where('period', $month)->exists()) {
                    throw new CommissionPayoutException($period['label'] . ' has already been paid for this dealer.');
                }

                $s = $this->compute($dealer, $period);

                if ($s['lines'] === []) {
                    throw new CommissionPayoutException('Nothing is payable for this dealer in ' . $period['label'] . '.');
                }

                if ($s['net_cents'] <= 0) {
                    throw new CommissionPayoutException(
                        'The net balance for ' . $period['label'] . ' is not positive, so there is nothing to pay. '
                        . 'These lines stay unpaid and roll into the next statement.'
                    );
                }

                $payout = DealerCommissionPayout::create([
                    'dealer_id'          => $dealer->id,
                    'period'             => $month,
                    'period_end'         => $period['endExclusive'],
                    'payable_after_days' => self::PAYABLE_AFTER_DAYS,
                    'gross_amount'       => $s['gross_cents'] / 100,
                    'clawback_amount'    => $s['clawback_cents'] / 100,
                    'net_amount'         => $s['net_cents'] / 100,
                    'line_count'         => count($s['lines']),
                    'reference'          => trim($reference),
                    'paid_on'            => $paidOn->toDateString(),
                    'paid_by_admin_id'   => $paidByAdminId,
                    'paid_by_name'       => $paidByName,
                    'notes'              => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                ]);

                $ids = array_values(array_filter(array_column($s['lines'], 'ledger_id')));
                $entryIds = array_values(array_filter(array_column($s['lines'], 'entry_id')));

                $claimed = $ids === [] ? 0 : DB::table('dealer_commission_ledger')
                    ->whereIn('id', $ids)
                    ->whereNull('payout_id')
                    ->update(['payout_id' => $payout->id]);
                $claimedEntries = $entryIds === [] ? 0 : DB::table('commission_entries')
                    ->whereIn('id', $entryIds)
                    ->whereNull('payout_id')
                    ->update(['payout_id' => $payout->id]);

                // Another payout got to one of these rows first: roll everything back rather than pay twice.
                if ($claimed !== count($ids) || $claimedEntries !== count($entryIds)) {
                    throw new CommissionPayoutException('These commission lines changed while you were paying. Nothing was recorded; reload and try again.');
                }

                return $payout;
            });
        } catch (QueryException $e) {
            // unique(dealer_id, period): someone else paid this month a moment ago.
            if (DealerCommissionPayout::where('dealer_id', $dealer->id)->where('period', $month)->exists()) {
                throw new CommissionPayoutException($period['label'] . ' has already been paid for this dealer.');
            }

            throw $e;
        }
    }

    /** Paid statements for one dealer, newest first. Dealers only ever see these, never a preview. */
    public function paidFor(Dealer $dealer)
    {
        return DealerCommissionPayout::where('dealer_id', $dealer->id)->orderByDesc('period')->get();
    }

    // ------------------------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function compute(Dealer $dealer, array $period): array
    {
        $payableBefore = $period['endExclusive']->copy()->subDays(self::PAYABLE_AFTER_DAYS);

        // Sales still standing and not yet paid out, made before the month ended.
        $earned = DB::table('dealer_commission_ledger as l')
            ->join('setup_shalotrack_devices as sd', 'sd.shdevice_id', '=', 'l.shdevice_id')
            ->where('l.dealer_id', $dealer->id)
            ->where('l.entry_type', 'earned')
            ->whereNull('l.reversed_at')
            ->whereNull('l.payout_id')
            ->where('l.created_at', '<', $period['endExclusive'])
            ->orderBy('l.created_at')
            ->get(['l.id', 'l.amount', 'l.created_at', 'l.shdevice_id', 'sd.imei_number']);

        $bound = $this->boundDevices($earned->pluck('imei_number')->all());

        $lines = [];
        $pending = [];

        foreach ($earned as $row) {
            $ad = $bound[$row->imei_number] ?? null;
            $start = $ad && $ad->subscription_start_date ? Carbon::parse($ad->subscription_start_date, 'UTC') : null;

            $line = [
                'ledger_id'      => (int) $row->id,
                'kind'           => 'sale',
                'imei'           => $row->imei_number,
                'customer_name'  => $ad->customer_name ?? null,
                'vehicle_number' => $ad->vehicle_number ?? null,
                'amount_cents'   => self::cents($row->amount),
                'sold_on'        => Carbon::parse($row->created_at, 'UTC'),
                'sub_start'      => $start,
                'payable_from'   => $start ? $start->copy()->addDays(self::PAYABLE_AFTER_DAYS) : null,
                'reason'         => null,
            ];

            if ($ad === null) {
                $pending[] = ['reason' => 'Not bound to a customer yet'] + $line;
            } elseif ($ad->payment_status !== 'Paid' || $start === null) {
                $pending[] = ['reason' => 'Subscription not paid'] + $line;
            } elseif ($start->gt($payableBefore)) {
                $pending[] = ['reason' => 'Paid for less than ' . self::PAYABLE_AFTER_DAYS . ' days at month end'] + $line;
            } else {
                $lines[] = $line;
            }
        }

        foreach ($this->clawbacks($dealer, $period) as $c) {
            $lines[] = $c;
        }

        // Package margins (Renewal Plans guideline): payable once the month ends, because each one was
        // recorded together with a confirmed payment. Anything reversed shows as a negative line.
        foreach ($this->marginLines($dealer, $period, null) as $m) {
            $lines[] = $m;
        }

        [$gross, $claw] = self::totals($lines);

        return $this->shape($dealer, $period, null, $lines, $pending, $gross, $claw);
    }

    /**
     * Reversals of sales that were ALREADY paid out. A reversal of a sale that was never paid just
     * cancels it (both rows stay unclaimed and net to zero), so it is not a clawback.
     *
     * @return array<int, array<string,mixed>>
     */
    private function clawbacks(Dealer $dealer, array $period): array
    {
        $reversals = DB::table('dealer_commission_ledger as l')
            ->join('setup_shalotrack_devices as sd', 'sd.shdevice_id', '=', 'l.shdevice_id')
            ->where('l.dealer_id', $dealer->id)
            ->where('l.entry_type', 'reversed')
            ->whereNull('l.payout_id')
            ->where('l.created_at', '<', $period['endExclusive'])
            ->get(['l.id', 'l.amount', 'l.created_at', 'l.shdevice_id', 'sd.imei_number']);

        if ($reversals->isEmpty()) {
            return [];
        }

        $paidSales = DB::table('dealer_commission_ledger')
            ->where('dealer_id', $dealer->id)
            ->where('entry_type', 'earned')
            ->whereNotNull('payout_id')
            ->whereNotNull('reversed_at')
            ->whereIn('shdevice_id', $reversals->pluck('shdevice_id')->unique()->all())
            ->get(['id', 'shdevice_id', 'amount', 'reversed_at'])
            ->all();

        $bound = $this->boundDevices($reversals->pluck('imei_number')->all());
        $out = [];

        foreach ($reversals as $r) {
            $at = Carbon::parse($r->created_at, 'UTC');

            foreach ($paidSales as $i => $sale) {
                if ($sale->shdevice_id === $r->shdevice_id
                    && self::cents($sale->amount) === -self::cents($r->amount)
                    && abs(Carbon::parse($sale->reversed_at, 'UTC')->diffInSeconds($at, false)) <= self::PAIR_TOLERANCE_SECONDS) {
                    unset($paidSales[$i]); // each paid sale can be cancelled only once

                    $ad = $bound[$r->imei_number] ?? null;
                    $out[] = [
                        'ledger_id'      => (int) $r->id,
                        'kind'           => 'clawback',
                        'imei'           => $r->imei_number,
                        'customer_name'  => $ad->customer_name ?? null,
                        'vehicle_number' => $ad->vehicle_number ?? null,
                        'amount_cents'   => self::cents($r->amount),
                        'sold_on'        => $at,
                        'sub_start'      => null,
                        'payable_from'   => null,
                        'reason'         => 'Sale reversed after it was paid out',
                    ];
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function paid(Dealer $dealer, array $period, DealerCommissionPayout $payout): array
    {
        $rows = DB::table('dealer_commission_ledger as l')
            ->join('setup_shalotrack_devices as sd', 'sd.shdevice_id', '=', 'l.shdevice_id')
            ->where('l.payout_id', $payout->id)
            ->orderBy('l.created_at')
            ->get(['l.id', 'l.amount', 'l.entry_type', 'l.created_at', 'sd.imei_number']);

        $bound = $this->boundDevices($rows->pluck('imei_number')->all());

        $lines = [];
        foreach ($rows as $row) {
            $ad = $bound[$row->imei_number] ?? null;
            $start = $ad && $ad->subscription_start_date ? Carbon::parse($ad->subscription_start_date, 'UTC') : null;
            $sale = $row->entry_type === 'earned';

            $lines[] = [
                'ledger_id'      => (int) $row->id,
                'kind'           => $sale ? 'sale' : 'clawback',
                'imei'           => $row->imei_number,
                'customer_name'  => $ad->customer_name ?? null,
                'vehicle_number' => $ad->vehicle_number ?? null,
                'amount_cents'   => self::cents($row->amount),
                'sold_on'        => Carbon::parse($row->created_at, 'UTC'),
                'sub_start'      => $sale ? $start : null,
                'payable_from'   => $sale && $start ? $start->copy()->addDays($payout->payable_after_days) : null,
                'reason'         => $sale ? null : 'Sale reversed after it was paid out',
            ];
        }

        foreach ($this->marginLines($dealer, $period, $payout) as $m) {
            $lines[] = $m;
        }

        // Totals come from the payout record itself, not a recomputation: this is what was actually paid.
        return $this->shape(
            $dealer, $period, $payout, $lines, [],
            self::cents($payout->gross_amount), self::cents($payout->clawback_amount)
        );
    }

    /**
     * Package margin lines for a dealer or distributor. For an open statement: every unpaid line recorded
     * before the month ended (older unpaid lines roll in). For a paid statement: the lines that payout claimed.
     *
     * @return array<int, array<string,mixed>>
     */
    private function marginLines(Dealer $dealer, array $period, ?DealerCommissionPayout $payout): array
    {
        $q = DB::table('commission_entries as e')
            ->join('package_payments as p', 'p.id', '=', 'e.payment_id')
            ->where('e.dealer_id', $dealer->id)
            ->orderBy('e.occurred_at')->orderBy('e.id');

        $payout
            ? $q->where('e.payout_id', $payout->id)
            : $q->whereNull('e.payout_id')->where('e.occurred_at', '<', $period['endExclusive']);

        $lines = [];
        foreach ($q->get(['e.id', 'e.amount', 'e.role', 'e.entry_type', 'e.occurred_at', 'e.reason',
            'p.imei_number', 'p.customer_name', 'p.vehicle_number', 'p.package_label', 'p.package_model',
            'p.subscription_start', 'p.id as payment_id']) as $r) {
            $claw = $r->entry_type === 'clawback';
            $lines[] = [
                'ledger_id'      => null,
                'entry_id'       => (int) $r->id,
                'payment_id'     => (int) $r->payment_id,
                'kind'           => $claw ? 'margin_clawback' : 'margin',
                'role'           => $r->role,
                'package'        => $r->package_label ?: $r->package_model,
                'imei'           => $r->imei_number,
                'customer_name'  => $r->customer_name,
                'vehicle_number' => $r->vehicle_number,
                'amount_cents'   => self::cents($r->amount),
                'sold_on'        => Carbon::parse($r->occurred_at, 'UTC'),
                'sub_start'      => $r->subscription_start ? Carbon::parse($r->subscription_start, 'UTC') : null,
                'payable_from'   => null,
                'reason'         => $claw ? ($r->reason ?: 'Payment reversed') : null,
            ];
        }

        return $lines;
    }

    /** @return array{0:int,1:int} [gross (sum of positive lines), clawbacks (sum of negative lines)] in cents */
    private static function totals(array $lines): array
    {
        $gross = $claw = 0;
        foreach ($lines as $l) {
            $l['amount_cents'] >= 0 ? $gross += $l['amount_cents'] : $claw += $l['amount_cents'];
        }

        return [$gross, $claw];
    }

    /** @return array<string,mixed> */
    private function shape(Dealer $dealer, array $period, ?DealerCommissionPayout $payout, array $lines, array $pending, int $gross, int $claw): array
    {
        $net = $gross + $claw;

        $status = match (true) {
            $payout !== null => 'paid',
            $lines === []    => 'nothing',
            $net <= 0        => 'negative',
            ! $period['ended'] => 'open',
            default          => 'ready',
        };

        return [
            'dealer'         => $dealer,
            'period'         => $period,
            'payout'         => $payout,
            'status'         => $status,
            'lines'          => $lines,
            'pending'        => $pending,
            'gross_cents'    => $gross,
            'clawback_cents' => $claw,
            'net_cents'      => $net,
            'pending_cents'  => array_sum(array_column($pending, 'amount_cents')),
        ];
    }

    /**
     * The current bound device row for each IMEI (the newest, if staff ever bound one IMEI twice).
     *
     * @param  array<int,string|null> $imeis
     * @return array<string, ActivatedDevice>
     */
    private function boundDevices(array $imeis): array
    {
        $imeis = array_values(array_unique(array_filter($imeis)));
        if ($imeis === []) {
            return [];
        }

        $out = [];
        foreach (ActivatedDevice::whereIn('imei_number', $imeis)->orderBy('created_at')->get() as $ad) {
            $out[$ad->imei_number] = $ad; // later (newer) rows overwrite earlier ones
        }

        return $out;
    }

    private static function cents($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function centsOf($amount): int
    {
        return self::cents($amount);
    }

    /** "1,234.50" from cents. */
    public static function money(int $cents): string
    {
        return ($cents < 0 ? '-' : '') . number_format(abs($cents) / 100, 2);
    }
}