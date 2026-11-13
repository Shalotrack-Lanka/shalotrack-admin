<?php

namespace Tests\Pgsql;

use App\Exceptions\CommissionPayoutException;
use App\Models\ActivatedDevice;
use App\Models\Dealer;
use App\Models\DealerCommissionPayout;
use App\Models\DealerCommissionRate;
use App\Models\SetupShalotrackDevice;
use App\Services\CommissionStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THE OLDER "FIXED RATE PER SALE" COMMISSION LEDGER, read by the monthly statement and paid out by "Mark paid".
 * (The new package-margin lines are tested in CommissionPayoutTest; this file covers the old ledger rows that
 * are still in the database and still have to be paid or clawed back correctly.)
 *
 * What the tests prove (simple words):
 *   - a sale is PAYABLE only when it still stands, is not paid out already, was made before the month ended,
 *     the device is bound to a customer, the subscription is paid, and it started at least 30 days before month end;
 *     every other sale waits in "pending" with the right reason;
 *   - a sale that was reversed AFTER it was paid out shows as a negative (clawback) line, once, matched by amount,
 *     device and time (5 seconds); a reversal of a sale never paid out is not a clawback;
 *   - "Mark paid" recomputes from the database, pays once per dealer per month, claims every line so it can never be
 *     paid twice, refuses an unfinished month, an empty month and a negative balance, and records nothing when a line
 *     was taken by someone else a moment earlier;
 *   - a paid statement is frozen: it shows what was actually paid, even if the ledger changes later.
 *
 * Everything made here is rolled back after each test.
 */
class CommissionStatementLedgerPgTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private string $month;
    private array $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->month = CommissionStatement::lastEndedMonth();
        $this->period = CommissionStatement::period($this->month);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function service(): CommissionStatement
    {
        return new CommissionStatement();
    }

    /** A bound, Paid device whose subscription started $daysBeforeEnd days before the month ended. */
    private function boundDevice(Dealer $dealer, int $daysBeforeEnd = 60, string $payment = 'Paid'): ActivatedDevice
    {
        return $this->makeBound([
            'payment_status'          => $payment,
            'subscription_model'      => '1 Year',
            'subscription_start_date' => $this->period['endExclusive']->copy()->subDays($daysBeforeEnd),
        ], $dealer);
    }

    private function shId(string $imei): int
    {
        return (int) SetupShalotrackDevice::where('imei_number', $imei)->value('shdevice_id');
    }

    /** One old-ledger row. Returns its id. */
    private function ledger(Dealer $dealer, int $shdeviceId, float $amount, string $type = 'earned', ?Carbon $at = null, array $extra = []): int
    {
        $at ??= $this->period['start']->copy()->addDays(9)->addHours(6);

        return (int) DB::table('dealer_commission_ledger')->insertGetId(array_merge([
            'dealer_id'             => $dealer->id,
            'shdevice_id'           => $shdeviceId,
            'dealer_status_at_time' => 'retailer',
            'rate_applied'          => abs($amount),
            'amount'                => $amount,
            'entry_type'            => $type,
            'reason'                => $type === 'earned' ? 'sold' : 'device reversed',
            'created_at'            => $at,
            'updated_at'            => $at,
        ], $extra));
    }

    private function sale(Dealer $dealer, ActivatedDevice $device, float $amount = 1000, array $extra = []): int
    {
        return $this->ledger($dealer, $this->shId($device->imei_number), $amount, 'earned', null, $extra);
    }

    private function statement(Dealer $dealer, ?string $month = null): array
    {
        return $this->service()->forDealer($dealer, $month ?? $this->month);
    }

    private function pay(Dealer $dealer, string $reference = 'REF-1', ?string $notes = null, ?string $month = null): DealerCommissionPayout
    {
        return $this->service()->markPaid($dealer, $month ?? $this->month, $reference, now(), 'admin-1', 'Autotest Admin', $notes);
    }

    private function assertRefused(string $message, \Closure $call): void
    {
        try {
            $call();
            $this->fail('expected a CommissionPayoutException');
        } catch (CommissionPayoutException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    /** A sale that was already paid out and then reversed, plus its reversal row. Returns [paidSaleId, reversalId, shdeviceId]. */
    private function paidThenReversed(Dealer $dealer, float $amount = 1000, int $secondsApart = 0): array
    {
        $device = $this->boundDevice($dealer);
        $sh = $this->shId($device->imei_number);
        $payout = $this->seedPayout($dealer, '2020-01');
        $reversedAt = $this->period['start']->copy()->addDays(12);
        $paidSale = $this->ledger($dealer, $sh, $amount, 'earned', $this->period['start']->copy()->subDays(40), ['payout_id' => $payout, 'reversed_at' => $reversedAt]);
        $reversal = $this->ledger($dealer, $sh, -$amount, 'reversed', $reversedAt->copy()->addSeconds($secondsApart));

        return [$paidSale, $reversal, $sh];
    }

    private function seedPayout(Dealer $dealer, string $month): int
    {
        return (int) DB::table('dealer_commission_payouts')->insertGetId([
            'dealer_id' => $dealer->id, 'period' => $month, 'period_end' => CommissionStatement::period($month)['endExclusive'],
            'payable_after_days' => 30, 'gross_amount' => 1000, 'clawback_amount' => 0, 'net_amount' => 1000, 'line_count' => 1,
            'reference' => 'AUTOTEST-OLD', 'paid_on' => '2020-02-01', 'paid_by_admin_id' => 'autotest', 'paid_by_name' => 'Autotest',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ================================================================== month and money helpers

    public static function badMonths(): array
    {
        return ['empty' => [''], 'null' => [null], 'word' => ['abc'], 'month 13' => ['2026-13'], 'month 00' => ['2026-00'], 'no zero' => ['2026-1'],
            'too early' => ['2019-12'], 'too late' => ['2101-01'], 'with day' => ['2026-10-01'], 'spaces' => [' 2026-10']];
    }

    #[DataProvider('badMonths')]
    public function test_only_a_real_year_and_month_is_a_period(mixed $month): void
    {
        $this->assertNull(CommissionStatement::period($month));
    }

    public function test_a_period_is_a_sri_lanka_calendar_month_in_utc(): void
    {
        $p = CommissionStatement::period('2026-10');

        $this->assertSame('October 2026', $p['label']);
        $this->assertSame('2026-09-30 18:30:00', $p['start']->toDateTimeString(), '1 October 00:00 in Colombo');
        $this->assertSame('2026-10-31 18:30:00', $p['endExclusive']->toDateTimeString());
    }

    public function test_a_month_has_ended_only_after_its_last_sri_lanka_moment(): void
    {
        Carbon::setTestNow('2026-10-31 18:29:59');   // 23:59:59 on 31 October in Colombo
        $this->assertFalse(CommissionStatement::period('2026-10')['ended']);

        Carbon::setTestNow('2026-10-31 18:30:00');   // 00:00 on 1 November in Colombo
        $this->assertTrue(CommissionStatement::period('2026-10')['ended']);
        $this->assertSame('2026-10', CommissionStatement::lastEndedMonth());
    }

    public function test_money_is_exact_in_cents_and_formatted_with_a_minus_sign(): void
    {
        $this->assertSame(1999, CommissionStatement::centsOf('19.99'), 'no floating point drift');
        $this->assertSame(1, CommissionStatement::centsOf(0.005 + 0.005));
        $this->assertSame('1,234.50', CommissionStatement::money(123450));
        $this->assertSame('-0.05', CommissionStatement::money(-5));
        $this->assertSame('0.00', CommissionStatement::money(0));
    }

    // ================================================================== what is payable and what waits

    public function test_a_standing_paid_sale_is_a_payable_line_with_its_details(): void
    {
        $dealer = $this->makeDealer();
        $device = $this->boundDevice($dealer);
        $this->sale($dealer, $device, 1250.50);

        $s = $this->statement($dealer);

        $this->assertSame('ready', $s['status']);
        $this->assertCount(1, $s['lines']);
        $line = $s['lines'][0];
        $this->assertSame('sale', $line['kind']);
        $this->assertSame($device->imei_number, $line['imei']);
        $this->assertSame($device->customer_name, $line['customer_name']);
        $this->assertSame($device->vehicle_number, $line['vehicle_number']);
        $this->assertSame(125050, $line['amount_cents']);
        $this->assertSame(125050, $s['gross_cents']);
        $this->assertSame(0, $s['clawback_cents']);
        $this->assertSame(125050, $s['net_cents']);
        $this->assertEquals(30, $line['sub_start']->diffInDays($line['payable_from']));
        $this->assertSame([], $s['pending']);
        $this->assertNull($s['payout']);
    }

    public function test_a_sale_waits_with_the_right_reason_until_it_can_be_paid(): void
    {
        $dealer = $this->makeDealer();
        $unbound = SetupShalotrackDevice::create(['device_category' => 'AUTOTEST-CAT', 'imei_number' => $this->validImei(), 'sim_number' => '0700000091', 'status' => 'Activated', 'dealer_id' => $dealer->id]);
        $this->ledger($dealer, $unbound->shdevice_id, 100);
        $this->sale($dealer, $this->boundDevice($dealer, 60, 'not-Paid'), 200);
        $this->sale($dealer, $this->boundDevice($dealer, 10), 300);

        $s = $this->statement($dealer);

        $reasons = collect($s['pending'])->pluck('reason', 'amount_cents');
        $this->assertSame('Not bound to a customer yet', $reasons[10000]);
        $this->assertSame('Subscription not paid', $reasons[20000]);
        $this->assertSame('Paid for less than 30 days at month end', $reasons[30000]);
        $this->assertSame([], $s['lines']);
        $this->assertSame('nothing', $s['status']);
        $this->assertSame(60000, $s['pending_cents']);
    }

    public function test_thirty_days_exactly_is_payable_and_one_second_less_is_not(): void
    {
        $dealer = $this->makeDealer();
        $onTime = $this->makeBound(['payment_status' => 'Paid', 'subscription_start_date' => $this->period['endExclusive']->copy()->subDays(30)], $dealer);
        $late = $this->makeBound(['payment_status' => 'Paid', 'subscription_start_date' => $this->period['endExclusive']->copy()->subDays(30)->addSecond()], $dealer);
        $this->sale($dealer, $onTime, 100);
        $this->sale($dealer, $late, 200);

        $s = $this->statement($dealer);

        $this->assertSame([10000], array_column($s['lines'], 'amount_cents'));
        $this->assertSame([20000], array_column($s['pending'], 'amount_cents'));
    }

    public function test_a_paid_device_with_no_start_date_waits_as_not_paid(): void
    {
        $dealer = $this->makeDealer();
        $device = $this->makeBound(['payment_status' => 'Paid', 'subscription_start_date' => null], $dealer);
        $this->sale($dealer, $device, 100);

        $this->assertSame('Subscription not paid', $this->statement($dealer)['pending'][0]['reason']);
    }

    public function test_sales_that_are_reversed_paid_out_or_made_after_the_month_are_left_out(): void
    {
        $dealer = $this->makeDealer();
        $payout = $this->seedPayout($dealer, '2020-02');
        $this->sale($dealer, $this->boundDevice($dealer), 100, ['reversed_at' => now()]);
        $this->sale($dealer, $this->boundDevice($dealer), 200, ['payout_id' => $payout]);
        $this->ledger($dealer, $this->shId($this->boundDevice($dealer)->imei_number), 300, 'earned', $this->period['endExclusive']->copy()->addMinute());
        $this->sale($dealer, $this->boundDevice($dealer), 400);

        $s = $this->statement($dealer);

        $this->assertSame([40000], array_column($s['lines'], 'amount_cents'));
        $this->assertSame([], $s['pending']);
    }

    public function test_another_dealers_sales_never_appear(): void
    {
        $mine = $this->makeDealer();
        $other = $this->makeDealer();
        $this->sale($other, $this->boundDevice($other), 999);

        $s = $this->statement($mine);

        $this->assertSame([], $s['lines']);
        $this->assertSame('nothing', $s['status']);
    }

    public function test_an_unfinished_month_shows_as_open_and_a_negative_balance_as_negative(): void
    {
        $dealer = $this->makeDealer();
        $device = $this->boundDevice($dealer, 90);
        $current = now(CommissionStatement::TZ)->format('Y-m');
        $this->ledger($dealer, $this->shId($device->imei_number), 500, 'earned', now()->subMinute());

        $this->assertSame('open', $this->statement($dealer, $current)['status']);

        $other = $this->makeDealer();
        $this->paidThenReversed($other, 700);
        $this->assertSame('negative', $this->statement($other)['status']);
    }

    // ================================================================== clawbacks

    public function test_a_sale_reversed_after_it_was_paid_out_is_a_negative_line(): void
    {
        $dealer = $this->makeDealer();
        $this->sale($dealer, $this->boundDevice($dealer), 1000);
        [, , $sh] = $this->paidThenReversed($dealer, 400);

        $s = $this->statement($dealer);

        $claw = collect($s['lines'])->firstWhere('kind', 'clawback');
        $this->assertNotNull($claw);
        $this->assertSame(-40000, $claw['amount_cents']);
        $this->assertSame('Sale reversed after it was paid out', $claw['reason']);
        $this->assertSame(100000, $s['gross_cents']);
        $this->assertSame(-40000, $s['clawback_cents']);
        $this->assertSame(60000, $s['net_cents']);
        $this->assertSame('ready', $s['status']);
    }

    public function test_the_clawback_must_match_in_time_amount_and_device(): void
    {
        $inTime = $this->makeDealer();
        $this->paidThenReversed($inTime, 400, 5);
        $this->assertCount(1, $this->statement($inTime)['lines'], '5 seconds apart still matches');

        $tooLate = $this->makeDealer();
        $this->paidThenReversed($tooLate, 400, 6);
        $this->assertCount(0, $this->statement($tooLate)['lines'], '6 seconds apart does not');

        $wrongAmount = $this->makeDealer();
        [, $reversal] = $this->paidThenReversed($wrongAmount, 400);
        DB::table('dealer_commission_ledger')->where('id', $reversal)->update(['amount' => -399.99]);
        $this->assertCount(0, $this->statement($wrongAmount)['lines']);
    }

    public function test_a_reversal_of_a_sale_that_was_never_paid_out_is_not_a_clawback(): void
    {
        $dealer = $this->makeDealer();
        $device = $this->boundDevice($dealer);
        $sh = $this->shId($device->imei_number);
        $this->ledger($dealer, $sh, 500, 'earned', null, ['reversed_at' => now()]);
        $this->ledger($dealer, $sh, -500, 'reversed');

        $s = $this->statement($dealer);

        $this->assertSame([], $s['lines']);
        $this->assertSame('nothing', $s['status']);
    }

    public function test_one_paid_sale_can_be_clawed_back_only_once(): void
    {
        $dealer = $this->makeDealer();
        [, $reversal, $sh] = $this->paidThenReversed($dealer, 400);
        $at = Carbon::parse(DB::table('dealer_commission_ledger')->where('id', $reversal)->value('created_at'), 'UTC');
        $this->ledger($dealer, $sh, -400, 'reversed', $at);   // a duplicate reversal row, at the very same moment

        $s = $this->statement($dealer);

        $this->assertCount(1, $s['lines']);
    }

    public function test_a_reversal_already_claimed_by_a_payout_is_not_counted_again(): void
    {
        $dealer = $this->makeDealer();
        [, $reversal] = $this->paidThenReversed($dealer, 400);
        DB::table('dealer_commission_ledger')->where('id', $reversal)->update(['payout_id' => $this->seedPayout($dealer, '2020-03')]);

        $this->assertSame([], $this->statement($dealer)['lines']);
    }

    // ================================================================== marking a month as paid

    public function test_paying_records_the_totals_claims_every_line_and_trims_the_text(): void
    {
        $dealer = $this->makeDealer();
        $a = $this->sale($dealer, $this->boundDevice($dealer), 1000);
        $b = $this->sale($dealer, $this->boundDevice($dealer), 500.50);
        $this->paidThenReversed($dealer, 300);

        $payout = $this->pay($dealer, '  BANK-77  ', '  paid by transfer  ');

        $payout = $payout->fresh();
        $this->assertSame($this->month, $payout->period);
        $this->assertEquals(1500.50, $payout->gross_amount);
        $this->assertEquals(-300, $payout->clawback_amount);
        $this->assertEquals(1200.50, $payout->net_amount);
        $this->assertSame(3, $payout->line_count);
        $this->assertSame('BANK-77', $payout->reference);
        $this->assertSame('paid by transfer', $payout->notes);
        $this->assertSame(30, (int) $payout->payable_after_days);
        $this->assertSame('admin-1', $payout->paid_by_admin_id);
        $this->assertSame('Autotest Admin', $payout->paid_by_name);
        $this->assertSame(now()->toDateString(), Carbon::parse($payout->paid_on)->toDateString());
        $this->assertSame(3, DB::table('dealer_commission_ledger')->where('payout_id', $payout->id)->count());
        $this->assertSame([$payout->id, $payout->id], DB::table('dealer_commission_ledger')->whereIn('id', [$a, $b])->pluck('payout_id')->all());
    }

    public function test_blank_notes_are_stored_as_nothing(): void
    {
        $dealer = $this->makeDealer();
        $this->sale($dealer, $this->boundDevice($dealer), 100);

        $this->assertNull($this->pay($dealer, 'R', '   ')->fresh()->notes);
    }

    public function test_pending_sales_are_not_paid_and_stay_unclaimed(): void
    {
        $dealer = $this->makeDealer();
        $this->sale($dealer, $this->boundDevice($dealer), 100);
        $waiting = $this->sale($dealer, $this->boundDevice($dealer, 5), 900);

        $this->pay($dealer);

        $this->assertNull(DB::table('dealer_commission_ledger')->where('id', $waiting)->value('payout_id'));
    }

    public function test_a_month_is_paid_once_per_dealer(): void
    {
        $dealer = $this->makeDealer();
        $this->sale($dealer, $this->boundDevice($dealer), 100);
        $this->pay($dealer);
        $this->sale($dealer, $this->boundDevice($dealer), 200);

        $this->assertRefused('has already been paid for this dealer', fn () => $this->pay($dealer));
        $this->assertSame(1, DealerCommissionPayout::where('dealer_id', $dealer->id)->count());
    }

    public function test_paying_refuses_a_bad_month_an_unfinished_month_an_empty_month_and_a_negative_balance(): void
    {
        $dealer = $this->makeDealer();
        $this->assertRefused('Invalid month.', fn () => $this->pay($dealer, 'R', null, '2026-13'));

        $current = now(CommissionStatement::TZ)->format('Y-m');
        $this->assertRefused('has not ended yet', fn () => $this->pay($dealer, 'R', null, $current));

        $this->assertRefused('Nothing is payable for this dealer', fn () => $this->pay($dealer));

        $negative = $this->makeDealer();
        $this->paidThenReversed($negative, 400);
        $this->assertRefused('net balance for', fn () => $this->pay($negative));
        $this->assertSame(0, DealerCommissionPayout::where('dealer_id', $negative->id)->where('period', $this->month)->count());
    }

    public function test_when_a_line_is_taken_by_someone_else_a_moment_earlier_nothing_is_recorded(): void
    {
        $dealer = $this->makeDealer();
        $mine = $this->sale($dealer, $this->boundDevice($dealer), 100);
        $other = $this->sale($dealer, $this->boundDevice($dealer), 200);
        $stolen = false;
        DB::listen(function ($query) use (&$stolen, $other) {
            if (! $stolen && str_starts_with(strtolower($query->sql), 'insert into "dealer_commission_payouts"')) {
                $stolen = true;
                $rival = DB::table('dealer_commission_payouts')->max('id');
                DB::table('dealer_commission_ledger')->where('id', $other)->update(['payout_id' => $rival]);
            }
        });

        $this->assertRefused('changed while you were paying', fn () => $this->pay($dealer));

        $this->assertTrue($stolen);
        $this->assertSame(0, DealerCommissionPayout::where('dealer_id', $dealer->id)->count(), 'the payout row was rolled back');
        $this->assertNull(DB::table('dealer_commission_ledger')->where('id', $mine)->value('payout_id'), 'and so were the claims');
    }

    // ================================================================== a paid statement is frozen

    public function test_a_paid_statement_shows_what_was_paid_even_if_the_ledger_changes_later(): void
    {
        $dealer = $this->makeDealer();
        $device = $this->boundDevice($dealer);
        $id = $this->sale($dealer, $device, 800);
        $payout = $this->pay($dealer);

        DB::table('dealer_commission_ledger')->where('id', $id)->update(['amount' => 1]);
        $s = $this->statement($dealer);

        $this->assertSame('paid', $s['status']);
        $this->assertSame($payout->id, $s['payout']->id);
        $this->assertSame(80000, $s['gross_cents'], 'totals come from the payout record');
        $this->assertSame(80000, $s['net_cents']);
        $this->assertSame([], $s['pending']);
        $line = $s['lines'][0];
        $this->assertSame('sale', $line['kind']);
        $this->assertSame($device->imei_number, $line['imei']);
        $this->assertEquals(30, $line['sub_start']->diffInDays($line['payable_from']));
    }

    public function test_a_paid_statement_keeps_its_clawback_lines(): void
    {
        $dealer = $this->makeDealer();
        $this->sale($dealer, $this->boundDevice($dealer), 1000);
        $this->paidThenReversed($dealer, 250);
        $this->pay($dealer);

        $s = $this->statement($dealer);

        $kinds = collect($s['lines'])->pluck('kind')->sort()->values()->all();
        $this->assertSame(['clawback', 'sale'], $kinds);
        $this->assertSame(-25000, $s['clawback_cents']);
        $this->assertSame(75000, $s['net_cents']);
        $this->assertNull(collect($s['lines'])->firstWhere('kind', 'clawback')['payable_from']);
    }

    public function test_a_balance_of_exactly_zero_is_not_paid(): void
    {
        $dealer = $this->makeDealer();
        $this->sale($dealer, $this->boundDevice($dealer), 400);
        $this->paidThenReversed($dealer, 400);

        $this->assertSame(0, $this->statement($dealer)['net_cents']);
        $this->assertRefused('net balance for', fn () => $this->pay($dealer));
        $this->assertSame(0, DealerCommissionPayout::where('dealer_id', $dealer->id)->where('period', $this->month)->count());
    }

    public function test_a_statement_for_a_bad_month_is_refused(): void
    {
        $this->assertRefused('Invalid month.', fn () => $this->statement($this->makeDealer(), 'nope'));
    }

    public function test_the_list_of_paid_statements_is_newest_first_and_per_dealer(): void
    {
        $dealer = $this->makeDealer();
        $other = $this->makeDealer();
        $this->seedPayout($dealer, '2020-01');
        $this->seedPayout($dealer, '2020-03');
        $this->seedPayout($dealer, '2020-02');
        $this->seedPayout($other, '2020-05');

        $this->assertSame(['2020-03', '2020-02', '2020-01'], $this->service()->paidFor($dealer)->pluck('period')->all());
    }

    // ================================================================== the all-dealers summary

    public function test_the_summary_lists_dealers_with_something_to_show_in_name_order_and_skips_the_rest(): void
    {
        $zed = $this->makeDealer(['full_name' => 'Autotest ZZ Summary']);
        $amy = $this->makeDealer(['full_name' => 'Autotest AA Summary']);
        $pendingOnly = $this->makeDealer(['full_name' => 'Autotest BB Summary']);
        $empty = $this->makeDealer(['full_name' => 'Autotest CC Summary']);
        $this->sale($zed, $this->boundDevice($zed), 100);
        $this->sale($amy, $this->boundDevice($amy), 100);
        $this->sale($pendingOnly, $this->boundDevice($pendingOnly, 5), 100);

        $names = collect($this->service()->summary($this->month))->map(fn ($r) => $r['dealer']->full_name)
            ->filter(fn ($n) => str_ends_with($n, 'Summary'))->values()->all();

        $this->assertSame(['Autotest AA Summary', 'Autotest BB Summary', 'Autotest ZZ Summary'], $names);
        $this->assertNotContains($empty->full_name, $names);
    }

    // ================================================================== the old-rate warning

    public function test_the_missing_rate_warning_appears_only_for_unpaid_old_sales_with_no_rate(): void
    {
        DealerCommissionRate::query()->delete();
        DB::table('dealer_commission_ledger')->delete();
        $service = $this->service();
        $this->assertTrue($service->ratesConfigured(), 'nothing waiting: nothing to warn about');

        $dealer = $this->makeDealer();
        $device = $this->boundDevice($dealer);
        $id = $this->sale($dealer, $device, 1000);
        $this->assertFalse($service->ratesConfigured(), 'an unpaid old sale with no rate set');

        DB::table('dealer_commission_ledger')->where('id', $id)->update(['reversed_at' => now()]);
        $this->assertTrue($service->ratesConfigured(), 'a reversed sale does not count');

        DB::table('dealer_commission_ledger')->where('id', $id)->update(['reversed_at' => null, 'payout_id' => $this->seedPayout($dealer, '2020-04')]);
        $this->assertTrue($service->ratesConfigured(), 'a paid sale does not count');

        DB::table('dealer_commission_ledger')->where('id', $id)->update(['payout_id' => null]);
        DealerCommissionRate::create(['dealer_status' => null, 'device_type_id' => null, 'rate' => 1000]);
        $this->assertTrue($service->ratesConfigured(), 'a rate exists');
    }
}