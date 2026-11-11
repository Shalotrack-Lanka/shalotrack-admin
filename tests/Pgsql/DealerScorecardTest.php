<?php

namespace Tests\Pgsql;

use App\Models\ActivatedDevice;
use App\Models\Dealer;
use App\Models\SetupShalotrackDevice;
use App\Services\DealerScorecard;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * DEALER SCORECARD, on real PostgreSQL (autotest schema).
 *
 *   GET admin.dealer-scorecard   ADMIN: every dealer side by side, sortable, with a CSV download
 *   GET dealer.scorecard         DEALER: only the dealer's OWN row
 *
 * What the tests prove (simple words):
 *   - the numbers are right: sold (this period and all time), active rate, lapsed, stock, stock waiting too long,
 *     "awaiting bind", faulty devices,
 *   - complaint figures come from the app server (faked here); if that server is down the page says "unavailable",
 *     it never shows 0 (a zero would look like "no complaints"),
 *   - the plain flags appear only when their rule is met,
 *   - the period and sort choices only accept the listed values; strange values fall back to the default and never crash,
 *   - a dealer sees only itself, whatever it puts in the address; a dealer login with no dealer record sees nothing,
 *   - the CSV cannot run a formula when opened in Excel, and a name with a script in it is shown as text.
 *
 * The app server is FAKED (no real network). Rows are tagged "Autotest", assertions look only at the dealers made
 * here (never at table totals), and everything is rolled back after each test.
 */
class DealerScorecardTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    /** What the faked app server answers. Changed by the helpers below, so a test can switch it mid-way. */
    private array $apiList = [];
    private bool $apiDown = false;

    /** One login per test (a second login mid-test is logged out by AuthenticateSession). */
    private $adminLogin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Http::fake(fn () => $this->apiDown
            ? Http::response(['message' => 'boom'], 500)
            : Http::response(['data' => $this->apiList], 200));
    }

    // ------------------------------------------------------------------ helpers

    /** The app server answers complaints with this list (same list for every dealer id). */
    private function fakeComplaints(array $list): void
    {
        $this->apiDown = false;
        $this->apiList = $list;
    }

    private function fakeComplaintsDown(): void
    {
        $this->apiDown = true;
    }

    private function complaint(string $createdAgo, bool $escalated = false, ?int $status = 0, ?int $resolvedAfterHours = null): array
    {
        $created = Carbon::now('UTC')->sub($createdAgo);

        return [
            'complaintId' => (string) Str::uuid(),
            'status'      => $status,
            'createdAt'   => $created->format('Y-m-d\TH:i:s'),
            'escalatedAt' => $escalated ? $created->copy()->addHour()->format('Y-m-d\TH:i:s') : null,
            'resolvedAt'  => $resolvedAfterHours !== null ? $created->copy()->addHours($resolvedAfterHours)->format('Y-m-d\TH:i:s') : null,
        ];
    }

    /** A device the dealer holds that is NOT bound to a customer yet. */
    private function held(Dealer $dealer, string $status = 'Activated', ?Carbon $allocatedAt = null): SetupShalotrackDevice
    {
        $n = $this->nextIntakeNumber();

        return SetupShalotrackDevice::forceCreate([
            'device_category' => 'AUTOTEST-CAT',
            'imei_number'     => $this->validImei(),
            'sim_number'      => '07' . str_pad((string) $n, 9, '0', STR_PAD_LEFT),
            'status'          => $status,
            'dealer_id'       => $dealer->id,
            'allocated_at'    => $allocatedAt,
        ]);
    }

    /** A device bound to a customer vehicle. $boundDaysAgo moves the bind date back. */
    private function sold(Dealer $dealer, bool $paid, int $boundDaysAgo = 1): ActivatedDevice
    {
        $device = $this->makeBound(['payment_status' => $paid ? 'Paid' : 'not-Paid'], $dealer);
        DB::table('activated_devices')
            ->where('activated_device_id', $device->activated_device_id)
            ->update(['created_at' => now()->subDays($boundDaysAgo)]);

        return $device;
    }

    private function row($response, Dealer $dealer): array
    {
        $rows = $response->viewData('rows');
        $this->assertArrayHasKey($dealer->id, $rows, 'the dealer is missing from the scorecard');

        return $rows[$dealer->id];
    }

    private function adminOpens(string $query = '')
    {
        $this->adminLogin ??= $this->makeAdmin('ADMIN');
        $this->actingAs($this->adminLogin);

        return $this->get(route('admin.dealer-scorecard') . $query);
    }

    // ------------------------------------------------------------------ the numbers

    public function test_the_numbers_for_one_dealer_are_right(): void
    {
        $this->fakeComplaints([]);
        $dealer = $this->makeDealer();

        $this->sold($dealer, true, 5);      // sold this period, paid
        $this->sold($dealer, true, 30);     // sold this period, paid
        $this->sold($dealer, false, 200);   // sold long ago, not paid (outside the 90-day period)

        $this->held($dealer, 'Activated', now()->subDays(100));   // in stock, unsold for 100 days: too long
        $this->held($dealer, 'Activated', now()->subDays(10));    // in stock, recent
        $this->held($dealer, 'Activated', null);                  // in stock, no allocation date: no age
        $this->held($dealer, 'Assigned to Customer');             // waiting for staff to bind
        $this->held($dealer, 'Pending Repair');                   // faulty
        $this->held($dealer, 'Broken Device');                    // faulty

        $recent = $this->sold($dealer, true, 200);                // a third paid sale, long ago
        $this->makeExpiredRecord($recent, now()->subDays(10)->toDateString());     // lapsed this period
        $this->makeExpiredRecord($this->sold($dealer, false, 300), now()->subDays(200)->toDateString());   // lapsed long ago

        $row = $this->row($this->adminOpens(), $dealer);

        $this->assertSame(5, $row['sold_total'], 'sold, all time');
        $this->assertSame(2, $row['sold_window'], 'sold in the last 90 days');
        $this->assertSame(3, $row['active'], 'sold devices whose subscription is Paid');
        $this->assertSame(60.0, (float) $row['active_rate']);
        $this->assertSame(1, $row['lapsed_window'], 'only the lapse inside the period counts');
        $this->assertSame(3, $row['stock']);
        $this->assertSame(1, $row['awaiting']);
        $this->assertSame(2, $row['faulty']);
        $this->assertSame(1, $row['stale_stock'], 'only the device held for more than 60 days');
        $this->assertGreaterThanOrEqual(100, $row['oldest_stock_days']);
        $this->assertSame($row['allocated'], 5 + 3 + 1 + 2, 'every device the dealer holds, bound or not');
    }

    public function test_a_dealer_with_nothing_has_zeros_and_no_rates(): void
    {
        $this->fakeComplaints([]);
        $dealer = $this->makeDealer();

        $row = $this->row($this->adminOpens(), $dealer);

        $this->assertSame(0, $row['sold_total']);
        $this->assertSame(0, $row['stock']);
        $this->assertNull($row['active_rate'], 'no sales: a rate would be a made-up number');
        $this->assertNull($row['fault_rate']);
        $this->assertNull($row['oldest_stock_days']);
        $this->assertSame([], $row['flags']);
    }

    public function test_another_dealers_devices_are_never_counted(): void
    {
        $this->fakeComplaints([]);
        $mine = $this->makeDealer();
        $other = $this->makeDealer();
        $this->sold($mine, true);
        $this->sold($other, true);
        $this->sold($other, true);
        $this->held($other);

        $response = $this->adminOpens();

        $this->assertSame(1, $this->row($response, $mine)['sold_total']);
        $this->assertSame(0, $this->row($response, $mine)['stock']);
        $this->assertSame(2, $this->row($response, $other)['sold_total']);
    }

    public function test_the_period_changes_what_counts_as_recent(): void
    {
        $this->fakeComplaints([]);
        $dealer = $this->makeDealer();
        $this->sold($dealer, true, 20);
        $this->sold($dealer, true, 60);
        $this->sold($dealer, true, 150);

        $r30 = $this->adminOpens('?days=30');
        $this->assertSame(30, $r30->viewData('days'));
        $this->assertSame(1, $this->row($r30, $dealer)['sold_window']);

        $this->assertSame(2, $this->row($this->adminOpens('?days=90'), $dealer)['sold_window']);
        $this->assertSame(3, $this->row($this->adminOpens('?days=180'), $dealer)['sold_window']);
        $this->assertSame(3, $this->row($this->adminOpens('?days=180'), $dealer)['sold_total']);
    }

    public static function strangePeriods(): array
    {
        return [
            'text'            => ['?days=abc'],
            'zero'            => ['?days=0'],
            'not a listed one' => ['?days=45'],
            'negative'        => ['?days=-30'],
            'huge'            => ['?days=99999999999999999999'],
            'a list'          => ['?days[]=30&days[]=90'],
            'empty'           => ['?days='],
        ];
    }

    #[DataProvider('strangePeriods')]
    public function test_a_strange_period_falls_back_to_90_days_and_never_crashes(string $query): void
    {
        $this->fakeComplaints([]);
        $this->makeDealer();

        $response = $this->adminOpens($query)->assertOk();

        $this->assertSame(DealerScorecard::DEFAULT_WINDOW, $response->viewData('days'));
    }

    // ------------------------------------------------------------------ sorting

    public function test_the_table_sorts_by_name_and_by_sold_in_both_directions(): void
    {
        $this->fakeComplaints([]);
        $a = $this->makeDealer(['full_name' => 'Autotest Scorecard AAA']);
        $z = $this->makeDealer(['full_name' => 'Autotest Scorecard ZZZ']);
        $this->sold($a, true);
        $this->sold($z, true);
        $this->sold($z, true);

        $order = fn ($response) => array_values(array_intersect(array_keys($response->viewData('rows')), [$a->id, $z->id]));

        $this->assertSame([$a->id, $z->id], $order($this->adminOpens('?sort=name&dir=asc')));
        $this->assertSame([$z->id, $a->id], $order($this->adminOpens('?sort=name&dir=desc')));
        $this->assertSame([$z->id, $a->id], $order($this->adminOpens('?sort=sold_window&dir=desc')), 'most sold first');
        $this->assertSame([$a->id, $z->id], $order($this->adminOpens('?sort=sold_window&dir=asc')));
    }

    public static function strangeSorts(): array
    {
        return [
            'not a column'   => ['?sort=password'],
            'sql in a value' => ["?sort=name;drop%20table%20dealers"],
            'a list'         => ['?sort[]=name'],
            'bad direction'  => ['?sort=name&dir=sideways'],
            'very long text' => ['?sort=' . str_repeat('x', 5000) . '&dir=asc'],
        ];
    }

    #[DataProvider('strangeSorts')]
    public function test_a_strange_sort_is_ignored_and_never_crashes(string $query): void
    {
        $this->fakeComplaints([]);
        $this->makeDealer();

        $response = $this->adminOpens($query)->assertOk();

        $this->assertContains($response->viewData('sort'), DealerScorecard::SORTS);
        $this->assertContains($response->viewData('dir'), ['asc', 'desc']);
    }

    public function test_unknown_values_sort_last_in_both_directions(): void
    {
        $rows = [
            1 => ['name' => 'B', 'complaints' => 5, 'flags' => []],
            2 => ['name' => 'A', 'complaints' => null, 'flags' => []],
            3 => ['name' => 'C', 'complaints' => 9, 'flags' => []],
        ];

        $this->assertSame([3, 1, 2], array_keys(DealerScorecard::sort($rows, 'complaints', 'desc')));
        $this->assertSame([1, 3, 2], array_keys(DealerScorecard::sort($rows, 'complaints', 'asc')));
    }

    // ------------------------------------------------------------------ complaints (from the app server)

    public function test_the_complaint_figures_come_from_the_app_server(): void
    {
        $this->fakeComplaints([
            $this->complaint('10 days', true, 2, 5),    // filed 10 days ago, escalated, resolved after 5 hours
            $this->complaint('20 days', false, 3, 3),   // filed 20 days ago, closed, resolved after 3 hours
            $this->complaint('2 days', false, 0),       // open with the dealer
            $this->complaint('300 days', false, 1),     // old, but still open now
        ]);
        $dealer = $this->makeDealer();
        $this->sold($dealer, true);
        $this->sold($dealer, true);

        $row = $this->row($this->adminOpens(), $dealer);

        $this->assertSame(3, $row['complaints'], 'filed in the last 90 days');
        $this->assertSame(2, $row['complaints_open'], 'open right now, whenever they were filed');
        $this->assertSame(1, $row['complaints_escalated']);
        $this->assertSame(4.0, (float) $row['complaints_avg_hours']);
        $this->assertSame(150.0, (float) $row['complaints_per_100'], '3 complaints for 2 devices sold');
    }

    public function test_when_the_app_server_is_down_complaints_say_unavailable_and_not_zero(): void
    {
        $this->fakeComplaintsDown();
        $dealer = $this->makeDealer();

        $response = $this->adminOpens()->assertOk();
        $row = $this->row($response, $dealer);

        $this->assertNull($row['complaints']);
        $this->assertNull($row['complaints_open']);
        $this->assertNull($row['complaints_per_100']);
        $this->assertTrue($response->viewData('complaintsDown'));
        // The rest of the scorecard still works without it.
        $this->assertSame(0, $row['sold_total']);
    }

    public function test_a_failed_complaints_fetch_is_not_remembered(): void
    {
        $dealer = $this->makeDealer();

        $this->fakeComplaintsDown();
        $this->assertNull($this->row($this->adminOpens(), $dealer)['complaints']);

        // The server is back: the next visit must ask again, not reuse the failure.
        $this->fakeComplaints([]);
        $this->assertSame(0, $this->row($this->adminOpens(), $dealer)['complaints']);
    }

    // ------------------------------------------------------------------ the plain flags

    public function test_a_flag_says_how_many_devices_have_been_unsold_for_too_long(): void
    {
        $this->fakeComplaints([]);
        $dealer = $this->makeDealer();
        $this->held($dealer, 'Activated', now()->subDays(90));
        $this->held($dealer, 'Activated', now()->subDays(75));
        $this->held($dealer, 'Activated', now()->subDays(5));

        $flags = $this->row($this->adminOpens(), $dealer)['flags'];

        $this->assertContains('2 devices unsold over 60 days', $flags);
    }

    public function test_a_low_active_rate_is_flagged_only_after_enough_sales(): void
    {
        $this->fakeComplaints([]);
        $few = $this->makeDealer();
        $many = $this->makeDealer();
        foreach (range(1, 4) as $i) {
            $this->sold($few, false);         // 0% active, but only 4 sold: too few to judge
        }
        foreach (range(1, 5) as $i) {
            $this->sold($many, $i === 1);     // 1 of 5 active = 20%
        }

        $response = $this->adminOpens();

        $this->assertNotContains('Active rate under 70%', $this->row($response, $few)['flags']);
        $this->assertContains('Active rate under 70%', $this->row($response, $many)['flags']);
    }

    public function test_many_escalations_are_flagged_only_after_enough_complaints(): void
    {
        $dealer = $this->makeDealer();

        $this->fakeComplaints([
            $this->complaint('1 days', true), $this->complaint('2 days', true), $this->complaint('3 days', true),
            $this->complaint('4 days', false),
        ]);
        $this->assertNotContains('Escalates 50%+ of complaints', $this->row($this->adminOpens(), $dealer)['flags'], 'only 4 complaints');

        \Illuminate\Support\Facades\Cache::forget('dealer_scorecard_complaints_' . $dealer->id);   // the first answer is remembered for 15 minutes
        $this->fakeComplaints([
            $this->complaint('1 days', true), $this->complaint('2 days', true), $this->complaint('3 days', true),
            $this->complaint('4 days', false), $this->complaint('5 days', false),
        ]);
        $this->assertContains('Escalates 50%+ of complaints', $this->row($this->adminOpens(), $dealer)['flags'], '3 of 5 is 60%');
    }

    // ------------------------------------------------------------------ CSV

    public function test_the_csv_download_has_a_header_and_one_line_per_dealer(): void
    {
        $this->fakeComplaints([]);
        $dealer = $this->makeDealer(['full_name' => 'Autotest Csv Dealer']);
        $this->sold($dealer, true);

        $response = $this->adminOpens('?export=csv&days=30');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('dealer-scorecard-30d-', $response->headers->get('Content-Disposition'));

        $lines = $this->csvBody($response);
        $this->assertSame('Dealer', $lines[0][0]);
        $mine = collect($lines)->first(fn ($l) => $l[0] === 'Autotest Csv Dealer');
        $this->assertNotNull($mine, 'the dealer is not in the file');
        $this->assertSame('1', $mine[4], 'Sold (all time)');
    }

    public function test_a_formula_in_a_dealer_name_cannot_run_in_excel(): void
    {
        $this->fakeComplaints([]);
        $this->makeDealer(['full_name' => '=HYPERLINK("http://evil.example","click")']);

        $lines = $this->csvBody($this->adminOpens('?export=csv'));

        $cells = collect($lines)->pluck(0)->filter(fn ($c) => str_contains((string) $c, 'HYPERLINK'));
        $this->assertCount(1, $cells);
        $this->assertStringStartsWith("'=", $cells->first(), 'the cell must not start with =');
    }

    public function test_the_csv_says_unavailable_when_the_app_server_is_down(): void
    {
        $this->fakeComplaintsDown();
        $this->makeDealer(['full_name' => 'Autotest Csv Down']);

        $lines = $this->csvBody($this->adminOpens('?export=csv'));

        $mine = collect($lines)->first(fn ($l) => $l[0] === 'Autotest Csv Down');
        $this->assertSame('unavailable', $mine[15]);
    }

    // ------------------------------------------------------------------ a dealer sees only itself

    public function test_a_dealer_sees_only_its_own_row(): void
    {
        $this->fakeComplaints([]);
        $mine = $this->makeDealer(['full_name' => 'Autotest Mine Scorecard']);
        $other = $this->makeDealer(['full_name' => 'Autotest Other Scorecard']);
        $this->sold($mine, true);
        $this->sold($other, true);
        $this->sold($other, true);

        $this->actingAs($this->makeDealerLogin($mine));
        $response = $this->get(route('dealer.scorecard'))->assertOk();

        $this->assertSame([$mine->id], array_keys($response->viewData('rows')));
        $this->assertSame(1, $response->viewData('rows')[$mine->id]['sold_total']);
        $response->assertSee('Autotest Mine Scorecard');
        $response->assertDontSee('Autotest Other Scorecard');
    }

    public function test_a_dealer_cannot_ask_for_another_dealer_with_the_address(): void
    {
        $this->fakeComplaints([]);
        $mine = $this->makeDealer();
        $other = $this->makeDealer(['full_name' => 'Autotest Hidden Scorecard']);

        $this->actingAs($this->makeDealerLogin($mine));
        $response = $this->get(route('dealer.scorecard', ['dealer_id' => $other->id, 'dealer' => $other->id, 'id' => $other->id]))->assertOk();

        $this->assertSame([$mine->id], array_keys($response->viewData('rows')));
        $response->assertDontSee('Autotest Hidden Scorecard');
    }

    public function test_a_dealer_login_with_no_dealer_record_sees_nothing_not_everyone(): void
    {
        $this->fakeComplaints([]);
        $this->makeDealer(['full_name' => 'Autotest Not For Strangers']);

        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => null]));
        $response = $this->get(route('dealer.scorecard'))->assertOk();

        $this->assertSame([], $response->viewData('rows'));
        $response->assertDontSee('Autotest Not For Strangers');
    }

    // ------------------------------------------------------------------ who may open what

    public static function otherRolesForAdminPage(): array
    {
        return ['DEALER' => ['DEALER'], 'DISTRIBUTOR' => ['DISTRIBUTOR'], 'FINANCE' => ['FINANCE'], 'TECHNICIAN' => ['TECHNICIAN'], 'SUPPLIER' => ['SUPPLIER']];
    }

    #[DataProvider('otherRolesForAdminPage')]
    public function test_only_an_admin_opens_the_staff_scorecard(string $role): void
    {
        $this->fakeComplaints([]);
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.dealer-scorecard'))->assertForbidden();
        $this->get(route('admin.dealer-scorecard', ['export' => 'csv']))->assertForbidden();
    }

    public static function rolesThatAreNotDealers(): array
    {
        return ['ADMIN' => ['ADMIN'], 'DISTRIBUTOR' => ['DISTRIBUTOR'], 'FINANCE' => ['FINANCE'], 'TECHNICIAN' => ['TECHNICIAN'], 'SUPPLIER' => ['SUPPLIER']];
    }

    #[DataProvider('rolesThatAreNotDealers')]
    public function test_only_a_dealer_opens_the_dealer_scorecard(string $role): void
    {
        $this->fakeComplaints([]);
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('dealer.scorecard'))->assertForbidden();
    }

    public function test_a_visitor_who_is_not_logged_in_is_sent_to_the_login_page(): void
    {
        $this->get(route('admin.dealer-scorecard'))->assertRedirect(route('login'));
        $this->get(route('dealer.scorecard'))->assertRedirect(route('login'));
    }

    public function test_an_inactive_login_cannot_open_the_scorecard(): void
    {
        $this->fakeComplaints([]);
        $this->actingAs($this->makeAdmin('ADMIN', ['status' => 'INACTIVE']));

        $this->get(route('admin.dealer-scorecard'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------ safe output

    public function test_a_script_in_a_dealer_name_is_shown_as_text(): void
    {
        $this->fakeComplaints([]);
        $this->makeDealer(['full_name' => 'Autotest <script>alert(1)</script> Scorecard']);

        $response = $this->adminOpens()->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_a_dealer_without_a_name_is_shown_by_number(): void
    {
        $this->fakeComplaints([]);
        $dealer = $this->makeDealer(['full_name' => '']);

        $this->assertSame('Dealer #' . $dealer->id, $this->row($this->adminOpens(), $dealer)['name']);
    }
}