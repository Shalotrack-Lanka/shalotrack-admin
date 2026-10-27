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
 * INSTALLS AND WARRANTY PAGE, on real PostgreSQL.
 *
 * One row per bound device: who installed it, when, and when its warranty ends. Rules in simple words:
 *   - Warranty ends at the device's ORIGINAL activation date (Sri Lanka day) plus its warranty months. Renewing a
 *     subscription never moves it.
 *   - A device with 0 warranty months has no added warranty. A device with months but no activation date shows
 *     "Activation date missing" and is never counted as ending or expired.
 *   - Filters: all installs, warranty ending soon (30, 60 or 90 days), warranty expired, no added warranty, missing install details.
 *   - Odd values in the address fall back to the defaults, never an error.
 *   - The CSV has the same rows and keeps typed text from running as a spreadsheet formula.
 *   - ADMIN only.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class InstallsWarrantyTest extends PgsqlTestCase
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

    /** A device whose 12 month warranty ends $daysFromNow days from today (negative = already ended). */
    private function warrantyEnding(int $daysFromNow, array $overrides = [])
    {
        $original = now()->local()->startOfDay()->subMonthsNoOverflow(12)->addDays($daysFromNow);

        return $this->makeBound($overrides + ['warranty_months' => 12, 'original_activated_at' => $original->copy()->utc(), 'installed_on' => '2026-01-10', 'installed_by' => 'Autotest Installer']);
    }

    private function ids(array $query = []): array
    {
        return $this->actingAs($this->admin())->get(route('admin.installs-warranty', $query))->assertOk()
            ->viewData('rows')->pluck('activated_device_id')->all();
    }

    // ================================================================== filters

    public function test_warranty_ending_soon_lists_only_those_ending_inside_the_window_soonest_first(): void
    {
        $soon = $this->warrantyEnding(10);
        $later = $this->warrantyEnding(25);
        $far = $this->warrantyEnding(45);
        $ended = $this->warrantyEnding(-5);
        $none = $this->makeBound(['warranty_months' => 0]);

        $ids = $this->ids(['filter' => 'ending']);

        $this->assertContains($soon->activated_device_id, $ids);
        $this->assertContains($later->activated_device_id, $ids);
        $this->assertLessThan(array_search($later->activated_device_id, $ids), array_search($soon->activated_device_id, $ids));
        foreach ([$far, $ended, $none] as $not) {
            $this->assertNotContains($not->activated_device_id, $ids);
        }
    }

    #[DataProvider('windows')]
    public function test_the_window_decides_what_is_ending_soon(int $window, bool $in20, bool $in50, bool $in80): void
    {
        $a = $this->warrantyEnding(20);
        $b = $this->warrantyEnding(50);
        $c = $this->warrantyEnding(80);

        $ids = $this->ids(['filter' => 'ending', 'days' => $window]);

        $this->assertSame($in20, in_array($a->activated_device_id, $ids, true));
        $this->assertSame($in50, in_array($b->activated_device_id, $ids, true));
        $this->assertSame($in80, in_array($c->activated_device_id, $ids, true));
    }

    public static function windows(): array
    {
        return ['30 days' => [30, true, false, false], '60 days' => [60, true, true, false], '90 days' => [90, true, true, true]];
    }

    #[DataProvider('oddWindows')]
    public function test_an_odd_window_means_thirty_days(mixed $value): void
    {
        $in20 = $this->warrantyEnding(20);
        $in50 = $this->warrantyEnding(50);

        $ids = $this->ids(['filter' => 'ending', 'days' => $value]);

        $this->assertContains($in20->activated_device_id, $ids);
        $this->assertNotContains($in50->activated_device_id, $ids);
    }

    public static function oddWindows(): array
    {
        return ['text' => ['abc'], 'huge' => ['99999999999999999999'], 'negative' => ['-30'], 'zero' => ['0'], 'not offered' => ['45'], 'a list' => [['30']], 'a script' => ['<script>']];
    }

    public function test_expired_lists_those_whose_warranty_has_ended_latest_first(): void
    {
        $recent = $this->warrantyEnding(-3);
        $old = $this->warrantyEnding(-200);
        $active = $this->warrantyEnding(60);

        $ids = $this->ids(['filter' => 'ended']);

        $this->assertLessThan(array_search($old->activated_device_id, $ids), array_search($recent->activated_device_id, $ids));
        $this->assertNotContains($active->activated_device_id, $ids);
    }

    public function test_a_warranty_ending_today_is_still_active_and_one_ending_yesterday_is_expired(): void
    {
        $today = $this->warrantyEnding(0);
        $yesterday = $this->warrantyEnding(-1);

        $ending = $this->ids(['filter' => 'ending']);
        $ended = $this->ids(['filter' => 'ended']);

        $this->assertContains($today->activated_device_id, $ending);
        $this->assertNotContains($today->activated_device_id, $ended);
        $this->assertContains($yesterday->activated_device_id, $ended);
        $this->assertNotContains($yesterday->activated_device_id, $ending);
    }

    public function test_no_added_warranty_lists_zero_month_devices_only(): void
    {
        $none = $this->makeBound(['warranty_months' => 0]);
        $has = $this->warrantyEnding(100);

        $ids = $this->ids(['filter' => 'none']);

        $this->assertContains($none->activated_device_id, $ids);
        $this->assertNotContains($has->activated_device_id, $ids);
    }

    public function test_missing_install_details_lists_devices_with_no_install_date(): void
    {
        $missing = $this->makeBound(['installed_on' => null]);
        $done = $this->makeBound(['installed_on' => '2026-02-02']);

        $ids = $this->ids(['filter' => 'missing']);

        $this->assertContains($missing->activated_device_id, $ids);
        $this->assertNotContains($done->activated_device_id, $ids);
    }

    public function test_a_device_with_warranty_months_but_no_activation_date_is_never_ending_or_expired(): void
    {
        $unknown = $this->makeBound(['warranty_months' => 12, 'original_activated_at' => null]);

        $this->assertNotContains($unknown->activated_device_id, $this->ids(['filter' => 'ending', 'days' => 90]));
        $this->assertNotContains($unknown->activated_device_id, $this->ids(['filter' => 'ended']));
        $this->assertContains($unknown->activated_device_id, $this->ids(['filter' => 'all']));
    }

    public function test_the_end_of_a_warranty_clamps_to_the_end_of_the_month(): void
    {
        // 31 August + 6 months is 28 February (not 3 March), computed on the Sri Lanka day.
        $row = $this->makeBound(['warranty_months' => 6, 'original_activated_at' => '2025-08-31 06:00:00']);

        $found = $this->actingAs($this->admin())->get(route('admin.installs-warranty'))->viewData('rows')->firstWhere('activated_device_id', $row->activated_device_id);
        $w = \App\Services\InstallsWarrantyReport::warranty($found);

        $this->assertSame('2026-02-28', $w['date']->toDateString());
    }

    public function test_the_sri_lanka_day_not_the_utc_day_is_used_for_the_activation_date(): void
    {
        // 20:00 UTC on 30 Sep is 01:30 on 1 Oct in Sri Lanka, so 6 months later is 1 April, not 30 March.
        $row = $this->makeBound(['warranty_months' => 6, 'original_activated_at' => '2025-09-30 20:00:00']);

        $found = $this->actingAs($this->admin())->get(route('admin.installs-warranty'))->viewData('rows')->firstWhere('activated_device_id', $row->activated_device_id);

        $this->assertSame('2026-04-01', \App\Services\InstallsWarrantyReport::warranty($found)['date']->toDateString());
    }

    public function test_all_installs_lists_installed_devices_first_newest_install_first_then_those_without_a_date(): void
    {
        $older = $this->makeBound(['installed_on' => '2026-01-01']);
        $newer = $this->makeBound(['installed_on' => '2026-06-01']);
        $missing = $this->makeBound(['installed_on' => null]);

        $ids = $this->ids();
        $pos = fn ($d) => array_search($d->activated_device_id, $ids);

        $this->assertLessThan($pos($older), $pos($newer));
        $this->assertLessThan($pos($missing), $pos($older));
    }

    #[DataProvider('oddQueries')]
    public function test_an_odd_filter_or_page_never_causes_an_error(array $query): void
    {
        $this->actingAs($this->admin())->get(route('admin.installs-warranty', $query))->assertOk();
    }

    public static function oddQueries(): array
    {
        return ['unknown filter' => [['filter' => 'bogus']], 'a list as filter' => [['filter' => ['x']]], 'text page' => [['page' => 'abc']], 'huge page' => [['page' => '99999999999999999999']], 'negative page' => [['page' => '-3']]];
    }

    public function test_the_counts_for_every_filter_are_right(): void
    {
        $this->warrantyEnding(10);
        $this->warrantyEnding(-10);
        $this->makeBound(['warranty_months' => 0, 'installed_on' => null]);
        $base = $this->actingAs($this->admin())->get(route('admin.installs-warranty'))->viewData('counts');

        $this->warrantyEnding(5);
        $this->makeBound(['warranty_months' => 0, 'installed_on' => null]);

        $now = $this->actingAs($this->admin())->get(route('admin.installs-warranty'))->viewData('counts');

        $this->assertSame($base['all'] + 2, $now['all']);
        $this->assertSame($base['ending'] + 1, $now['ending']);
        $this->assertSame($base['ended'], $now['ended']);
        $this->assertSame($base['none'] + 1, $now['none']);
        $this->assertSame($base['missing'] + 1, $now['missing']);
    }

    public function test_the_dealer_name_comes_from_the_dealer_holding_the_device(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest Warranty Dealer']);
        $device = $this->makeBound(['warranty_months' => 0], $dealer);

        $row = $this->actingAs($this->admin())->get(route('admin.installs-warranty'))->viewData('rows')->firstWhere('activated_device_id', $device->activated_device_id);

        $this->assertSame('Autotest Warranty Dealer', $row->dealer_name);
    }

    public function test_a_script_in_a_customer_name_or_install_note_is_shown_as_text(): void
    {
        $this->makeBound(['customer_name' => '<script>alert(1)</script>', 'install_notes' => '<script>alert(2)</script>', 'installed_by' => '<script>alert(3)</script>']);

        $html = $this->actingAs($this->admin())->get(route('admin.installs-warranty'))->assertOk()->getContent();

        foreach (['alert(1)', 'alert(2)', 'alert(3)'] as $js) {
            $this->assertStringNotContainsString("<script>{$js}</script>", $html);
        }
    }

    public function test_the_list_is_cut_into_pages_of_fifty(): void
    {
        foreach (range(1, 51) as $i) {
            $this->makeBound(['warranty_months' => 0]);
        }

        $page = $this->actingAs($this->admin())->get(route('admin.installs-warranty'))->viewData('rows');

        $this->assertSame(50, $page->count());
    }

    // ================================================================== CSV

    public function test_the_csv_has_the_heading_and_one_line_per_device_with_the_warranty_end(): void
    {
        $device = $this->warrantyEnding(40, ['customer_name' => 'Autotest CSV Customer', 'install_notes' => 'Behind the dash']);

        $response = $this->actingAs($this->admin())->get(route('admin.installs-warranty', ['export' => 'csv']))->assertOk();

        $this->assertStringContainsString('installs-warranty-all-' . now()->local()->format('Y-m-d') . '.csv', $response->headers->get('content-disposition'));
        $rows = $this->csvBody($response);
        $this->assertSame('Customer', $rows[0][0]);
        $this->assertSame('Days left', $rows[0][12]);
        $mine = collect($rows)->first(fn ($r) => ($r[2] ?? null) === $device->imei_number);
        $this->assertSame('Autotest CSV Customer', $mine[0]);
        $this->assertSame('Autotest Installer', $mine[5]);
        $this->assertSame('Behind the dash', $mine[6]);
        $this->assertSame('Active', $mine[10]);
        $this->assertSame(now()->local()->startOfDay()->addDays(40)->toDateString(), $mine[11]);
        $this->assertContains((int) $mine[12], [39, 40]);
    }

    public function test_the_csv_follows_the_chosen_filter(): void
    {
        $none = $this->makeBound(['warranty_months' => 0]);
        $has = $this->warrantyEnding(100);

        $rows = $this->csvBody($this->actingAs($this->admin())->get(route('admin.installs-warranty', ['filter' => 'none', 'export' => 'csv'])));

        $imeis = array_column($rows, 2);
        $this->assertContains($none->imei_number, $imeis);
        $this->assertNotContains($has->imei_number, $imeis);
    }

    public function test_an_expired_or_no_warranty_row_has_no_days_left_in_the_csv(): void
    {
        $ended = $this->warrantyEnding(-20);
        $none = $this->makeBound(['warranty_months' => 0]);

        $rows = $this->csvBody($this->actingAs($this->admin())->get(route('admin.installs-warranty', ['export' => 'csv'])));

        $e = collect($rows)->first(fn ($r) => ($r[2] ?? null) === $ended->imei_number);
        $n = collect($rows)->first(fn ($r) => ($r[2] ?? null) === $none->imei_number);
        $this->assertSame('Expired', $e[10]);
        $this->assertSame('', $e[12]);
        $this->assertSame('No added warranty', $n[10]);
        $this->assertSame('', $n[11]);
    }

    #[DataProvider('formulas')]
    public function test_typed_text_cannot_run_as_a_spreadsheet_formula(string $typed): void
    {
        $device = $this->makeBound(['customer_name' => $typed, 'vehicle_number' => $typed, 'installed_by' => $typed, 'install_notes' => $typed]);

        $rows = $this->csvBody($this->actingAs($this->admin())->get(route('admin.installs-warranty', ['export' => 'csv'])));

        $mine = collect($rows)->first(fn ($r) => ($r[2] ?? null) === $device->imei_number);
        foreach ([0, 1, 5, 6] as $col) {
            $this->assertSame("'" . $typed, $mine[$col]);
        }
    }

    public static function formulas(): array
    {
        return ['equals' => ['=HYPERLINK("http://evil.test","x")'], 'plus' => ['+1+1'], 'minus' => ['-2+3'], 'at' => ['@SUM(A1)']];
    }

    // ================================================================== who may enter

    public static function notAdmin(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('notAdmin')]
    public function test_only_an_admin_can_open_the_page_or_its_csv(string $role): void
    {
        $user = $this->makeAdmin($role);

        $this->actingAs($user)->get(route('admin.installs-warranty'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.installs-warranty', ['export' => 'csv']))->assertForbidden();
    }

    public function test_a_logged_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('admin.installs-warranty'))->assertRedirect();
    }
}