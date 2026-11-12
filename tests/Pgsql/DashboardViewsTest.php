<?php

namespace Tests\Pgsql;

use App\Enums\DeviceStatus;
use App\Models\Admin;
use App\Models\CustomerAd;
use App\Models\Dealer;
use App\Models\DashboardMetricSnapshot;
use App\Models\DealerCustomerAd;
use App\Models\SetupShalotrackDevice;
use App\Models\Sim;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\VehicleAd;
use App\Services\StaleDeviceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * THE TWO MAIN DASHBOARDS, on real PostgreSQL (autotest schema):
 *   admin.dashboard    the Admin landing page (totals, customers, gateway, complaints, stock, stale GPS, trends)
 *   dealer.dashboard   the Dealer landing page (dealer record repair, own devices, own leads)
 *
 * What the tests prove (simple words):
 *   - every number on the Admin page moves when the real data moves (checked as "before and after" so old rows in the
 *     test database cannot confuse the result),
 *   - when the complaints service, the gateway or the GPS check is down the page SAYS so, instead of showing a calm "0",
 *   - the complaints API answer is used as given (newest first, top dealer, average resolution time) and a failure is not cached,
 *   - customer names and dealer names are shown as text, never as HTML,
 *   - only the Admin role can open the Admin page; guests go to login,
 *   - the Dealer page finds the dealer by email or by the id on the login, creates one when missing, repairs ugly
 *     names, links the login to the dealer, and shows ONLY that dealer's own devices and leads.
 *
 * The outside world is never called: the complaints, gateway and GPS answers are placed in the cache by the test,
 * and any other web call is refused by Http::preventStrayRequests().
 */
class DashboardViewsTest extends PgsqlTestCase
{
    use DatabaseTransactions, CreatesTestAdmins, BuildsIntakeData, BuildsReportData;

