<?php

namespace Tests\Pgsql;

use App\Models\ActivatedDevice;
use App\Models\Dealer;
use App\Services\DeviceHealthReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * DEVICE HEALTH, on real PostgreSQL (autotest schema).
 *
 *   GET admin.device-health    ADMIN: paid devices that need a call, for every dealer
 *   GET dealer.device-health   DEALER: the same list, only for the devices that dealer sold
 *
 * The list says which PAID devices have a problem: "not linked in the app", "never connected", "gone silent"
 * or "unplugged". What the tests prove (simple words):
 *   - each rule fires at the right moment (boundaries: 1 hour, 24 hours, 72 hours) and only one problem is shown per device,
 *   - only PAID devices are listed (a lapsed customer is a renewals matter, not a fault),
 *   - if the app server cannot be reached the page says so; it never shows an empty list that reads "all healthy",
 *     and that failure is not remembered,
 *   - the most serious problems are first; the filters accept only the listed values; the page is split in pages of 50,
 *   - a dealer sees only its own devices; a dealer login with no dealer record sees nothing and nothing is asked of the server,
 *   - the CSV cannot run a formula in Excel and is not offered when the data is not available,
 *   - names are shown as text, never run as a script.
 *
 * The app server is FAKED (no real network). Rows are tagged "Autotest" and rolled back after each test.
 */
class DeviceHealthTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    /** What the faked app server answers for the fleet list: IMEI => health row. */
    private array $fleet = [];
    private string $apiMode = 'ok';   // ok | down | error | not_json | no_data
    private $adminLogin = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Http::fake(function (ClientRequest $request) {
            if (! str_contains($request->url(), '/api/internal/devices/health')) {
                return Http::response(['data' => []], 200);   // the sidebar's complaint count
            }

            return match ($this->apiMode) {
                'down'     => throw new \Illuminate\Http\Client\ConnectionException('cannot connect'),
                'error'    => Http::response(['message' => 'boom'], 500),
                'not_json' => Http::response('<html>bad gateway</html>', 200),
                'no_data'  => Http::response(['status' => 'ok'], 200),
                default    => Http::response(['data' => array_values($this->fleet)], 200),
            };
        });
    }

    // ------------------------------------------------------------------ helpers

    private function utc(float $hoursAgo): string
    {
        return Carbon::now('UTC')->subMinutes((int) round($hoursAgo * 60))->format('Y-m-d\TH:i:s');
    }

    /**
     * A PAID device bound $boundHoursAgo hours ago, held by $dealer, and what the app server says about it.
     * $health = null means the app server does not list the device at all.
     */
    private function paid(?Dealer $dealer, float $boundHoursAgo, ?array $health, array $overrides = []): ActivatedDevice
    {
        $device = $this->makeBound(array_merge(['payment_status' => 'Paid'], $overrides), $dealer);

        DB::table('activated_devices')
            ->where('activated_device_id', $device->activated_device_id)
            ->update(['created_at' => Carbon::now('UTC')->subMinutes((int) round($boundHoursAgo * 60))->format('Y-m-d H:i:s')]);

        if ($health !== null) {
            $this->fleet[$device->imei_number] = ['imei' => $device->imei_number] + $health;
        }

        return $device;
    }

    private function adminOpens(string $query = '')
    {
        $this->adminLogin ??= $this->makeAdmin('ADMIN');
        $this->actingAs($this->adminLogin);

        return $this->get(route('admin.device-health') . $query);
    }

    /** The rows of the page the admin sees, keyed by IMEI. */
    private function rowsByImei($response): array
    {
        $out = [];
        foreach ($response->viewData('rows')->items() as $row) {
            $out[$row['imei']] = $row;
        }

        return $out;
    }

    private function boundAt(float $hoursAgo): Carbon
    {
        return Carbon::now('UTC')->subMinutes((int) round($hoursAgo * 60));
    }

    // ------------------------------------------------------------------ the rules (no database, no network)

    public static function rules(): array
    {
        // [health from the app server (null = not listed), hours since the device was bound, expected type, expected level]
        return [
            'not listed, bound 30 minutes ago: still settling'   => [null, 0.5, null, null],
            'not listed, bound 2 hours ago'                      => [null, 2, 'not_linked', 'warning'],
            'not listed, bound 23 hours ago'                     => [null, 23, 'not_linked', 'warning'],
            'not listed, bound 24 hours ago'                     => [null, 24, 'not_linked', 'critical'],
            'listed, never reported, bound 23 hours ago'         => [['lastContactUtc' => null], 23, null, null],
            'listed, never reported, bound 24 hours ago'         => [['lastContactUtc' => null], 24, 'never_connected', 'warning'],
            'listed, never reported, bound 71 hours ago'         => [['lastContactUtc' => null], 71, 'never_connected', 'warning'],
            'listed, never reported, bound 72 hours ago'         => [['lastContactUtc' => null], 72, 'never_connected', 'critical'],
            'heard 23 hours ago, power fine'                     => [['lastContactUtc' => 'H23', 'power' => 'Connected'], 500, null, null],
            'heard 25 hours ago'                                 => [['lastContactUtc' => 'H25', 'power' => 'Connected'], 500, 'silent', 'warning'],
            'heard 73 hours ago'                                 => [['lastContactUtc' => 'H73', 'power' => 'Connected'], 500, 'silent', 'critical'],
            'heard 25 hours ago, power was cut'                  => [['lastContactUtc' => 'H25', 'power' => 'Disconnected'], 500, 'silent', 'critical'],
            'heard 1 hour ago, running on the battery'           => [['lastContactUtc' => 'H1', 'power' => 'Disconnected', 'batteryLevel' => 4], 500, 'unplugged', 'critical'],
            'heard 1 hour ago, power fine'                       => [['lastContactUtc' => 'H1', 'power' => 'Connected'], 500, null, null],
            'heard 1 hour ago, power not reported'               => [['lastContactUtc' => 'H1'], 500, null, null],
        ];
    }

    #[DataProvider('rules')]
    public function test_each_rule_fires_at_the_right_moment(?array $health, float $boundHoursAgo, ?string $type, ?string $level): void
    {
        $now = Carbon::now('UTC');
        if ($health !== null && isset($health['lastContactUtc']) && str_starts_with((string) $health['lastContactUtc'], 'H')) {
            $health['lastContactUtc'] = $now->copy()->subHours((int) substr($health['lastContactUtc'], 1))->format('Y-m-d\TH:i:s');
        }

        $problem = DeviceHealthReport::classify($health, $now->copy()->subMinutes((int) round($boundHoursAgo * 60)), $now);

        if ($type === null) {
            $this->assertNull($problem);

            return;
        }

        $this->assertNotNull($problem);
        $this->assertSame($type, $problem['type']);
        $this->assertSame($level, $problem['level']);
    }

    public function test_the_detail_text_says_what_to_do(): void
    {
        $now = Carbon::now('UTC');

        $silent = DeviceHealthReport::classify(['lastContactUtc' => $now->copy()->subHours(30)->format('Y-m-d\TH:i:s'), 'power' => 'Disconnected'], $now->copy()->subDays(30), $now);
        $this->assertStringContainsString('No signal for 1d 6h', $silent['detail']);
        $this->assertStringContainsString('possible cut wire', $silent['detail']);

        $low = DeviceHealthReport::classify(['lastContactUtc' => $now->copy()->subHour()->format('Y-m-d\TH:i:s'), 'power' => 'Disconnected', 'batteryLevel' => 1], $now->copy()->subDays(30), $now);
        $this->assertStringContainsString('level 1 of 6, low', $low['detail']);

        $fine = DeviceHealthReport::classify(['lastContactUtc' => $now->copy()->subHour()->format('Y-m-d\TH:i:s'), 'power' => 'Disconnected', 'batteryLevel' => 5], $now->copy()->subDays(30), $now);
        $this->assertStringNotContainsString('low', $fine['detail']);
    }

    public function test_an_unreadable_contact_time_counts_as_never_reported(): void
    {
        $now = Carbon::now('UTC');

        $problem = DeviceHealthReport::classify(['lastContactUtc' => 'not a date'], $now->copy()->subDays(5), $now);

        $this->assertSame('never_connected', $problem['type']);
    }

    // ------------------------------------------------------------------ the list

    public function test_only_paid_devices_with_a_problem_are_listed_one_problem_each(): void
    {
        $dealer = $this->makeDealer();
        $silent = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected']);
        $healthy = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(1), 'power' => 'Connected']);
        $notLinked = $this->paid($dealer, 5, null);
        $unpaid = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(100)], ['payment_status' => 'not-Paid']);
        $both = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Disconnected']);   // silent AND unplugged

        $response = $this->adminOpens()->assertOk();
        $rows = $this->rowsByImei($response);

        $this->assertTrue($response->viewData('available'));
        $this->assertSame('silent', $rows[$silent->imei_number]['type']);
        $this->assertSame('not_linked', $rows[$notLinked->imei_number]['type']);
        $this->assertSame('silent', $rows[$both->imei_number]['type'], 'the most serious problem only');
        $this->assertArrayNotHasKey($healthy->imei_number, $rows);
        $this->assertArrayNotHasKey($unpaid->imei_number, $rows, 'a lapsed customer is a renewals matter');
    }

    public function test_a_row_carries_the_customer_the_vehicle_and_the_dealer(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest Health Dealer']);
        $device = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected'], [
            'customer_name' => 'Autotest Health Customer', 'vehicle_number' => 'AUTO-HEALTH-1',
        ]);
        $this->makeCustomerRecord($device, '0771234567');

        $row = $this->rowsByImei($this->adminOpens())[$device->imei_number];

        $this->assertSame('Autotest Health Customer', $row['customer_name']);
        $this->assertSame('0771234567', $row['customer_phone']);
        $this->assertSame('AUTO-HEALTH-1', $row['vehicle_number']);
        $this->assertSame('Autotest Health Dealer', $row['dealer_name']);
    }

    public function test_the_most_serious_and_longest_silent_come_first(): void
    {
        $dealer = $this->makeDealer();
        $warning = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected']);
        $longest = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(200), 'power' => 'Connected']);
        $critical = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(80), 'power' => 'Connected']);

        $order = array_map('strval', array_keys($this->rowsByImei($this->adminOpens())));   // an all-digit key becomes a number: compare as text
        $mine = array_values(array_intersect($order, [$warning->imei_number, $longest->imei_number, $critical->imei_number]));

        $this->assertSame([$longest->imei_number, $critical->imei_number, $warning->imei_number], $mine);
    }

    public function test_the_counts_cover_the_whole_list_and_the_filters_only_narrow_it(): void
    {
        $dealer = $this->makeDealer();
        $silent = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected']);        // warning
        $cut = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(1), 'power' => 'Disconnected']);          // critical, unplugged
        $lost = $this->paid($dealer, 100, null);                                                                    // critical, not linked

        $all = $this->adminOpens();
        $this->assertGreaterThanOrEqual(3, $all->viewData('counts')['total']);

        $critical = $this->rowsByImei($this->adminOpens('?level=critical'));
        $this->assertArrayHasKey($cut->imei_number, $critical);
        $this->assertArrayHasKey($lost->imei_number, $critical);
        $this->assertArrayNotHasKey($silent->imei_number, $critical);

        $unplugged = $this->rowsByImei($this->adminOpens('?type=unplugged'));
        $this->assertArrayHasKey($cut->imei_number, $unplugged);
        $this->assertArrayNotHasKey($silent->imei_number, $unplugged);

        $both = $this->rowsByImei($this->adminOpens('?level=warning&type=silent'));
        $this->assertArrayHasKey($silent->imei_number, $both);
        $this->assertArrayNotHasKey($cut->imei_number, $both);

        // The counts shown above the filters do not shrink when a filter is on.
        $this->assertSame($all->viewData('counts'), $this->adminOpens('?type=unplugged')->viewData('counts'));
        $this->assertSame($all->viewData('typeCounts'), $this->adminOpens('?type=unplugged')->viewData('typeCounts'));
    }

    public static function strangeFilters(): array
    {
        return [
            'unknown level'    => ['?level=urgent', 'all', 'all'],
            'unknown type'     => ['?type=everything', 'all', 'all'],
            'a list'           => ['?level[]=critical&type[]=silent', 'all', 'all'],
            'sql in a value'   => ["?type=silent'%20or%20'1'='1", 'all', 'all'],
            'very long text'   => ['?level=' . '' . 'x', 'all', 'all'],
            'a page far away'  => ['?page=99999999999', 'all', 'all'],
            'a page of text'   => ['?page=abc', 'all', 'all'],
            'a negative page'  => ['?page=-5', 'all', 'all'],
        ];
    }

    #[DataProvider('strangeFilters')]
    public function test_strange_filter_values_never_crash_and_fall_back_to_all(string $query, string $level, string $type): void
    {
        $dealer = $this->makeDealer();
        $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected']);

        $response = $this->adminOpens($query)->assertOk();

        $this->assertSame($level, $response->viewData('level'));
        $this->assertSame($type, $response->viewData('type'));
    }

    public function test_the_list_is_split_in_pages_of_50(): void
    {
        $dealer = $this->makeDealer();
        foreach (range(1, 53) as $i) {
            $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30 + $i), 'power' => 'Connected']);
        }

        $first = $this->adminOpens()->viewData('rows');
        $this->assertCount(50, $first->items());
        $this->assertGreaterThanOrEqual(53, $first->total());

        $second = $this->adminOpens('?page=2')->viewData('rows');
        $this->assertGreaterThanOrEqual(3, count($second->items()));
        $this->assertSame([], array_intersect(array_column($first->items(), 'imei'), array_column($second->items(), 'imei')), 'no device on two pages');
    }

    // ------------------------------------------------------------------ when the app server cannot answer

    public static function brokenAnswers(): array
    {
        return [
            'cannot connect'    => ['down'],
            'server error'      => ['error'],
            'not json'          => ['not_json'],
            'no data in answer' => ['no_data'],
        ];
    }

    #[DataProvider('brokenAnswers')]
    public function test_a_broken_answer_says_not_available_and_never_an_empty_healthy_list(string $mode): void
    {
        $dealer = $this->makeDealer();
        $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(80)]);
        $this->apiMode = $mode;

        $response = $this->adminOpens()->assertOk();

        $this->assertFalse($response->viewData('available'));
        $this->assertSame(0, $response->viewData('rows')->total());
        $this->assertSame(0, $response->viewData('counts')['total']);
    }

    public function test_a_failed_answer_is_not_remembered_but_a_good_one_is(): void
    {
        $dealer = $this->makeDealer();
        $device = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(80), 'power' => 'Connected']);

        $this->apiMode = 'error';
        $this->assertFalse($this->adminOpens()->viewData('available'));
        $this->assertNull(Cache::get(DeviceHealthReport::CACHE_KEY), 'a failure must not be cached');

        $this->apiMode = 'ok';
        $this->assertTrue($this->adminOpens()->viewData('available'), 'the server is back: ask again');
        $this->assertIsArray(Cache::get(DeviceHealthReport::CACHE_KEY));

        // Now the server is down again, but the good answer (valid for 2 minutes) is still used.
        $this->apiMode = 'down';
        $again = $this->adminOpens()->assertOk();
        $this->assertTrue($again->viewData('available'));
        $this->assertArrayHasKey($device->imei_number, $this->rowsByImei($again));
    }

    public function test_the_server_is_asked_with_the_sync_key_and_a_row_without_an_imei_is_skipped(): void
    {
        config(['services.shalotrack_api.sync_key' => 'autotest-sync-key']);
        $dealer = $this->makeDealer();
        $device = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(80), 'power' => 'Connected']);
        $this->fleet['broken'] = ['imei' => '', 'lastContactUtc' => $this->utc(80)];

        $response = $this->adminOpens()->assertOk();

        Http::assertSent(fn (ClientRequest $r) => str_contains($r->url(), '/api/internal/devices/health')
            && $r->hasHeader('X-Admin-Sync-Key', 'autotest-sync-key'));
        $this->assertArrayHasKey($device->imei_number, $this->rowsByImei($response));
    }

    // ------------------------------------------------------------------ CSV

    public function test_the_csv_download_lists_the_filtered_rows(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest Csv Health Dealer']);
        $silent = $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected'], ['customer_name' => 'Autotest Csv Customer']);
        $lost = $this->paid($dealer, 100, null);

        $response = $this->adminOpens('?export=csv&type=silent');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('device-health-', $response->headers->get('Content-Disposition'));

        $lines = $this->csvBody($response);
        $this->assertSame(['Severity', 'Problem', 'Customer', 'Phone', 'Vehicle', 'IMEI', 'Dealer', 'Last contact', 'Hours', 'Detail'], $lines[0]);
        $imeis = array_column($lines, 5);
        $this->assertContains($silent->imei_number, $imeis);
        $this->assertNotContains($lost->imei_number, $imeis, 'the filter is applied to the file too');

        $mine = collect($lines)->first(fn ($l) => ($l[5] ?? '') === $silent->imei_number);
        $this->assertSame('warning', $mine[0]);
        $this->assertSame('Gone silent', $mine[1]);
        $this->assertSame('Autotest Csv Customer', $mine[2]);
        $this->assertSame('Autotest Csv Health Dealer', $mine[6]);
    }

    public function test_a_formula_in_a_customer_name_cannot_run_in_excel(): void
    {
        $dealer = $this->makeDealer();
        $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected'], ['customer_name' => '=cmd|calc!A1']);

        $lines = $this->csvBody($this->adminOpens('?export=csv'));

        $cell = collect($lines)->pluck(2)->first(fn ($c) => str_contains((string) $c, 'cmd|calc'));
        $this->assertNotNull($cell);
        $this->assertStringStartsWith("'=", $cell);
    }

    public function test_no_csv_is_offered_when_the_data_is_not_available(): void
    {
        $this->makeDealer();
        $this->apiMode = 'down';

        $response = $this->adminOpens('?export=csv')->assertOk();

        $this->assertStringNotContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertFalse($response->viewData('available'));
    }

    // ------------------------------------------------------------------ a dealer sees only its own devices

    public function test_a_dealer_sees_only_the_devices_it_sold(): void
    {
        $mine = $this->makeDealer();
        $other = $this->makeDealer();
        $myDevice = $this->paid($mine, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected']);
        $theirDevice = $this->paid($other, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected']);

        $this->actingAs($this->makeDealerLogin($mine));
        $response = $this->get(route('dealer.device-health'))->assertOk();
        $rows = $this->rowsByImei($response);

        $this->assertSame([(string) $myDevice->imei_number], array_map('strval', array_keys($rows)));
        $this->assertFalse($response->viewData('showDealer'), 'a dealer does not need a Dealer column');
        $response->assertDontSee($theirDevice->imei_number);
    }

    public function test_a_dealer_cannot_ask_for_another_dealer_with_the_address(): void
    {
        $mine = $this->makeDealer();
        $other = $this->makeDealer();
        $theirDevice = $this->paid($other, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected']);

        $this->actingAs($this->makeDealerLogin($mine));
        $response = $this->get(route('dealer.device-health', ['dealer_id' => $other->id, 'dealer' => $other->id]))->assertOk();

        $this->assertSame([], $this->rowsByImei($response));
        $response->assertDontSee($theirDevice->imei_number);
    }

    public function test_a_dealer_login_with_no_dealer_record_sees_nothing_and_nothing_is_asked(): void
    {
        $other = $this->makeDealer();
        $this->paid($other, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected']);

        $this->actingAs($this->makeAdmin('DEALER', ['dealer_id' => null]));
        $response = $this->get(route('dealer.device-health'))->assertOk();

        $this->assertSame([], $this->rowsByImei($response));
        $this->assertTrue($response->viewData('available'), 'it is an empty list, not a broken page');
        Http::assertNotSent(fn (ClientRequest $r) => str_contains($r->url(), '/api/internal/devices/health'));
    }

    public function test_the_admin_page_shows_the_dealer_column(): void
    {
        $this->makeDealer();

        $this->assertTrue($this->adminOpens()->viewData('showDealer'));
    }

    // ------------------------------------------------------------------ who may open what

    public static function otherRolesForAdminPage(): array
    {
        return ['DEALER' => ['DEALER'], 'DISTRIBUTOR' => ['DISTRIBUTOR'], 'FINANCE' => ['FINANCE'], 'TECHNICIAN' => ['TECHNICIAN'], 'SUPPLIER' => ['SUPPLIER']];
    }

    #[DataProvider('otherRolesForAdminPage')]
    public function test_only_an_admin_opens_the_staff_page(string $role): void
    {
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('admin.device-health'))->assertForbidden();
        $this->get(route('admin.device-health', ['export' => 'csv']))->assertForbidden();
    }

    public static function rolesThatAreNotDealers(): array
    {
        return ['ADMIN' => ['ADMIN'], 'DISTRIBUTOR' => ['DISTRIBUTOR'], 'FINANCE' => ['FINANCE'], 'TECHNICIAN' => ['TECHNICIAN'], 'SUPPLIER' => ['SUPPLIER']];
    }

    #[DataProvider('rolesThatAreNotDealers')]
    public function test_only_a_dealer_opens_the_dealer_page(string $role): void
    {
        $this->actingAs($this->makeAdmin($role));

        $this->get(route('dealer.device-health'))->assertForbidden();
    }

    public function test_a_visitor_who_is_not_logged_in_is_sent_to_the_login_page(): void
    {
        $this->get(route('admin.device-health'))->assertRedirect(route('login'));
        $this->get(route('dealer.device-health'))->assertRedirect(route('login'));
    }

    // ------------------------------------------------------------------ safe output

    public function test_a_script_in_a_customer_name_is_shown_as_text(): void
    {
        $dealer = $this->makeDealer();
        $this->paid($dealer, 500, ['lastContactUtc' => $this->utc(30), 'power' => 'Connected'], [
            'customer_name' => 'Autotest <script>alert(1)</script> Health',
        ]);

        $response = $this->adminOpens()->assertOk();

        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }
}