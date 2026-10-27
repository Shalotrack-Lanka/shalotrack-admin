<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * RENEWALS PAGE (who needs to renew), admin and dealer, on real PostgreSQL.
 *
 * Rules in simple words:
 *   - "Due soon" = a Paid subscription that ends within the chosen window (7, 14, 30 or 60 days) and has not ended yet.
 *   - "Overdue"  = anything that is not Paid (lapsed or never paid), newest ending first.
 *   - The admin sees every dealer's devices. A dealer sees ONLY devices held by that dealer. A dealer login with no
 *     dealer record sees nothing (never "everything").
 *   - Odd values in the address (window, tab, page) fall back to the defaults, never an error.
 *   - The CSV download carries the same rows as the page and keeps customer-typed text from running as a spreadsheet formula.
 *   - Admin page: ADMIN only. Dealer page: DEALER only.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class RenewalsReportTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private ?Admin $admin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->quietApi();
    }

    private function admin(): Admin
    {
        return $this->admin ??= $this->makeAdmin('ADMIN');
    }

    private function due(int $inDays, array $overrides = [], $dealer = null)
    {
        return $this->makeBound($overrides + [
            'payment_status'        => 'Paid',
            'subscription_model'    => '1 Year',
            'subscription_end_date' => now()->addDays($inDays)->addHour(),
        ], $dealer);
    }

    private function rowIds(string $routeName, array $query = [], ?Admin $as = null): array
    {
        return $this->actingAs($as ?? $this->admin())->get(route($routeName, $query))->assertOk()
            ->viewData('rows')->pluck('activated_device_id')->all();
    }

    // ================================================================== due soon

    public function test_due_soon_lists_only_paid_subscriptions_ending_inside_the_window_soonest_first(): void
    {
        $soon = $this->due(3);
        $later = $this->due(20);
        $this->due(45);                                              // outside the 30 day window
        $ended = $this->due(-2);                                     // already ended
        $unpaid = $this->makeBound(['payment_status' => 'not-Paid', 'subscription_end_date' => now()->addDays(5)]);
        $noEnd = $this->makeBound(['payment_status' => 'Paid', 'subscription_end_date' => null]);

        $ids = $this->rowIds('admin.renewals');

        $this->assertContains($soon->activated_device_id, $ids);
        $this->assertContains($later->activated_device_id, $ids);
        $this->assertSame([$soon->activated_device_id, $later->activated_device_id], array_values(array_intersect($ids, [$soon->activated_device_id, $later->activated_device_id])), 'soonest first');
        foreach ([$ended, $unpaid, $noEnd] as $not) {
            $this->assertNotContains($not->activated_device_id, $ids);
        }
    }

    #[DataProvider('windows')]
    public function test_the_window_decides_what_is_due(int $window, bool $in10, bool $in40): void
    {
        $ten = $this->due(10);
        $forty = $this->due(40);

        $ids = $this->rowIds('admin.renewals', ['days' => $window]);

        $this->assertSame($in10, in_array($ten->activated_device_id, $ids, true));
        $this->assertSame($in40, in_array($forty->activated_device_id, $ids, true));
    }

    public static function windows(): array
    {
        return ['7 days' => [7, false, false], '14 days' => [14, true, false], '30 days' => [30, true, false], '60 days' => [60, true, true]];
    }

    #[DataProvider('oddWindows')]
    public function test_an_odd_window_means_thirty_days(mixed $value): void
    {
        $in20 = $this->due(20);
        $in50 = $this->due(50);

        $ids = $this->rowIds('admin.renewals', ['days' => $value]);

        $this->assertContains($in20->activated_device_id, $ids);
        $this->assertNotContains($in50->activated_device_id, $ids);
    }

    public static function oddWindows(): array
    {
        return ['text' => ['abc'], 'huge' => ['99999999999999999999'], 'negative' => ['-7'], 'zero' => ['0'], 'not offered' => ['45'], 'a list' => [['7']], 'a script' => ['<script>']];
    }

    // ================================================================== overdue

    public function test_overdue_lists_everything_not_paid_with_the_latest_end_date_first(): void
    {
        $older = $this->makeBound(['payment_status' => 'not-Paid']);
        $newer = $this->makeBound(['payment_status' => 'not-Paid']);
        $paid = $this->due(10);
        $this->makeExpiredRecord($older, '2026-01-10');
        $this->makeExpiredRecord($newer, '2026-03-20');

        $ids = $this->rowIds('admin.renewals', ['scope' => 'overdue']);

        $this->assertContains($older->activated_device_id, $ids);
        $this->assertNotContains($paid->activated_device_id, $ids);
        $this->assertLessThan(array_search($older->activated_device_id, $ids), array_search($newer->activated_device_id, $ids), 'latest end date first');
    }

    public function test_the_ended_on_date_comes_from_the_expiry_record(): void
    {
        $device = $this->makeBound(['payment_status' => 'not-Paid']);
        $this->makeExpiredRecord($device, '2026-05-02');

        $row = $this->actingAs($this->admin())->get(route('admin.renewals', ['scope' => 'overdue']))->viewData('rows')->firstWhere('activated_device_id', $device->activated_device_id);

        $this->assertSame('2026-05-02', substr((string) $row->ended_on, 0, 10));
    }

    public function test_both_tab_counts_are_right_whichever_tab_is_open(): void
    {
        $this->due(4);
        $this->makeBound(['payment_status' => 'not-Paid']);
        $before = $this->actingAs($this->admin())->get(route('admin.renewals'))->viewData('dueCount');
        $beforeOver = $this->actingAs($this->admin())->get(route('admin.renewals'))->viewData('overdueCount');

        $this->due(6);
        $this->makeBound(['payment_status' => 'not-Paid']);

        foreach (['due', 'overdue'] as $tab) {
            $this->actingAs($this->admin())->get(route('admin.renewals', ['scope' => $tab]))
                ->assertViewHas('dueCount', $before + 1)->assertViewHas('overdueCount', $beforeOver + 1);
        }
    }

    #[DataProvider('oddQueries')]
    public function test_an_odd_tab_or_page_never_causes_an_error(array $query): void
    {
        $this->actingAs($this->admin())->get(route('admin.renewals', $query))->assertOk();
    }

    public static function oddQueries(): array
    {
        return ['unknown tab' => [['scope' => 'bogus']], 'a list as the tab' => [['scope' => ['x']]], 'text page' => [['page' => 'abc']], 'huge page' => [['page' => '99999999999999999999']], 'negative page' => [['page' => '-3']]];
    }

    public function test_the_list_is_cut_into_pages_of_fifty(): void
    {
        foreach (range(1, 51) as $i) {
            $this->makeBound(['payment_status' => 'not-Paid']);
        }

        $page = $this->actingAs($this->admin())->get(route('admin.renewals', ['scope' => 'overdue']))->assertOk()->viewData('rows');

        $this->assertSame(50, $page->count());
        $this->assertGreaterThanOrEqual(51, $page->total());
    }

    // ================================================================== details on a row

    public function test_the_dealer_name_and_customer_phone_come_from_their_own_records(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest Named Dealer']);
        $device = $this->due(5, [], $dealer);
        $this->makeCustomerRecord($device, '0771234567');

        $row = $this->actingAs($this->admin())->get(route('admin.renewals'))->viewData('rows')->firstWhere('activated_device_id', $device->activated_device_id);

        $this->assertSame('Autotest Named Dealer', $row->dealer_name);
        $this->assertSame('0771234567', $row->customer_phone);
    }

    public function test_a_device_with_no_dealer_or_customer_record_still_appears(): void
    {
        $device = $this->due(5);

        $row = $this->actingAs($this->admin())->get(route('admin.renewals'))->viewData('rows')->firstWhere('activated_device_id', $device->activated_device_id);

        $this->assertNull($row->dealer_name);
        $this->assertNull($row->customer_phone);
    }

    public function test_a_script_in_a_customer_name_is_shown_as_text_and_never_run(): void
    {
        $this->due(5, ['customer_name' => '<script>alert(1)</script>']);

        $html = $this->actingAs($this->admin())->get(route('admin.renewals'))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ================================================================== dealer page: only their own

    public function test_a_dealer_sees_only_devices_held_by_that_dealer(): void
    {
        $mine = $this->makeDealer();
        $theirs = $this->makeDealer();
        $own = $this->due(5, [], $mine);
        $other = $this->due(5, [], $theirs);
        $nobody = $this->due(5);
        $login = $this->makeDealerLogin($mine);

        $ids = $this->rowIds('dealer.renewals', [], $login);

        $this->assertContains($own->activated_device_id, $ids);
        $this->assertNotContains($other->activated_device_id, $ids);
        $this->assertNotContains($nobody->activated_device_id, $ids);
    }

    public function test_the_dealer_overdue_tab_is_also_limited_to_their_own_devices(): void
    {
        $mine = $this->makeDealer();
        $theirs = $this->makeDealer();
        $own = $this->makeBound(['payment_status' => 'not-Paid'], $mine);
        $other = $this->makeBound(['payment_status' => 'not-Paid'], $theirs);

        $ids = $this->rowIds('dealer.renewals', ['scope' => 'overdue'], $this->makeDealerLogin($mine));

        $this->assertSame([$own->activated_device_id], $ids);
        $this->assertNotContains($other->activated_device_id, $ids);
    }

    public function test_a_dealer_login_with_no_dealer_record_sees_nothing_at_all(): void
    {
        $this->due(5, [], $this->makeDealer());
        $this->makeBound(['payment_status' => 'not-Paid']);
        $login = $this->makeDealerLogin(null);

        $this->assertSame([], $this->rowIds('dealer.renewals', [], $login));
        $this->assertSame([], $this->rowIds('dealer.renewals', ['scope' => 'overdue'], $login));
    }

    public function test_the_dealer_cannot_widen_the_list_with_an_address_trick(): void
    {
        $mine = $this->makeDealer();
        $theirs = $this->makeDealer();
        $other = $this->due(5, [], $theirs);
        $login = $this->makeDealerLogin($mine);

        foreach ([['dealer_id' => $theirs->id], ['dealer_id' => '0 OR 1=1'], ['dealer' => $theirs->id], ['scope' => 'due', 'days' => 60, 'dealer_id' => $theirs->id]] as $query) {
            $this->assertNotContains($other->activated_device_id, $this->rowIds('dealer.renewals', $query, $login));
        }
    }

    public function test_the_admin_page_shows_the_dealer_column(): void
    {
        $this->actingAs($this->admin())->get(route('admin.renewals'))->assertViewHas('showDealer', true);
    }

    public function test_the_dealer_page_does_not_show_the_dealer_column(): void
    {
        $this->actingAs($this->makeDealerLogin($this->makeDealer()))->get(route('dealer.renewals'))->assertOk()->assertViewHas('showDealer', false);
    }

    // ================================================================== CSV

    public function test_the_csv_has_a_heading_the_same_rows_as_the_page_and_a_dated_file_name(): void
    {
        $device = $this->due(5, ['customer_name' => 'Autotest CSV Customer', 'vehicle_number' => 'AUTO-CSV-1']);

        $response = $this->actingAs($this->admin())->get(route('admin.renewals', ['export' => 'csv']))->assertOk();

        $this->assertStringContainsString('renewals-due-' . now()->local()->format('Y-m-d') . '.csv', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $rows = $this->csvBody($response);
        $this->assertSame(['Customer', 'Phone', 'Vehicle', 'IMEI', 'Dealer', 'Ends on', 'Days left', 'Plan'], $rows[0]);
        $mine = collect($rows)->first(fn ($r) => ($r[3] ?? null) === $device->imei_number);
        $this->assertSame('Autotest CSV Customer', $mine[0]);
        $this->assertSame('1 Year', $mine[7]);
        $this->assertContains((int) $mine[6], [4, 5], 'whole local days left');
    }

    public function test_the_overdue_csv_says_ended_on_and_has_no_days_left(): void
    {
        $device = $this->makeBound(['payment_status' => 'not-Paid']);
        $this->makeExpiredRecord($device, '2026-04-04');

        $response = $this->actingAs($this->admin())->get(route('admin.renewals', ['scope' => 'overdue', 'export' => 'csv']))->assertOk();

        $rows = $this->csvBody($response);
        $this->assertSame('Ended on', $rows[0][5]);
        $mine = collect($rows)->first(fn ($r) => ($r[3] ?? null) === $device->imei_number);
        $this->assertSame('', $mine[6]);
    }

    #[DataProvider('formulas')]
    public function test_customer_typed_text_cannot_run_as_a_spreadsheet_formula(string $typed): void
    {
        $device = $this->due(5, ['customer_name' => $typed, 'vehicle_number' => $typed]);

        $rows = $this->csvBody($this->actingAs($this->admin())->get(route('admin.renewals', ['export' => 'csv'])));

        $mine = collect($rows)->first(fn ($r) => ($r[3] ?? null) === $device->imei_number);
        $this->assertSame("'" . $typed, $mine[0]);
        $this->assertSame("'" . $typed, $mine[2]);
    }

    public static function formulas(): array
    {
        return ['equals' => ['=HYPERLINK("http://evil.test","x")'], 'plus' => ['+1+1'], 'minus' => ['-2+3'], 'at' => ['@SUM(A1)']];
    }

    public function test_the_dealer_csv_holds_only_their_own_rows(): void
    {
        $mine = $this->makeDealer();
        $theirs = $this->makeDealer();
        $own = $this->due(5, [], $mine);
        $other = $this->due(5, [], $theirs);

        $rows = $this->csvBody($this->actingAs($this->makeDealerLogin($mine))->get(route('dealer.renewals', ['export' => 'csv'])));

        $imeis = array_column($rows, 3);
        $this->assertContains($own->imei_number, $imeis);
        $this->assertNotContains($other->imei_number, $imeis);
    }

    public function test_the_csv_of_a_dealer_with_no_record_is_only_the_heading(): void
    {
        $this->due(5, [], $this->makeDealer());

        $rows = $this->csvBody($this->actingAs($this->makeDealerLogin(null))->get(route('dealer.renewals', ['export' => 'csv'])));

        $this->assertCount(1, $rows);
    }

    // ================================================================== who may enter

    public static function notAdmin(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('notAdmin')]
    public function test_the_admin_renewals_page_is_for_admins_only(string $role): void
    {
        $user = $this->makeAdmin($role);

        $this->actingAs($user)->get(route('admin.renewals'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.renewals', ['export' => 'csv']))->assertForbidden();
    }

    public static function notDealer(): array
    {
        return array_map(fn ($r) => [$r], ['ADMIN', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('notDealer')]
    public function test_the_dealer_renewals_page_is_for_dealers_only(string $role): void
    {
        $user = $this->makeAdmin($role);

        $this->actingAs($user)->get(route('dealer.renewals'))->assertForbidden();
        $this->actingAs($user)->get(route('dealer.renewals', ['export' => 'csv']))->assertForbidden();
    }

    public function test_a_logged_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('admin.renewals'))->assertRedirect();
        $this->get(route('dealer.renewals'))->assertRedirect();
    }
}