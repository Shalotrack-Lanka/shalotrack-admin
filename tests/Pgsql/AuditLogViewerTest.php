<?php

namespace Tests\Pgsql;

use App\Models\Admin;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * AUDIT LOG VIEWER, on real PostgreSQL.
 *
 * The audit log is the trail of who did what. Rules in simple words:
 *   - Read only. Nobody can change or delete an entry (not even through the code).
 *   - Only the developer account sees everyone's entries. Any other administrator sees only what they did themselves
 *     and what happened to their own account. No filter can widen that.
 *   - Filters: category, action, who (name or id), subject text, and a from / to day. Days are Sri Lanka days while rows
 *     are stored in UTC. A bad value in a filter is ignored, never an error.
 *   - "%" and "_" in a search are plain letters.
 *   - The CSV export carries the same rows (at most 5,000), shows Sri Lanka time, and keeps typed text from running as
 *     a spreadsheet formula. The export itself is written to the audit log.
 *   - ADMIN only.
 *
 * Nothing real is touched: all data is made up with Autotest names and rolled back after every test.
 */
class AuditLogViewerTest extends PgsqlTestCase
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

    private function entry(array $overrides = []): AuditLog
    {
        $n = $this->nextIntakeNumber();

        return AuditLog::create(array_merge([
            'occurred_at'   => now(),
            'actor_id'      => (string) $this->admin()->admin_id,
            'actor_name'    => 'Autotest Actor',
            'actor_role'    => 'ADMIN',
            'category'      => AuditLog::CATEGORY_DEVICE,
            'action'        => 'device.activated',
            'subject_type'  => 'vehicle',
            'subject_id'    => "autotest-subject-{$n}",
            'subject_label' => "AUTO-LOG-{$n}",
        ], $overrides));
    }

    private function labels(array $query = [], ?Admin $as = null): array
    {
        return $this->actingAs($as ?? $this->admin())->get(route('admin.audit-log', $query))->assertOk()
            ->viewData('rows')->pluck('subject_label')->all();
    }

    private function makeDeveloper(): Admin
    {
        $dev = $this->makeAdmin('ADMIN');
        config(['erp.developer_usernames' => [strtolower($dev->username)]]);

        return $dev;
    }

    // ================================================================== who sees what

    public function test_an_ordinary_admin_sees_only_their_own_entries_and_those_about_their_own_account(): void
    {
        $other = $this->makeAdmin('ADMIN');
        $mine = $this->entry(['subject_label' => 'AUTO-MINE']);
        $about = $this->entry(['actor_id' => (string) $other->admin_id, 'subject_type' => 'admin', 'subject_id' => (string) $this->admin()->admin_id, 'subject_label' => 'AUTO-ABOUT-ME']);
        $theirs = $this->entry(['actor_id' => (string) $other->admin_id, 'subject_label' => 'AUTO-THEIRS']);

        $labels = $this->labels();

        $this->assertContains('AUTO-MINE', $labels);
        $this->assertContains('AUTO-ABOUT-ME', $labels);
        $this->assertNotContains('AUTO-THEIRS', $labels);
        $this->actingAs($this->admin())->get(route('admin.audit-log'))->assertViewHas('restricted', true);
    }

    public function test_the_developer_account_sees_everyones_entries(): void
    {
        $dev = $this->makeDeveloper();
        $other = $this->makeAdmin('DEALER');
        $this->entry(['actor_id' => (string) $other->admin_id, 'subject_label' => 'AUTO-DEALER-ACTION']);

        $this->assertContains('AUTO-DEALER-ACTION', $this->labels([], $dev));
        $this->actingAs($dev)->get(route('admin.audit-log'))->assertViewHas('restricted', false);
    }

    public function test_an_ordinary_admin_cannot_widen_the_view_with_any_filter(): void
    {
        $other = $this->makeAdmin('ADMIN');
        $this->entry(['actor_id' => (string) $other->admin_id, 'actor_name' => 'Autotest Somebody Else', 'subject_label' => 'AUTO-HIDDEN', 'subject_id' => 'hidden-1']);

        foreach ([['actor' => 'Somebody Else'], ['actor' => (string) $other->admin_id], ['q' => 'hidden'], ['category' => 'device'], ['action' => 'device.activated'], ['from' => '2000-01-01'], ['to' => '2999-12-31'], ['actor' => "x' OR '1'='1"]] as $query) {
            $this->assertNotContains('AUTO-HIDDEN', $this->labels($query), json_encode($query));
        }
    }

    // ================================================================== filters

    public function test_the_category_and_action_filters_work_and_an_unknown_value_is_ignored(): void
    {
        $device = $this->entry(['subject_label' => 'AUTO-DEVICE', 'category' => 'device', 'action' => 'device.activated']);
        $money = $this->entry(['subject_label' => 'AUTO-MONEY', 'category' => 'money', 'action' => 'invoice.issued']);

        $this->assertSame(['AUTO-MONEY'], array_values(array_intersect($this->labels(['category' => 'money']), ['AUTO-DEVICE', 'AUTO-MONEY'])));
        $this->assertSame(['AUTO-DEVICE'], array_values(array_intersect($this->labels(['action' => 'device.activated']), ['AUTO-DEVICE', 'AUTO-MONEY'])));

        foreach ([['category' => 'bogus'], ['action' => 'bogus.action'], ['category' => ['money']], ['action' => ['x']]] as $query) {
            $labels = $this->labels($query);
            $this->assertContains('AUTO-DEVICE', $labels, json_encode($query));
            $this->assertContains('AUTO-MONEY', $labels, json_encode($query));
        }
    }

    public function test_the_who_filter_matches_name_or_id_ignoring_capitals(): void
    {
        $this->entry(['actor_name' => 'Autotest Zebediah', 'subject_label' => 'AUTO-ZEB']);
        $this->entry(['actor_name' => 'Autotest Other', 'subject_label' => 'AUTO-OTHER']);

        $byName = $this->labels(['actor' => 'zebediah']);
        $this->assertContains('AUTO-ZEB', $byName);
        $this->assertNotContains('AUTO-OTHER', $byName);

        $byId = $this->labels(['actor' => strtoupper(substr((string) $this->admin()->admin_id, 0, 8))]);
        $this->assertContains('AUTO-ZEB', $byId);
    }

    public function test_the_subject_filter_matches_label_or_id_ignoring_capitals(): void
    {
        $this->entry(['subject_label' => 'AUTO-WP-1234', 'subject_id' => 'sub-aaa']);
        $this->entry(['subject_label' => 'AUTO-CP-9999', 'subject_id' => 'sub-BBB']);

        $this->assertSame(['AUTO-WP-1234'], array_values(array_intersect($this->labels(['q' => 'wp-12']), ['AUTO-WP-1234', 'AUTO-CP-9999'])));
        $this->assertSame(['AUTO-CP-9999'], array_values(array_intersect($this->labels(['q' => 'sub-bbb']), ['AUTO-WP-1234', 'AUTO-CP-9999'])));
    }

    public function test_percent_underscore_and_exclamation_are_plain_letters_in_searches(): void
    {
        $this->entry(['subject_label' => 'AUTO-PLAIN-NAME']);
        $this->entry(['subject_label' => 'AUTO-100%-OFF_NOW!']);

        foreach (['100%', 'OFF_NOW', 'NOW!', '%-OFF'] as $term) {
            $labels = $this->labels(['q' => $term]);
            $this->assertContains('AUTO-100%-OFF_NOW!', $labels, $term);
            $this->assertNotContains('AUTO-PLAIN-NAME', $labels, $term);
        }
        $this->assertNotContains('AUTO-PLAIN-NAME', $this->labels(['q' => 'AUTO-_LAIN']));
    }

    public function test_the_days_are_sri_lanka_days_not_utc_days(): void
    {
        // 19:00 UTC on 26 Oct is 00:30 on 27 Oct in Sri Lanka.
        $this->entry(['subject_label' => 'AUTO-MIDNIGHT', 'occurred_at' => '2026-10-26 19:00:00']);
        // 18:00 UTC on 26 Oct is 23:30 on 26 Oct in Sri Lanka.
        $this->entry(['subject_label' => 'AUTO-BEFORE', 'occurred_at' => '2026-10-26 18:00:00']);

        $from27 = $this->labels(['from' => '2026-10-27', 'to' => '2026-10-27']);
        $this->assertContains('AUTO-MIDNIGHT', $from27);
        $this->assertNotContains('AUTO-BEFORE', $from27);

        $on26 = $this->labels(['from' => '2026-10-26', 'to' => '2026-10-26']);
        $this->assertContains('AUTO-BEFORE', $on26);
        $this->assertNotContains('AUTO-MIDNIGHT', $on26);
    }

    #[DataProvider('badDates')]
    public function test_a_bad_date_is_ignored_and_never_causes_an_error(mixed $value): void
    {
        $this->entry(['subject_label' => 'AUTO-ANYDAY']);

        $this->assertContains('AUTO-ANYDAY', $this->labels(['from' => $value, 'to' => $value]));
    }

    public static function badDates(): array
    {
        return ['impossible day' => ['2026-02-31'], 'text' => ['yesterday'], 'wrong shape' => ['2026-1-5'], 'with time' => ['2026-10-27 10:00'], 'a list' => [['2026-10-27']], 'a script' => ['<script>'], 'huge year' => ['99999-01-01'], 'sql' => ["2026-10-27'; DROP TABLE audit_logs;--"]];
    }

    #[DataProvider('oddTexts')]
    public function test_an_odd_search_text_never_causes_an_error(mixed $value): void
    {
        $this->actingAs($this->admin())->get(route('admin.audit-log', ['q' => $value, 'actor' => $value]))->assertOk();
    }

    public static function oddTexts(): array
    {
        return ['a list' => [['a']], 'very long' => [str_repeat('a', 5000)], 'quotes' => ["' OR 1=1 --"], 'a script' => ['<script>alert(1)</script>'], 'backslash' => ['\\'], 'unicode' => ['සිංහල']];
    }

    public function test_a_very_long_search_is_cut_to_100_letters(): void
    {
        $this->entry(['subject_label' => str_repeat('a', 100)]);

        $this->assertContains(str_repeat('a', 100), $this->labels(['q' => str_repeat('a', 5000)]));
    }

    // ================================================================== list

    public function test_the_newest_entry_is_first(): void
    {
        $this->entry(['subject_label' => 'AUTO-OLD', 'occurred_at' => now()->subDays(2)]);
        $this->entry(['subject_label' => 'AUTO-NEW', 'occurred_at' => now()->subHour()]);

        $labels = $this->labels();

        $this->assertLessThan(array_search('AUTO-OLD', $labels), array_search('AUTO-NEW', $labels));
    }

    public function test_the_list_is_cut_into_pages_of_fifty(): void
    {
        foreach (range(1, 51) as $i) {
            $this->entry(['subject_label' => 'AUTO-PAGE-' . $i]);
        }

        $rows = $this->actingAs($this->admin())->get(route('admin.audit-log', ['q' => 'AUTO-PAGE-']))->viewData('rows');

        $this->assertSame(50, $rows->count());
        $this->assertSame(51, $rows->total());
    }

    #[DataProvider('oddPages')]
    public function test_an_odd_page_number_never_causes_an_error(string $page): void
    {
        $this->actingAs($this->admin())->get(route('admin.audit-log', ['page' => $page]))->assertOk();
    }

    public static function oddPages(): array
    {
        return ['text' => ['abc'], 'huge' => ['99999999999999999999'], 'negative' => ['-3'], 'zero' => ['0']];
    }

    public function test_a_script_in_an_entry_is_shown_as_text_and_never_run(): void
    {
        $this->entry(['subject_label' => '<script>alert(1)</script>', 'actor_name' => '<script>alert(2)</script>', 'changes' => ['name' => ['from' => '<script>alert(3)</script>', 'to' => 'x']]]);

        $html = $this->actingAs($this->admin())->get(route('admin.audit-log'))->assertOk()->getContent();

        foreach (['alert(1)', 'alert(2)', 'alert(3)'] as $js) {
            $this->assertStringNotContainsString("<script>{$js}</script>", $html);
        }
    }

    // ================================================================== nobody can change an entry

    public function test_an_entry_cannot_be_updated_or_deleted_through_the_code(): void
    {
        $row = $this->entry();

        try {
            $row->update(['subject_label' => 'CHANGED']);
            $this->fail('an update should have been refused');
        } catch (LogicException) {
            $this->assertNotSame('CHANGED', $row->fresh()->subject_label);
        }

        try {
            $row->delete();
            $this->fail('a delete should have been refused');
        } catch (LogicException) {
            $this->assertNotNull(AuditLog::find($row->id));
        }
    }

    public function test_there_is_no_address_that_changes_or_deletes_entries(): void
    {
        $row = $this->entry(['subject_label' => 'AUTO-SAFE']);
        $this->actingAs($this->admin());

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            foreach (['/admin/audit-log', '/admin/audit-log/' . $row->id] as $url) {
                $status = $this->{$method}($url)->getStatusCode();
                $this->assertContains($status, [404, 405], "{$method} {$url} must not exist");
            }
        }

        $this->assertSame('AUTO-SAFE', $row->fresh()->subject_label);
    }

    // ================================================================== CSV

    public function test_the_csv_has_the_heading_shows_sri_lanka_time_and_labels_the_action(): void
    {
        $row = $this->entry(['subject_label' => 'AUTO-CSV-1', 'occurred_at' => '2026-10-26 20:00:00', 'actor_name' => 'Autotest CSV Actor', 'action' => 'invoice.issued', 'category' => 'money', 'changes' => ['a' => ['from' => 1, 'to' => 2]], 'meta' => ['k' => 'v'], 'ip' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36']);

        $response = $this->actingAs($this->admin())->get(route('admin.audit-log', ['export' => 'csv', 'q' => 'AUTO-CSV-1']))->assertOk();

        $this->assertStringContainsString('audit-log-' . now()->local()->format('Y-m-d') . '.csv', $response->headers->get('content-disposition'));
        $rows = $this->csvBody($response);
        $this->assertSame('When (Sri Lanka)', $rows[0][0]);
        $this->assertSame('User agent', $rows[0][12]);
        $this->assertCount(2, $rows);
        $line = $rows[1];
        $this->assertSame('2026-10-27 01:30:00', $line[0]);
        $this->assertSame('Autotest CSV Actor', $line[1]);
        $this->assertSame(AuditLog::labelFor('invoice.issued'), $line[3]);
        $this->assertSame('vehicle AUTO-CSV-1', $line[4]);
        $this->assertStringContainsString('"to":2', $line[5]);
        $this->assertSame('203.0.113.9', $line[7]);
        $this->assertStringStartsWith('Chrome', $line[8]);
    }

    public function test_an_entry_written_by_the_system_has_blank_device_columns(): void
    {
        $this->entry(['subject_label' => 'AUTO-SYSTEM', 'actor_name' => null, 'user_agent' => null]);

        $rows = $this->csvBody($this->actingAs($this->admin())->get(route('admin.audit-log', ['export' => 'csv', 'q' => 'AUTO-SYSTEM'])));

        $this->assertSame($this->admin()->admin_id, $rows[1][1], 'no name, so the id is shown');
        $this->assertSame(['', '', '', ''], array_slice($rows[1], 8, 4));
    }

    public function test_an_entry_with_no_actor_at_all_reads_as_system(): void
    {
        $about = $this->entry(['subject_label' => 'AUTO-NOBODY', 'actor_id' => null, 'actor_name' => null, 'subject_type' => 'admin', 'subject_id' => (string) $this->admin()->admin_id]);

        $rows = $this->csvBody($this->actingAs($this->admin())->get(route('admin.audit-log', ['export' => 'csv', 'q' => 'AUTO-NOBODY'])));

        $this->assertSame('system / not signed in', $rows[1][1]);
    }

    #[DataProvider('formulas')]
    public function test_typed_text_cannot_run_as_a_spreadsheet_formula(string $typed): void
    {
        $this->entry(['subject_type' => null, 'subject_label' => $typed, 'actor_name' => $typed, 'ip' => $typed]);

        $rows = $this->csvBody($this->actingAs($this->admin())->get(route('admin.audit-log', ['export' => 'csv', 'actor' => ltrim($typed, '=+-@'), 'q' => ltrim($typed, '=+-@')])));

        $line = collect($rows)->first(fn ($r) => ($r[1] ?? '') === "'" . $typed);
        $this->assertNotNull($line, 'the actor cell starts with a quote mark');
        $this->assertSame("'" . $typed, $line[4]);
        $this->assertSame("'" . $typed, $line[7]);
    }

    public static function formulas(): array
    {
        return ['equals' => ['=HYPERLINK("http://evil.test","x")'], 'plus' => ['+1+1'], 'minus' => ['-2+3'], 'at' => ['@SUM(A1)']];
    }

    public function test_the_csv_of_an_ordinary_admin_holds_only_their_own_entries(): void
    {
        $other = $this->makeAdmin('ADMIN');
        $this->entry(['subject_label' => 'AUTO-MINE-CSV']);
        $this->entry(['actor_id' => (string) $other->admin_id, 'subject_label' => 'AUTO-THEIRS-CSV']);

        $text = json_encode($this->csvBody($this->actingAs($this->admin())->get(route('admin.audit-log', ['export' => 'csv']))));

        $this->assertStringContainsString('AUTO-MINE-CSV', $text);
        $this->assertStringNotContainsString('AUTO-THEIRS-CSV', $text);
    }

    public function test_the_csv_follows_the_same_filters_as_the_page(): void
    {
        $this->entry(['subject_label' => 'AUTO-KEEP-CSV', 'category' => 'money', 'action' => 'invoice.issued']);
        $this->entry(['subject_label' => 'AUTO-DROP-CSV', 'category' => 'device']);

        $text = json_encode($this->csvBody($this->actingAs($this->admin())->get(route('admin.audit-log', ['export' => 'csv', 'category' => 'money']))));

        $this->assertStringContainsString('AUTO-KEEP-CSV', $text);
        $this->assertStringNotContainsString('AUTO-DROP-CSV', $text);
    }

    public function test_downloading_the_csv_is_itself_written_to_the_audit_log(): void
    {
        $before = AuditLog::where('action', 'data.downloaded')->count();

        $this->actingAs($this->admin())->get(route('admin.audit-log', ['export' => 'csv']))->sendContent();

        $this->assertSame($before + 1, AuditLog::where('action', 'data.downloaded')->count());
    }

    // ================================================================== who may enter

    public static function notAdmin(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('notAdmin')]
    public function test_only_an_admin_can_open_the_audit_log_or_its_csv(string $role): void
    {
        $user = $this->makeAdmin($role);

        $this->actingAs($user)->get(route('admin.audit-log'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.audit-log', ['export' => 'csv']))->assertForbidden();
    }

    public function test_a_logged_out_visitor_is_sent_to_log_in(): void
    {
        $this->get(route('admin.audit-log'))->assertRedirect();
    }
}