    private const KEYS = ['dashboard_open_complaints', 'dashboard_resolved_complaints', 'gateway_status:current', StaleDeviceService::CACHE_KEY];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['services.gateway.public_probe' => false]);
        $this->clearCaches();
        $this->prime();
    }

    protected function tearDown(): void
    {
        $this->clearCaches();
        parent::tearDown();
    }

    private function clearCaches(): void
    {
        foreach (self::KEYS as $k) {
            Cache::forget($k);
        }
    }

    /** Put believable answers in the cache so the page never calls the network. */
    private function prime(array $gateway = [], ?array $open = [], ?array $resolved = [], ?array $stale = ['stale' => 0, 'checked' => 0, 'unknown' => 0, 'unavailable' => false]): void
    {
        Cache::put('gateway_status:current', array_merge([
            'state' => 'ok', 'reasons' => [], 'devices_connected' => 0, 'last_packet_age_s' => 1.0,
            'public_path_ok' => null, 'checked_at' => now()->toIso8601String(), 'checked_ts' => now()->getTimestamp(), 'age_s' => 0,
        ], $gateway), 300);

        foreach (['dashboard_open_complaints' => $open, 'dashboard_resolved_complaints' => $resolved, StaleDeviceService::CACHE_KEY => $stale] as $key => $value) {
            $value === null ? Cache::forget($key) : Cache::put($key, $value, 300);
        }
    }

    private function page(Admin $admin)
    {
        return $this->actingAs($admin)->get(route('admin.dashboard'));
    }

    private function data($response, string $key)
    {
        return $response->viewData($key);
    }

    private function device(array $overrides = []): SetupShalotrackDevice
    {
        $n = $this->nextIntakeNumber();

        $device = new SetupShalotrackDevice();
        // forceFill: assigned_customer_id is deliberately not mass-assignable in the app.
        $device->forceFill(array_merge([
            'device_category' => 'AUTOTEST-CAT', 'imei_number' => $this->validImei(),
            'sim_number' => '07' . str_pad((string) $n, 9, '0', STR_PAD_LEFT), 'status' => DeviceStatus::NotActivated->value,
        ], $overrides))->save();

        return $device;
    }

    // ================================================================== Admin dashboard: access

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public static function outsiders(): array
    {
        return array_map(fn ($r) => [$r], ['DEALER', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('outsiders')]
    public function test_only_the_admin_role_can_open_the_admin_dashboard(string $role): void
    {
        $this->actingAs($this->makeAdmin($role))->get(route('admin.dashboard'))->assertForbidden();
    }

    public function test_the_admin_can_open_the_dashboard(): void
    {
        $this->page($this->makeAdmin('ADMIN'))->assertOk()->assertViewIs('admin.dashboard');
    }

    // ================================================================== Admin dashboard: numbers follow the data

    public function test_the_totals_move_when_the_real_data_moves(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        $b = $this->page($admin);
        $before = [];
        foreach (['totalDevices', 'activatedDevices', 'notActivatedDevices', 'totalSuppliers', 'activeSuppliers', 'totalDealers', 'activeDealers', 'totalSIMs', 'activatedSIMs', 'totalCustomers', 'verifiedCustomers', 'notVerifiedCustomers'] as $k) {
            $before[$k] = $this->data($b, $k);
        }

        $this->device(['status' => DeviceStatus::NotActivated->value]);
        $this->device(['status' => DeviceStatus::Activated->value]);
        $this->device(['status' => DeviceStatus::Activated->value]);
        Supplier::create(['name' => 'Autotest Supplier A', 'status' => 'Active']);
        Supplier::create(['name' => 'Autotest Supplier B', 'status' => 'Inactive']);
        $this->makeDealer(['status' => 'active']);
        $this->makeDealer(['status' => 'inactive']);
        $this->makeSim('Activated');
        $this->makeSim('Not Activated');
        CustomerAd::create(['customer_id' => (string) Str::uuid(), 'full_name' => 'Autotest V', 'cus_status' => 'verified']);
        CustomerAd::create(['customer_id' => (string) Str::uuid(), 'full_name' => 'Autotest N', 'cus_status' => 'not_verified']);
        CustomerAd::create(['customer_id' => (string) Str::uuid(), 'full_name' => 'Autotest Null']);

        $a = $this->page($admin);

        $expected = [
            'totalDevices' => 3, 'activatedDevices' => 2, 'notActivatedDevices' => 1, 'totalSuppliers' => 2, 'activeSuppliers' => 1,
            'totalDealers' => 2, 'activeDealers' => 1, 'totalSIMs' => 2, 'activatedSIMs' => 1, 'totalCustomers' => 3,
            'verifiedCustomers' => 1, 'notVerifiedCustomers' => 2,
        ];
        foreach ($expected as $k => $delta) {
            $this->assertSame($before[$k] + $delta, $this->data($a, $k), $k);
        }
    }

    public function test_stock_units_and_out_of_stock_categories_follow_the_stock_table(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        $b = $this->page($admin);
        [$u, $o, $names] = [$this->data($b, 'stockUnitsAvailable'), $this->data($b, 'outOfStockCategories'), $this->data($b, 'outOfStockCategoryNames')];

        $full = $this->makeDeviceType();
        $empty = $this->makeDeviceType();
        $this->stockFor($full, 7);
        $this->stockFor($empty, 0);

        $a = $this->page($admin);

        $this->assertSame($u + 7, $this->data($a, 'stockUnitsAvailable'));
        $this->assertSame($o + 1, $this->data($a, 'outOfStockCategories'));
        $this->assertContains($empty->device_category . ' ' . $empty->model, $this->data($a, 'outOfStockCategoryNames'));
        $this->assertNotContains($full->device_category . ' ' . $full->model, $this->data($a, 'outOfStockCategoryNames'));
        $this->assertCount($o + 1, $this->data($a, 'outOfStockCategoryNames'));
        unset($names);
    }

    public function test_tracked_vehicles_count_only_those_with_an_imei_and_offline_is_never_negative(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        $this->prime(['devices_connected' => 0]);
        $b = $this->page($admin);
        $tracked = $this->data($b, 'totalTrackedVehicles');

        foreach ([$this->validImei(), $this->validImei(), null, ''] as $i => $imei) {
            VehicleAd::create(['vehicle_id' => (string) Str::uuid(), 'customer_id' => (string) Str::uuid(), 'vehicle_number' => 'AUTO-D' . $this->nextIntakeNumber(), 'imei' => $imei]);
        }

        $a = $this->page($admin);
        $this->assertSame($tracked + 2, $this->data($a, 'totalTrackedVehicles'));
        $this->assertSame($tracked + 2, $this->data($a, 'devicesOfflineNow'));

        // More devices online than tracked vehicles: offline must stop at 0, never go negative.
        $this->prime(['devices_connected' => $tracked + 500]);
        $c = $this->page($admin);
        $this->assertSame($tracked + 500, $this->data($c, 'devicesOnlineNow'));
        $this->assertSame(0, $this->data($c, 'devicesOfflineNow'));
    }

    public function test_the_six_month_growth_chart_has_six_labels_and_never_goes_down(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        CustomerAd::create(['customer_id' => (string) Str::uuid(), 'full_name' => 'Autotest Growth']);

        $r = $this->page($admin);
        $labels = $this->data($r, 'customerGrowthLabels');
        $series = $this->data($r, 'customerGrowthData');

        $this->assertCount(6, $labels);
        $this->assertCount(6, $series);
        $this->assertSame(now()->format('M'), $labels[5]);
        $this->assertSame(now()->subMonths(5)->format('M'), $labels[0]);
        $sorted = $series;
        sort($sorted);
        $this->assertSame($sorted, $series);
        $this->assertSame(CustomerAd::count(), $series[5]);
    }

    public function test_recent_customers_are_the_newest_six_with_only_the_listed_fields(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        foreach (range(1, 8) as $i) {
            (new CustomerAd())->forceFill(['customer_id' => (string) Str::uuid(), 'full_name' => "Autotest Recent {$i}", 'created_at' => now()->addDays(30)->addMinutes($i), 'updated_at' => now()->addDays(30)->addMinutes($i)])->save();
        }

        $recent = $this->data($this->page($admin), 'recentCustomers');

        $this->assertCount(6, $recent);
        $this->assertSame('Autotest Recent 8', $recent[0]->full_name);
        $this->assertSame('Autotest Recent 3', $recent[5]->full_name);
        $this->assertEqualsCanonicalizing(['customer_id', 'full_name', 'email', 'phone_number', 'nic_number', 'address', 'cus_status'], array_keys($recent[0]->getAttributes()));
    }

    public function test_names_with_html_are_shown_as_text(): void
    {
        $admin = $this->makeAdmin('ADMIN');
        (new CustomerAd())->forceFill(['customer_id' => (string) Str::uuid(), 'full_name' => '<img src=x onerror=alert(1)>', 'created_at' => now()->addDays(60), 'updated_at' => now()->addDays(60)])->save();
        $this->prime(open: [['createdAt' => now()->toIso8601String(), 'dealerName' => '<script>alert(9)</script>']]);

        $html = $this->page($admin)->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringNotContainsString('<script>alert(9)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(9)&lt;/script&gt;', $html);
    }

    // ================================================================== Admin dashboard: gateway

    public static function gatewayStates(): array
    {
        return ['ok' => ['ok', true], 'degraded still serves devices' => ['degraded', true], 'down' => ['down', false]];
    }

    #[DataProvider('gatewayStates')]
    public function test_the_gateway_is_online_unless_it_is_down(string $state, bool $online): void
    {
        $this->prime(['state' => $state]);

        $r = $this->page($this->makeAdmin('ADMIN'));

        $this->assertSame($online, $this->data($r, 'gatewayOnline'));
        $this->assertSame($state, $this->data($r, 'gatewayStatus')['state']);
    }

    public function test_devices_online_comes_from_the_gateway_report(): void
    {
        $this->prime(['devices_connected' => 42]);

        $this->assertSame(42, $this->data($this->page($this->makeAdmin('ADMIN')), 'devicesOnlineNow'));
    }

    // ================================================================== Admin dashboard: complaints

    public function test_open_complaints_are_counted_newest_first_and_only_five_are_listed(): void
    {
        $open = [];
        foreach (range(1, 7) as $i) {
            $open[] = ['id' => $i, 'createdAt' => now()->subDays(10 - $i)->toIso8601String(), 'dealerName' => 'Dealer ' . ($i <= 4 ? 'Alpha' : 'Beta')];
        }
        $this->prime(open: $open);

        $r = $this->page($this->makeAdmin('ADMIN'));

        // The cached list is shown as stored; the sort happens when it is fetched, so only count and slice are checked here.
        $this->assertSame(7, $this->data($r, 'openComplaintsCount'));
        $this->assertCount(5, $this->data($r, 'recentComplaints'));
        $this->assertSame('Dealer Alpha', $this->data($r, 'topDealerName'));
        $this->assertSame(4, $this->data($r, 'topDealerComplaintCount'));
        $this->assertFalse($this->data($r, 'complaintsUnavailable'));
    }

    public function test_complaints_without_a_dealer_are_grouped_as_unassigned(): void
    {
        $this->prime(open: [['createdAt' => now()->toIso8601String()], ['createdAt' => now()->toIso8601String(), 'dealerName' => '  '], ['createdAt' => now()->toIso8601String(), 'DealerName' => 'Only One']]);

        $r = $this->page($this->makeAdmin('ADMIN'));

        $this->assertSame('Unassigned', $this->data($r, 'topDealerName'));
        $this->assertSame(2, $this->data($r, 'topDealerComplaintCount'));
    }

    public function test_with_no_open_complaints_there_is_no_top_dealer(): void
    {
        $r = $this->page($this->makeAdmin('ADMIN'));

        $this->assertSame(0, $this->data($r, 'openComplaintsCount'));
        $this->assertNull($this->data($r, 'topDealerName'));
        $this->assertSame(0, $this->data($r, 'topDealerComplaintCount'));
        $this->assertFalse($this->data($r, 'complaintsUnavailable'));
    }

    public function test_the_average_resolution_time_is_in_hours_and_ignores_bad_rows(): void
    {
        $t = now()->startOfHour();
        $this->prime(resolved: [
            ['createdAt' => $t->copy()->subHours(2)->toIso8601String(), 'resolvedAt' => $t->toIso8601String()],                 // 2h
            ['CreatedAt' => $t->copy()->subHours(4)->toIso8601String(), 'ResolvedAt' => $t->toIso8601String()],                 // 4h (other key spelling)
            ['createdAt' => $t->toIso8601String(), 'resolvedAt' => $t->copy()->subHour()->toIso8601String()],                    // resolved before created: ignored
            ['createdAt' => 'not a date', 'resolvedAt' => $t->toIso8601String()],                                               // unreadable: ignored
            ['createdAt' => $t->toIso8601String()],                                                                              // never resolved: ignored
        ]);

        $r = $this->page($this->makeAdmin('ADMIN'));

        $this->assertSame(5, $this->data($r, 'resolvedComplaintsCount'));
        $this->assertEquals(3.0, $this->data($r, 'avgResolutionHours'));
    }

    public function test_no_resolved_complaints_means_no_average(): void
    {
        $r = $this->page($this->makeAdmin('ADMIN'));

        $this->assertSame(0, $this->data($r, 'resolvedComplaintsCount'));
        $this->assertNull($this->data($r, 'avgResolutionHours'));
    }

    public function test_when_the_complaints_service_fails_the_page_says_unavailable_and_does_not_cache_the_failure(): void
    {
        $this->prime(open: null, resolved: null);
        Http::fake(['*' => Http::response('boom', 500)]);
        $admin = $this->makeAdmin('ADMIN');

        $r = $this->page($admin)->assertOk();

        $this->assertTrue($this->data($r, 'complaintsUnavailable'));
        $this->assertSame(0, $this->data($r, 'openComplaintsCount'));
        $this->assertFalse(Cache::has('dashboard_open_complaints'));
        $this->assertFalse(Cache::has('dashboard_resolved_complaints'));
        $this->assertStringContainsString('unavailable', strtolower($r->getContent()));
    }

    public static function oneSidedFailures(): array
    {
        return ['only the open list fails' => ['for-admin/resolved'], 'only the resolved list fails' => ['for-admin']];
    }

    #[DataProvider('oneSidedFailures')]
    public function test_if_either_complaints_call_fails_the_page_says_unavailable(string $worksEnding): void
    {
        $this->prime(open: null, resolved: null);
        Http::fake(fn ($request) => str_ends_with($request->url(), $worksEnding) ? Http::response(['data' => []], 200) : Http::response('boom', 500));

        $this->assertTrue($this->data($this->page($this->makeAdmin('ADMIN')), 'complaintsUnavailable'));
    }

    public function test_when_the_complaints_service_is_back_the_next_load_shows_the_real_numbers(): void
    {
        $this->prime(open: null, resolved: null);
        $admin = $this->makeAdmin('ADMIN');

        $serviceUp = false;
        Http::fake(function ($request) use (&$serviceUp) {
            if (! $serviceUp) {
                return Http::response('boom', 500);
            }
            if (str_contains($request->url(), 'for-admin/resolved')) {
                return Http::response(['data' => [['createdAt' => now()->subHour()->toIso8601String(), 'resolvedAt' => now()->toIso8601String()]]], 200);
            }

            return Http::response(['data' => [['id' => 1, 'createdAt' => '2026-01-01T00:00:00Z', 'dealerName' => 'Old'], ['id' => 2, 'createdAt' => '2026-02-01T00:00:00Z', 'dealerName' => 'New']]], 200);
        });

        $this->assertTrue($this->data($this->page($admin), 'complaintsUnavailable'));

        $serviceUp = true;
        $r = $this->page($admin);

        $this->assertFalse($this->data($r, 'complaintsUnavailable'));
        $this->assertSame(2, $this->data($r, 'openComplaintsCount'));
        $this->assertSame(1, $this->data($r, 'resolvedComplaintsCount'));
        $this->assertSame('New', $this->data($r, 'recentComplaints')[0]['dealerName'], 'newest complaint first');
    }

    public function test_the_complaints_calls_carry_the_shared_key_and_are_cached_for_the_next_load(): void
    {
        config(['services.shalotrack_api.sync_key' => 'autotest-key', 'services.shalotrack_api.base_url' => 'https://api.example.test']);
        $this->prime(open: null, resolved: null);
        Http::fake(['*' => Http::response(['data' => []], 200)]);
        $admin = $this->makeAdmin('ADMIN');

        $this->page($admin)->assertOk();
        $this->page($admin)->assertOk();

        // Two page loads, but each complaints call went out only once: the second load used the cache.
        $open = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/api/internal/complaints/for-admin'));
        $resolved = collect(Http::recorded())->filter(fn ($p) => str_ends_with($p[0]->url(), '/api/internal/complaints/for-admin/resolved'));
        // The resolved list is only used by the dashboard, so one call proves the second load used the cache.
        // (The sidebar reads the open list too, with its own cache, so that URL is checked for the key only.)
        $this->assertCount(1, $resolved);
        $this->assertNotEmpty($open);
        foreach ($open->merge($resolved) as $pair) {
            $this->assertSame(['autotest-key'], $pair[0]->header('X-Admin-Sync-Key'));
        }
    }

    // ================================================================== Admin dashboard: stale GPS and trends

    public function test_the_stale_gps_count_and_unavailable_flag_are_passed_to_the_page(): void
    {
        $this->prime(stale: ['stale' => 3, 'checked' => 10, 'unknown' => 0, 'unavailable' => false]);
        VehicleAd::create(['vehicle_id' => (string) Str::uuid(), 'customer_id' => (string) Str::uuid(), 'vehicle_number' => 'AUTO-S1', 'imei' => $this->validImei()]);
        $admin = $this->makeAdmin('ADMIN');

        $r = $this->page($admin);
        $this->assertSame(3, $this->data($r, 'staleDevicesCount'));
        $this->assertFalse($this->data($r, 'staleDevicesUnavailable'));
        $this->assertStringContainsString('no GPS point in 24h+', $r->getContent());

        $this->prime(stale: ['stale' => 0, 'checked' => 0, 'unknown' => 5, 'unavailable' => true]);
        $r = $this->page($admin);
        $this->assertTrue($this->data($r, 'staleDevicesUnavailable'));
        $this->assertStringContainsString('GPS check unavailable', $r->getContent());
    }

    public function test_trends_are_empty_without_a_week_old_snapshot(): void
    {
        DashboardMetricSnapshot::query()->delete();
        DashboardMetricSnapshot::create(['snapshot_date' => now()->subDays(3)->toDateString(), 'open_complaints_count' => 1, 'devices_online' => 1, 'devices_offline' => 1, 'stock_units_available' => 1]);

        $r = $this->page($this->makeAdmin('ADMIN'));

        $this->assertSame(['openComplaints' => null, 'devicesOnline' => null, 'stockUnits' => null], $this->data($r, 'trends'));
        $this->assertNull($this->data($r, 'trendSnapshotDate'));
    }

    public function test_trends_compare_with_the_newest_snapshot_that_is_at_least_a_week_old(): void
    {
        DashboardMetricSnapshot::query()->delete();
        DashboardMetricSnapshot::create(['snapshot_date' => now()->subDays(20)->toDateString(), 'open_complaints_count' => 100, 'devices_online' => 100, 'devices_offline' => 0, 'stock_units_available' => 100]);
        DashboardMetricSnapshot::create(['snapshot_date' => now()->subDays(8)->toDateString(), 'open_complaints_count' => 1, 'devices_online' => 5, 'devices_offline' => 0, 'stock_units_available' => 2]);
        DashboardMetricSnapshot::create(['snapshot_date' => now()->subDays(2)->toDateString(), 'open_complaints_count' => 50, 'devices_online' => 50, 'devices_offline' => 0, 'stock_units_available' => 50]);
        $this->prime(['devices_connected' => 9], open: [['createdAt' => now()->toIso8601String()], ['createdAt' => now()->toIso8601String()], ['createdAt' => now()->toIso8601String()]]);

        $r = $this->page($this->makeAdmin('ADMIN'));

        $trends = $this->data($r, 'trends');
        $this->assertSame(2, $trends['openComplaints']);          // 3 now - 1 then
        $this->assertSame(4, $trends['devicesOnline']);           // 9 now - 5 then
        $this->assertSame($this->data($r, 'stockUnitsAvailable') - 2, $trends['stockUnits']);
        $this->assertSame(now()->subDays(8)->format('M j'), $this->data($r, 'trendSnapshotDate'));
        $this->assertStringContainsString('vs ' . now()->subDays(8)->format('M j'), $r->getContent());
    }

    // ================================================================== Dealer dashboard

    private function dealerPage(Admin $login)
    {
        return $this->actingAs($login)->get(route('dealer.dashboard'));
    }

    public function test_a_guest_is_sent_to_login_from_the_dealer_dashboard(): void
    {
        $this->get(route('dealer.dashboard'))->assertRedirect(route('login'));
    }

    public static function notDealers(): array
    {
        return array_map(fn ($r) => [$r], ['ADMIN', 'DISTRIBUTOR', 'FINANCE', 'TECHNICIAN', 'SUPPLIER']);
    }

    #[DataProvider('notDealers')]
    public function test_only_the_dealer_role_can_open_the_dealer_dashboard(string $role): void
    {
        $this->actingAs($this->makeAdmin($role))->get(route('dealer.dashboard'))->assertForbidden();
    }

    public function test_the_dealer_is_found_by_the_login_email_in_any_letter_case_and_linked_to_the_login(): void
    {
        $dealer = $this->makeDealer(['contact_email' => 'Dealer.Match@Example.Test']);
        $login = $this->makeAdmin('DEALER', ['email' => 'dealer.match@example.test']);
        $before = Dealer::count();

        $r = $this->dealerPage($login)->assertOk()->assertViewIs('dealer.dashboard');

        $this->assertSame($dealer->id, $r->viewData('dealer')->id);
        $this->assertSame($before, Dealer::count(), 'no second dealer is created');
        $this->assertSame($dealer->id, $login->fresh()->dealer_id);
    }

    public function test_the_dealer_is_found_by_the_dealer_id_on_the_login_when_the_email_does_not_match(): void
    {
        $dealer = $this->makeDealer();
        $login = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id]);
        $before = Dealer::count();

        $r = $this->dealerPage($login)->assertOk();

        $this->assertSame($dealer->id, $r->viewData('dealer')->id);
        $this->assertSame($before, Dealer::count());
    }

    public function test_a_dealer_login_with_no_dealer_gets_one_created_and_linked(): void
    {
        $login = $this->makeAdmin('DEALER', ['full_name' => 'Autotest New Dealer', 'email' => 'brand.new.dealer@example.test']);
        $before = Dealer::count();

        $r = $this->dealerPage($login)->assertOk();

        $dealer = $r->viewData('dealer');
        $this->assertSame($before + 1, Dealer::count());
        $this->assertSame('brand.new.dealer@example.test', $dealer->contact_email);
        $this->assertSame('active', $dealer->status);
        $this->assertSame($dealer->id, $login->fresh()->dealer_id);
    }

    public static function uglyNames(): array
    {
        return ['placeholder' => ['Dealer Account'], 'default' => ['Default Dealer'], 'an email' => ['someone@example.test']];
    }

    #[DataProvider('uglyNames')]
    public function test_a_placeholder_or_email_dealer_name_is_replaced_by_the_login_name(string $ugly): void
    {
        $dealer = $this->makeDealer(['full_name' => $ugly]);
        $login = $this->makeAdmin('DEALER', ['full_name' => 'Autotest Proper Name', 'dealer_id' => $dealer->id, 'email' => $dealer->contact_email]);

        $this->dealerPage($login)->assertOk();

        $this->assertSame('Autotest Proper Name', $dealer->fresh()->full_name);
    }

    public function test_a_good_dealer_name_is_left_alone(): void
    {
        $dealer = $this->makeDealer(['full_name' => 'Autotest Real Dealer Ltd']);
        $login = $this->makeAdmin('DEALER', ['full_name' => 'Somebody Else', 'dealer_id' => $dealer->id, 'email' => $dealer->contact_email]);

        $this->dealerPage($login)->assertOk();

        $this->assertSame('Autotest Real Dealer Ltd', $dealer->fresh()->full_name);
    }

    public function test_a_login_pointing_to_the_wrong_dealer_is_corrected_to_the_email_match(): void
    {
        $mine = $this->makeDealer(['contact_email' => 'mine.dealer@example.test']);
        $other = $this->makeDealer();
        $login = $this->makeAdmin('DEALER', ['email' => 'mine.dealer@example.test', 'dealer_id' => $other->id]);

        $r = $this->dealerPage($login)->assertOk();

        $this->assertSame($mine->id, $r->viewData('dealer')->id);
        $this->assertSame($mine->id, $login->fresh()->dealer_id);
    }

    public function test_the_dealer_sees_only_their_own_devices_and_leads(): void
    {
        $mine = $this->makeDealer();
        $other = $this->makeDealer();
        $login = $this->makeDealerLogin($mine);

        $ownStock = $this->device(['dealer_id' => $mine->id, 'status' => DeviceStatus::Activated->value]);
        $otherStock = $this->device(['dealer_id' => $other->id, 'status' => DeviceStatus::Activated->value]);
        $soldTo = fn (Dealer $d) => DealerCustomerAd::create(['dealer_id' => $d->id, 'name' => 'Autotest Buyer ' . $this->nextIntakeNumber(), 'contact' => '0774444444', 'no_of_devices' => 1, 'imei_numbers' => []])->id;
        $ownSold = $this->device(['dealer_id' => $mine->id, 'status' => DeviceStatus::AssignedToCustomer->value, 'assigned_customer_id' => $soldTo($mine)]);
        $otherSold = $this->device(['dealer_id' => $other->id, 'status' => DeviceStatus::AssignedToCustomer->value, 'assigned_customer_id' => $soldTo($other)]);
        $ownPending = $this->device(['dealer_id' => $mine->id, 'status' => DeviceStatus::PendingRepair->value]);
        $otherPending = $this->device(['dealer_id' => $other->id, 'status' => DeviceStatus::PendingRepair->value]);
        $ownBroken = $this->device(['dealer_id' => $mine->id, 'status' => DeviceStatus::BrokenDevice->value]);
        $otherBroken = $this->device(['dealer_id' => $other->id, 'status' => DeviceStatus::BrokenDevice->value]);
        $n = $this->nextIntakeNumber();
        $ownLead = DealerCustomerAd::create(['dealer_id' => $mine->id, 'name' => "Autotest Own Lead {$n}", 'contact' => '0771111111', 'no_of_devices' => 0, 'imei_numbers' => []]);
        $otherLead = DealerCustomerAd::create(['dealer_id' => $other->id, 'name' => "Autotest Other Lead {$n}", 'contact' => '0772222222', 'no_of_devices' => 0, 'imei_numbers' => []]);

        $r = $this->dealerPage($login)->assertOk();

        $ids = fn ($key) => collect($r->viewData($key))->pluck('shdevice_id')->all();

        $this->assertContains($ownStock->shdevice_id, $ids('allocatedDevices'));
        $this->assertNotContains($otherStock->shdevice_id, $ids('allocatedDevices'));
        $this->assertContains($ownSold->shdevice_id, $ids('assignedDevices'));
        $this->assertNotContains($otherSold->shdevice_id, $ids('assignedDevices'));
        $this->assertSame([$ownPending->shdevice_id], $ids('pendingDevices'));
        $this->assertSame([$ownBroken->shdevice_id], $ids('brokenDevices'));
        $leadIds = $r->viewData('dealerLeads')->pluck('id')->all();
        $this->assertContains($ownLead->id, $leadIds);
        $this->assertNotContains($otherLead->id, $leadIds);
        $this->assertCount(2, $leadIds);   // the lead above plus the buyer of the sold device
        $this->assertSame(2, $r->viewData('totalCustomers'));
        $this->assertSame(count($ids('allocatedDevices')), $r->viewData('allocatedDevicesCount'));
        $this->assertSame(count($ids('assignedDevices')), $r->viewData('assignedDevicesCount'));
        unset($otherPending, $otherBroken);
    }

    public function test_a_dealer_with_nothing_sees_zero_everywhere(): void
    {
        $r = $this->dealerPage($this->makeDealerLogin($this->makeDealer()))->assertOk();

        foreach (['allocatedDevicesCount', 'assignedDevicesCount', 'totalCustomers', 'totalVehicles', 'activeGpsVehicles'] as $k) {
            $this->assertSame(0, $r->viewData($k), $k);
        }
        $this->assertCount(0, $r->viewData('pendingDevices'));
        $this->assertCount(0, $r->viewData('brokenDevices'));
    }

    public function test_a_device_marked_sold_with_no_customer_is_taken_back_into_stock(): void
    {
        $dealer = $this->makeDealer();
        $login = $this->makeDealerLogin($dealer);
        $nullCustomer = $this->device(['dealer_id' => $dealer->id, 'status' => DeviceStatus::AssignedToCustomer->value, 'assigned_customer_id' => null]);
        $buyer = DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => 'Autotest Buyer', 'contact' => '0775555555', 'no_of_devices' => 1, 'imei_numbers' => []]);
        $realSale = $this->device(['dealer_id' => $dealer->id, 'status' => DeviceStatus::AssignedToCustomer->value, 'assigned_customer_id' => $buyer->id]);

        $this->dealerPage($login)->assertOk();

        $this->assertSame(DeviceStatus::Activated->value, $nullCustomer->fresh()->status);
        $this->assertSame(DeviceStatus::AssignedToCustomer->value, $realSale->fresh()->status, 'a real sale is untouched');
    }

    public function test_another_dealers_orphaned_device_is_not_touched(): void
    {
        $mine = $this->makeDealer();
        $other = $this->makeDealer();
        $orphan = $this->device(['dealer_id' => $other->id, 'status' => DeviceStatus::AssignedToCustomer->value, 'assigned_customer_id' => null]);

        $this->dealerPage($this->makeDealerLogin($mine))->assertOk();

        $this->assertSame(DeviceStatus::AssignedToCustomer->value, $orphan->fresh()->status);
    }

    public function test_dealer_and_lead_names_are_shown_as_text(): void
    {
        $dealer = $this->makeDealer(['full_name' => '<script>alert(3)</script>']);
        $n = $this->nextIntakeNumber();
        DealerCustomerAd::create(['dealer_id' => $dealer->id, 'name' => '<img src=x onerror=alert(4)>', 'contact' => '0773333333', 'no_of_devices' => 0, 'imei_numbers' => []]);

        $html = $this->dealerPage($this->makeAdmin('DEALER', ['dealer_id' => $dealer->id, 'email' => $dealer->contact_email]))->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(3)</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(4)>', $html);
        unset($n);
    }
}