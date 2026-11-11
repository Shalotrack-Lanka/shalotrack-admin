<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\Dealer;
use App\Services\CommissionStatement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * COMMISSION PAGES THAT HAD NO TEST, on real PostgreSQL (autotest schema).
 * (Paying a month and the dealer's single paid statement are already tested in CommissionPayoutTest.)
 *
 *   GET dealer.commission-earnings    a dealer's own earnings, month by month (this month, last 3, 6 or 12), paid or not
 *   GET dealer.commission-statements  the list of statements already PAID to the dealer
 *   GET admin.commission-payouts.show ADMIN: one dealer's statement for a month, with a CSV download
 *
 * What the tests prove (simple words):
 *   - the money adds up: earned, reversed (clawback), already paid and not paid yet, in cents, per month and in total,
 *   - the range accepts only 1, 3, 6 or 12 months; anything else means this month and never crashes,
 *   - a dealer sees only ITS OWN lines and ITS OWN paid statements, whatever it puts in the address; a dealer login
 *     with no dealer record sees nothing,
 *   - only the right role opens each page; a visitor who is not logged in is sent to the login page,
 *   - opening a statement only reads: nothing is paid, claimed or changed,
 *   - an unpaid month rolls older unpaid lines in; a paid month shows what was really paid,
 *   - the CSV cannot run a formula in Excel, and names are shown as text.
 *
 * Money lines are inserted straight into package_payments / commission_entries (the rows a paid activation writes).
 * Everything is rolled back after each test; assertions look only at the dealers made here.
 */
class CommissionEarningsPagesTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins;

    private int $seq = 0;
    private ?Admin $adminLogin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    // ------------------------------------------------------------------ helpers

    private function dealer(string $name = 'Commission Dealer'): Dealer
    {
        $n = ++$this->seq;

        return Dealer::create([
            'full_name'     => "Autotest {$name} {$n}",
            'dealer_status' => 'retailer',
            'region'        => 'colombo',
            'country'       => 'Sri Lanka',
            'contact_email' => "autotest+commpage-{$n}@example.test",
            'status'        => 'active',
            'created_by'    => 'autotest',
        ]);
    }

    private function dealerLogin(Dealer $dealer): Admin
    {
        $admin = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]);
        $this->actingAs($admin);

        return $admin;
    }

    private function adminOpens(string $url)
    {
        $this->adminLogin ??= $this->makeAdmin('ADMIN');
        $this->actingAs($this->adminLogin);

        return $this->get($url);
    }

    /** 'YYYY-MM' of the month $back months before the current one, in Sri Lanka time. */
    private function month(int $back = 0): string
    {
        return now(CommissionStatement::TZ)->startOfMonth()->subMonths($back)->format('Y-m');
    }

    private function occurredIn(string $month): Carbon
    {
        return $month === $this->month(0)
            ? now()->utc()
            : Carbon::createFromFormat('Y-m-d H:i:s', $month . '-10 12:00:00', CommissionStatement::TZ)->utc();
    }

    /** One commission line: a package_payments row plus the commission_entries row that points at it. Returns the entry id. */
    private function line(Dealer $dealer, string $month, float $amount, array $o = []): int
    {
        $n = ++$this->seq;
        $when = $this->occurredIn($month);

        $paymentId = DB::table('package_payments')->insertGetId([
            'imei_number'        => $o['imei'] ?? ('99977' . str_pad((string) $n, 10, '0', STR_PAD_LEFT)),
            'customer_name'      => $o['customer'] ?? "Autotest Customer {$n}",
            'vehicle_number'     => $o['vehicle'] ?? "AUTO-C{$n}",
            'source'             => 'activation',
            'package_model'      => '1 Year',
            'package_label'      => $o['package'] ?? '1 Year Plan',
            'months'             => 12,
            'customer_price'     => 2999,
            'retailer_margin'    => 500,
            'retailer_dealer_id' => $dealer->id,
            'status'             => 'valid',
            'created_at'         => $when,
            'updated_at'         => $when,
        ]);

        return (int) DB::table('commission_entries')->insertGetId([
            'payment_id'   => $paymentId,
            'dealer_id'    => $dealer->id,
            'role'         => $o['role'] ?? 'retailer',
            'entry_type'   => $amount < 0 ? 'clawback' : 'earned',
            'amount'       => $amount,
            'reason'       => $o['reason'] ?? null,
            'period_month' => $month,
            'occurred_at'  => $o['occurred_at'] ?? $when,
            'created_at'   => $when,
            'payout_id'    => $o['payout_id'] ?? null,
        ]);
    }

    /** A paid month, as the pay button would have recorded it. */
    private function payout(Dealer $dealer, string $month, float $net, string $reference = 'AUTOTEST-REF', array $o = []): int
    {
        return (int) DB::table('dealer_commission_payouts')->insertGetId([
            'dealer_id'          => $dealer->id,
            'period'             => $month,
            'period_end'         => CommissionStatement::period($month)['endExclusive'],
            'payable_after_days' => CommissionStatement::PAYABLE_AFTER_DAYS,
            'gross_amount'       => $o['gross'] ?? $net,
            'clawback_amount'    => $o['claw'] ?? 0,
            'net_amount'         => $net,
            'line_count'         => 1,
            'reference'          => $reference,
            'paid_on'            => now(CommissionStatement::TZ)->toDateString(),
            'paid_by_admin_id'   => 'autotest',
            'paid_by_name'       => 'Autotest',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /** Reads the whole body of a streamed CSV download, as a list of rows. */
    private function csvBody($response): array
    {
        ob_start();
        $response->sendContent();
        $text = ob_get_clean();

        return array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', $text), fn ($l) => $l !== '')));
    }

    private function entryCount(Dealer $dealer): int
    {
        return DB::table('commission_entries')->where('dealer_id', $dealer->id)->count();
    }

    // ------------------------------------------------------------------ dealer: my earnings

    public function test_the_totals_add_up_earned_reversed_paid_and_not_paid_yet(): void
    {
        $dealer = $this->dealer();
        $month = $this->month(0);
        $payoutId = $this->payout($dealer, $month, 300);

        $this->line($dealer, $month, 500);                                                     // earned, not paid yet
        $this->line($dealer, $month, 300, ['payout_id' => $payoutId]);                         // earned, already paid
        $this->line($dealer, $month, -200, ['reason' => 'Customer cancelled']);                // reversed, not paid yet

        $this->dealerLogin($dealer);
        $response = $this->get(route('dealer.commission-earnings'))->assertOk();

        $this->assertSame(['earned' => 80000, 'clawback' => -20000, 'paid' => 30000, 'outstanding' => 30000], $response->viewData('total'));
        $this->assertSame(['earned' => 80000, 'clawback' => -20000, 'paid' => 30000, 'outstanding' => 30000], collect($response->viewData('perMonth'))->get($month) ? array_diff_key($response->viewData('perMonth')[$month], ['label' => 1]) : []);
        $this->assertTrue($response->viewData('hasDealer'));
        $response->assertSee('800.00');
        $response->assertSee('200.00');
    }

    public function test_every_line_says_what_it_was_for_and_whether_it_was_paid(): void
    {
        $dealer = $this->dealer();
        $month = $this->month(0);
        $payoutId = $this->payout($dealer, $month, 300, 'AUTOTEST-REF-777');

        $this->line($dealer, $month, 300, ['payout_id' => $payoutId, 'customer' => 'Autotest Paid Customer', 'vehicle' => 'AUTO-PAID-1', 'package' => 'Gold Plan']);
        $this->line($dealer, $month, -100, ['reason' => 'Customer cancelled', 'customer' => 'Autotest Reversed Customer']);

        $this->dealerLogin($dealer);
        $response = $this->get(route('dealer.commission-earnings'))->assertOk();

        $response->assertSee('Autotest Paid Customer');
        $response->assertSee('AUTO-PAID-1');
        $response->assertSee('Gold Plan');
        $response->assertSee('AUTOTEST-REF-777');
        $response->assertSee('Reversed: Customer cancelled');
        $this->assertCount(2, $response->viewData('lines'));
    }

    public function test_the_newest_line_is_first(): void
    {
        $dealer = $this->dealer();
        $this->line($dealer, $this->month(2), 100, ['customer' => 'Autotest Oldest']);
        $this->line($dealer, $this->month(0), 100, ['customer' => 'Autotest Newest']);
        $this->line($dealer, $this->month(1), 100, ['customer' => 'Autotest Middle']);

        $this->dealerLogin($dealer);
        $lines = $this->get(route('dealer.commission-earnings', ['range' => 3]))->assertOk()->viewData('lines');

        $this->assertSame(['Autotest Newest', 'Autotest Middle', 'Autotest Oldest'], $lines->pluck('customer_name')->all());
    }

    public static function ranges(): array
    {
        // [query, how many months are shown]
        return [
            'default'         => ['', 1],
            'this month'      => ['?range=1', 1],
            'last 3 months'   => ['?range=3', 3],
            'last 6 months'   => ['?range=6', 6],
            'last 12 months'  => ['?range=12', 12],
            'not a choice'    => ['?range=2', 1],
            'zero'            => ['?range=0', 1],
            'negative'        => ['?range=-6', 1],
            'text'            => ['?range=abc', 1],
            'huge'            => ['?range=99999999999999999999', 1],
            'a list'          => ['?range[]=12', 1],
            'empty'           => ['?range=', 1],
        ];
    }

    #[DataProvider('ranges')]
    public function test_the_range_shows_only_the_months_asked_for(string $query, int $months): void
    {
        $dealer = $this->dealer();
        $this->dealerLogin($dealer);

        $response = $this->get(route('dealer.commission-earnings') . $query)->assertOk();

        $this->assertCount($months, $response->viewData('perMonth'));
        $this->assertSame($this->month(0), array_key_first($response->viewData('perMonth')), 'the newest month is first');
    }

    public function test_lines_outside_the_range_are_not_counted(): void
    {
        $dealer = $this->dealer();
        $this->line($dealer, $this->month(0), 100);
        $this->line($dealer, $this->month(2), 200);
        $this->line($dealer, $this->month(5), 400);
        $this->line($dealer, $this->month(14), 800);   // older than a year: never shown

        $this->dealerLogin($dealer);

        $this->assertSame(10000, $this->get(route('dealer.commission-earnings'))->viewData('total')['earned']);
        $this->assertSame(30000, $this->get(route('dealer.commission-earnings', ['range' => 3]))->viewData('total')['earned']);
        $this->assertSame(70000, $this->get(route('dealer.commission-earnings', ['range' => 6]))->viewData('total')['earned']);
        $this->assertSame(70000, $this->get(route('dealer.commission-earnings', ['range' => 12]))->viewData('total')['earned']);
    }

    public function test_a_dealer_sees_only_its_own_lines(): void
    {
        $mine = $this->dealer();
        $other = $this->dealer();
        $this->line($mine, $this->month(0), 100, ['customer' => 'Autotest My Customer']);
        $this->line($other, $this->month(0), 9000, ['customer' => 'Autotest Their Customer']);
        $otherPayout = $this->payout($other, $this->month(0), 9000, 'AUTOTEST-THEIR-REF');
        $this->line($other, $this->month(0), 50, ['payout_id' => $otherPayout]);

        $this->dealerLogin($mine);
        $response = $this->get(route('dealer.commission-earnings', ['range' => 12, 'dealer_id' => $other->id, 'dealer' => $other->id]))->assertOk();

        $this->assertSame(10000, $response->viewData('total')['earned']);
        $response->assertSee('Autotest My Customer');
        $response->assertDontSee('Autotest Their Customer');
        $response->assertDontSee('AUTOTEST-THEIR-REF');
    }

    public function test_a_dealer_login_with_no_dealer_record_sees_nothing_not_everyone(): void
    {
        $other = $this->dealer();
        $this->line($other, $this->month(0), 5000, ['customer' => 'Autotest Not For Strangers']);

        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => null]));
        $response = $this->get(route('dealer.commission-earnings', ['range' => 12]))->assertOk();

        $this->assertFalse($response->viewData('hasDealer'));
        $this->assertSame(['earned' => 0, 'clawback' => 0, 'paid' => 0, 'outstanding' => 0], $response->viewData('total'));
        $response->assertDontSee('Autotest Not For Strangers');
    }

    public function test_a_dealer_with_nothing_earned_gets_a_page_with_zeros(): void
    {
        $this->dealerLogin($this->dealer());

        $response = $this->get(route('dealer.commission-earnings'))->assertOk();

        $this->assertSame(['earned' => 0, 'clawback' => 0, 'paid' => 0, 'outstanding' => 0], $response->viewData('total'));
        $this->assertCount(0, $response->viewData('lines'));
    }

    public function test_a_script_in_a_customer_name_or_reason_is_shown_as_text(): void
    {
        $dealer = $this->dealer();
        $this->line($dealer, $this->month(0), 100, ['customer' => 'Autotest <script>alert(1)</script> Customer']);
        $this->line($dealer, $this->month(0), -50, ['reason' => '<img src=x onerror=alert(2)>']);

        $this->dealerLogin($dealer);
        $response = $this->get(route('dealer.commission-earnings'))->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('<img src=x onerror=alert(2)>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    // ------------------------------------------------------------------ dealer: paid statements list

    public function test_the_list_shows_only_paid_statements_newest_first(): void
    {
        $dealer = $this->dealer();
        $this->payout($dealer, $this->month(3), 100, 'AUTOTEST-OLD');
        $this->payout($dealer, $this->month(1), 700, 'AUTOTEST-NEW');
        $this->line($dealer, $this->month(2), 999);   // earned in a month that was never paid: not a statement

        $this->dealerLogin($dealer);
        $response = $this->get(route('dealer.commission-statements'))->assertOk();

        $this->assertSame([$this->month(1), $this->month(3)], $response->viewData('payouts')->pluck('period')->all());
        $response->assertSeeInOrder(['AUTOTEST-NEW', 'AUTOTEST-OLD']);
        $response->assertSee('700.00');
        $response->assertDontSee('999.00');
        $response->assertSee(route('dealer.commission-statement', $this->month(1)), false);
    }

    public function test_the_list_says_so_when_nothing_has_been_paid_yet(): void
    {
        $dealer = $this->dealer();
        $this->line($dealer, $this->month(1), 500);

        $this->dealerLogin($dealer);

        $this->get(route('dealer.commission-statements'))->assertOk()->assertSee('No paid statements yet.');
    }

    public function test_the_list_never_shows_another_dealers_statement(): void
    {
        $mine = $this->dealer();
        $other = $this->dealer();
        $this->payout($mine, $this->month(1), 100, 'AUTOTEST-MINE');
        $this->payout($other, $this->month(1), 5000, 'AUTOTEST-THEIRS');

        $this->dealerLogin($mine);
        $response = $this->get(route('dealer.commission-statements', ['dealer_id' => $other->id]))->assertOk();

        $this->assertCount(1, $response->viewData('payouts'));
        $response->assertSee('AUTOTEST-MINE');
        $response->assertDontSee('AUTOTEST-THEIRS');
    }

    public function test_a_dealer_login_with_no_dealer_record_has_an_empty_list(): void
    {
        $other = $this->dealer();
        $this->payout($other, $this->month(1), 5000, 'AUTOTEST-NOT-YOURS');

        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => null]));
        $response = $this->get(route('dealer.commission-statements'))->assertOk();

        $this->assertCount(0, $response->viewData('payouts'));
        $response->assertDontSee('AUTOTEST-NOT-YOURS');
    }

    public function test_a_script_in_a_payment_reference_is_shown_as_text(): void
    {
        $dealer = $this->dealer();
        $this->payout($dealer, $this->month(1), 100, '<script>alert(3)</script>');

        $this->dealerLogin($dealer);
        $response = $this->get(route('dealer.commission-statements'))->assertOk();

        $response->assertDontSee('<script>alert(3)</script>', false);
        $response->assertSee('&lt;script&gt;alert(3)&lt;/script&gt;', false);
    }

    public static function badMonths(): array
    {
        return [
            'not a month'   => ['abc'],
            'month 13'      => ['2026-13'],
            'month 00'      => ['2026-00'],
            'too early'     => ['1999-01'],
            'too late'      => ['2101-01'],
            'wrong shape'   => ['2026-1'],
            'with a day'    => ['2026-01-15'],
            'sql in a value' => ["2026-01'%20or%20'1'='1"],
        ];
    }

    #[DataProvider('badMonths')]
    public function test_a_statement_for_a_month_that_cannot_exist_is_not_found(string $month): void
    {
        $this->dealerLogin($this->dealer());

        $this->get('/dealer/commission-statements/' . $month)->assertNotFound();
    }

    public function test_the_paid_statement_shows_what_was_really_paid(): void
    {
        $dealer = $this->dealer();
        $month = $this->month(1);
        $payoutId = $this->payout($dealer, $month, 500, 'AUTOTEST-FROZEN');
        $this->line($dealer, $month, 500, ['payout_id' => $payoutId, 'customer' => 'Autotest Frozen Customer']);

        $this->dealerLogin($dealer);
        $response = $this->get(route('dealer.commission-statement', $month))->assertOk();

        $response->assertSee('AUTOTEST-FROZEN');
        $response->assertSee('Autotest Frozen Customer');
        $this->assertSame(50000, $response->viewData('s')['net_cents']);
    }

    // ------------------------------------------------------------------ admin: one dealer's statement

    public function test_the_admin_sees_a_dealers_open_statement_for_a_month_that_has_ended(): void
    {
        $dealer = $this->dealer();
        $month = $this->month(1);
        $this->line($dealer, $month, 500, ['customer' => 'Autotest Statement Customer', 'package' => 'Gold Plan']);
        $this->line($dealer, $month, -100, ['reason' => 'Payment reversed']);

        $response = $this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $month]))->assertOk();
        $s = $response->viewData('s');

        $this->assertSame($month, $response->viewData('month'));
        $this->assertSame('ready', $s['status']);
        $this->assertNull($s['payout']);
        $this->assertSame(50000, $s['gross_cents']);
        $this->assertSame(-10000, $s['clawback_cents']);
        $this->assertSame(40000, $s['net_cents']);
        $this->assertCount(2, $s['lines']);
        $response->assertSee($dealer->full_name);
        $response->assertSee('Autotest Statement Customer');
        $response->assertSee('400.00');
    }

    public function test_a_month_that_has_not_ended_is_only_a_preview(): void
    {
        $dealer = $this->dealer();
        $this->line($dealer, $this->month(0), 500);

        $s = $this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $this->month(0)]))->assertOk()->viewData('s');

        $this->assertSame('open', $s['status']);
        $this->assertFalse($s['period']['ended']);
    }

    public function test_a_month_with_no_lines_says_nothing_and_a_zero_net_is_negative(): void
    {
        $empty = $this->dealer();
        $netZero = $this->dealer();
        $month = $this->month(1);
        $this->line($netZero, $month, 500);
        $this->line($netZero, $month, -500);

        $this->assertSame('nothing', $this->adminOpens(route('admin.commission-payouts.show', [$empty, 'month' => $month]))->viewData('s')['status']);
        $this->assertSame('negative', $this->adminOpens(route('admin.commission-payouts.show', [$netZero, 'month' => $month]))->viewData('s')['status']);
    }

    public function test_older_unpaid_lines_roll_into_the_next_statement(): void
    {
        $dealer = $this->dealer();
        $this->line($dealer, $this->month(3), 300, ['customer' => 'Autotest Rolled Over']);

        $s = $this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $this->month(1)]))->viewData('s');

        $this->assertSame(30000, $s['net_cents'], 'an old line that was never paid is still owed');
    }

    public function test_a_line_recorded_after_the_month_ended_is_not_in_that_statement(): void
    {
        $dealer = $this->dealer();
        $this->line($dealer, $this->month(0), 300);   // recorded this month

        $s = $this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $this->month(1)]))->viewData('s');

        $this->assertSame('nothing', $s['status']);
        $this->assertSame(0, $s['net_cents']);
    }

    public function test_a_paid_month_shows_the_amount_that_was_really_paid(): void
    {
        $dealer = $this->dealer();
        $month = $this->month(1);
        $payoutId = $this->payout($dealer, $month, 450, 'AUTOTEST-PAID-REF', ['gross' => 500, 'claw' => -50]);
        $this->line($dealer, $month, 500, ['payout_id' => $payoutId]);
        $this->line($dealer, $month, 999);   // recorded afterwards for the same month: must not change a paid statement

        $s = $this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $month]))->assertOk()->assertSee('AUTOTEST-PAID-REF')->viewData('s');

        $this->assertSame('paid', $s['status']);
        $this->assertSame(50000, $s['gross_cents']);
        $this->assertSame(-5000, $s['clawback_cents']);
        $this->assertSame(45000, $s['net_cents']);
        $this->assertCount(1, $s['lines'], 'only what that payout claimed');
    }

    public function test_a_bad_or_missing_month_means_the_last_month_that_ended(): void
    {
        $dealer = $this->dealer();

        foreach (['', '?month=abc', '?month=2026-13', '?month[]=2026-01', '?month=' . str_repeat('9', 500)] as $query) {
            $response = $this->adminOpens(route('admin.commission-payouts.show', $dealer) . $query)->assertOk();

            $this->assertSame(CommissionStatement::lastEndedMonth(), $response->viewData('month'), "for {$query}");
        }
    }

    public function test_an_unknown_dealer_is_not_found(): void
    {
        $this->adminOpens('/admin/commission-payouts/99999999')->assertNotFound();
        $this->adminOpens('/admin/commission-payouts/abc')->assertNotFound();
    }

    public function test_opening_a_statement_changes_nothing(): void
    {
        $dealer = $this->dealer();
        $month = $this->month(1);
        $entry = $this->line($dealer, $month, 500);
        $payouts = DB::table('dealer_commission_payouts')->where('dealer_id', $dealer->id)->count();

        $this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $month]))->assertOk();
        $this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $month, 'export' => 'csv']))->assertOk();

        $this->assertSame($payouts, DB::table('dealer_commission_payouts')->where('dealer_id', $dealer->id)->count());
        $this->assertNull(DB::table('commission_entries')->where('id', $entry)->value('payout_id'), 'viewing must not claim a line');
        $this->assertSame(1, $this->entryCount($dealer));
    }

    // ------------------------------------------------------------------ admin: CSV

    public function test_the_csv_has_the_statement_lines_and_the_totals(): void
    {
        $dealer = $this->dealer();
        $month = $this->month(1);
        $this->line($dealer, $month, 500, ['customer' => 'Autotest Csv Customer', 'vehicle' => 'AUTO-CSV-1', 'package' => 'Gold Plan']);
        $this->line($dealer, $month, -100, ['reason' => 'Payment reversed']);

        $response = $this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $month, 'export' => 'csv']))->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("commission-{$dealer->id}-{$month}.csv", $response->headers->get('Content-Disposition'));

        $lines = $this->csvBody($response);
        $this->assertSame(['Dealer', $dealer->full_name], $lines[0]);
        $this->assertSame('Period', $lines[1][0]);
        $this->assertSame(['Status', 'ready'], $lines[2]);

        $sale = collect($lines)->first(fn ($l) => ($l[2] ?? '') === 'Autotest Csv Customer');
        $this->assertNotNull($sale);
        $this->assertSame('Retailer margin (Gold Plan)', $sale[0]);
        $this->assertSame('AUTO-CSV-1', $sale[3]);
        $this->assertSame('500.00', $sale[7]);

        $this->assertSame('500.00', collect($lines)->first(fn ($l) => $l[0] === 'Gross sales')[7]);
        $this->assertSame('-100.00', collect($lines)->first(fn ($l) => $l[0] === 'Clawbacks')[7]);
        $this->assertSame('400.00', collect($lines)->first(fn ($l) => $l[0] === 'Net')[7]);
        $this->assertNotNull(collect($lines)->first(fn ($l) => str_starts_with((string) $l[0], 'Clawback: Retailer margin')));
    }

    public function test_the_csv_of_a_paid_month_says_when_and_how_it_was_paid(): void
    {
        $dealer = $this->dealer();
        $month = $this->month(1);
        $payoutId = $this->payout($dealer, $month, 500, 'AUTOTEST-CSV-REF');
        $this->line($dealer, $month, 500, ['payout_id' => $payoutId]);

        $lines = $this->csvBody($this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $month, 'export' => 'csv'])));

        $this->assertSame(['Status', 'paid'], $lines[2]);
        $this->assertSame('Paid on', $lines[3][0]);
        $this->assertSame(['Reference', 'AUTOTEST-CSV-REF'], $lines[4]);
    }

    public function test_a_formula_in_a_name_cannot_run_in_excel(): void
    {
        $dealer = $this->dealer();
        $dealer->forceFill(['full_name' => '=HYPERLINK("http://evil.example","pay")'])->save();
        $month = $this->month(1);
        $this->line($dealer, $month, 500, ['customer' => '+cmd|calc', 'vehicle' => '@SUM(1)']);

        $lines = $this->csvBody($this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $month, 'export' => 'csv'])));

        $this->assertStringStartsWith("'=", $lines[0][1]);
        $row = collect($lines)->first(fn ($l) => str_contains((string) ($l[2] ?? ''), 'cmd|calc'));
        $this->assertNotNull($row);
        $this->assertStringStartsWith("'+", $row[2]);
        $this->assertStringStartsWith("'@", $row[3]);
    }

    public function test_a_script_in_a_dealer_or_customer_name_is_shown_as_text(): void
    {
        $dealer = $this->dealer('<script>alert(4)</script> Dealer');
        $month = $this->month(1);
        $this->line($dealer, $month, 500, ['customer' => '<script>alert(5)</script>']);

        $response = $this->adminOpens(route('admin.commission-payouts.show', [$dealer, 'month' => $month]))->assertOk();

        $response->assertDontSee('<script>alert(4)</script>', false);
        $response->assertDontSee('<script>alert(5)</script>', false);
    }

    // ------------------------------------------------------------------ who may open what

    public static function rolesThatAreNotDealers(): array
    {
        return ['ADMIN' => ['ADMIN'], 'DISTRIBUTOR' => ['DISTRIBUTOR'], 'FINANCE' => ['FINANCE'], 'TECHNICIAN' => ['TECHNICIAN'], 'SUPPLIER' => ['SUPPLIER']];
    }

    #[DataProvider('rolesThatAreNotDealers')]
    public function test_only_a_dealer_opens_the_dealer_commission_pages(string $role): void
    {
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('dealer.commission-earnings'))->assertForbidden();
        $this->get(route('dealer.commission-statements'))->assertForbidden();
        $this->get(route('dealer.commission-statement', $this->month(1)))->assertForbidden();
    }

    public static function otherRolesForAdminPage(): array
    {
        return ['DEALER' => ['DEALER'], 'DISTRIBUTOR' => ['DISTRIBUTOR'], 'FINANCE' => ['FINANCE'], 'TECHNICIAN' => ['TECHNICIAN'], 'SUPPLIER' => ['SUPPLIER']];
    }

    #[DataProvider('otherRolesForAdminPage')]
    public function test_only_an_admin_opens_a_dealers_payout_statement(string $role): void
    {
        $dealer = $this->dealer();
        $this->line($dealer, $this->month(1), 500);
        $this->actingAs($this->makeAdmin($role, $role === 'DEALER' ? ['dealer_id' => $dealer->id] : []));

        $this->get(route('admin.commission-payouts.show', $dealer))->assertForbidden();
        $this->get(route('admin.commission-payouts.show', [$dealer, 'export' => 'csv']))->assertForbidden();
    }

    public function test_a_visitor_who_is_not_logged_in_is_sent_to_the_login_page(): void
    {
        $dealer = $this->dealer();

        $this->get(route('dealer.commission-earnings'))->assertRedirect(route('login'));
        $this->get(route('dealer.commission-statements'))->assertRedirect(route('login'));
        $this->get(route('admin.commission-payouts.show', $dealer))->assertRedirect(route('login'));
    }
}