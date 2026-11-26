<?php

namespace Tests\Pgsql;

use App\Services\StaleDeviceService;
use App\Support\ApiCircuit;
use App\Support\ApiRecentlyUnreachable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Pgsql\Concerns\BuildsIntakeData;
use Tests\Pgsql\Concerns\BuildsReportData;
use Tests\Pgsql\Concerns\CreatesTestAdmins;
use Tests\PgsqlTestCase;

/**
 * When the mobile (C#) API or the gateway is down, the portal must stay fast.
 *
 * The admin sidebar asks the API for the complaint count on every page and the dashboard asks twice more; each
 * call used to wait up to 5 seconds. Now a connection failure is remembered for 30 seconds (ApiCircuit), the
 * connect timeout is short, and the dashboard does not ask a gateway it already knows is down.
 */
class ApiOutageResiliencePgTest extends PgsqlTestCase
{
    use DatabaseTransactions;
    use CreatesTestAdmins;
    use BuildsIntakeData;
    use BuildsReportData;

    private array $urls = [];

    /** When set, the faked outside world answers instead of refusing the connection. */
    private ?array $answerWhenBack = null;

    protected function setUp(): void
    {
        parent::setUp();
        ApiCircuit::reset();
        Cache::forget('dashboard_open_complaints');
        Cache::forget('dashboard_resolved_complaints');
        Cache::forget('admin_complaints_count');
        $this->urls = [];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Every outside call fails to connect, and each attempted URL is recorded. */
    private function everythingUnreachable(): void
    {
        Http::fake(function (Request $request) {
            $this->urls[] = $request->url();

            if ($this->answerWhenBack !== null) {
                return Http::response($this->answerWhenBack, 200);
            }

            throw new ConnectionException('Connection refused (test)');
        });
    }

    private function callsTo(string $needle): int
    {
        return count(array_filter($this->urls, fn ($u) => str_contains($u, $needle)));
    }

    private function primeQuietDashboard(array $gateway = []): void
    {
        Cache::put('gateway_status:current', array_merge([
            'state' => 'ok', 'reasons' => [], 'devices_connected' => 0, 'last_packet_age_s' => 1.0,
            'public_path_ok' => null, 'checked_at' => now()->toIso8601String(), 'checked_ts' => now()->getTimestamp(), 'age_s' => 0,
        ], $gateway), 300);
        Cache::put(StaleDeviceService::CACHE_KEY, ['stale' => 0, 'checked' => 0, 'unknown' => 0, 'unavailable' => false], 300);
    }

    // ------------------------------------------------------------------ the circuit itself

    public function test_after_a_connection_failure_the_api_is_not_called_again_for_30_seconds(): void
    {
        $this->everythingUnreachable();

        try {
            ApiCircuit::run(fn () => ApiCircuit::http()->get('http://api.test/one'));
            $this->fail('the first call should have failed to connect');
        } catch (ConnectionException) {
        }

        $this->assertTrue(ApiCircuit::isOpen());

        $this->expectException(ApiRecentlyUnreachable::class);
        try {
            ApiCircuit::run(fn () => ApiCircuit::http()->get('http://api.test/two'));
        } finally {
            $this->assertCount(1, $this->urls, 'the second call must not leave the server');
        }
    }

    public function test_the_api_is_tried_again_after_the_cooldown(): void
    {
        $this->everythingUnreachable();
        try {
            ApiCircuit::run(fn () => ApiCircuit::http()->get('http://api.test/one'));
        } catch (ConnectionException) {
        }

        Carbon::setTestNow(now()->addSeconds(ApiCircuit::COOLDOWN_SECONDS - 1));
        $this->assertTrue(ApiCircuit::isOpen(), 'still closed to calls just before the cooldown ends');

        Carbon::setTestNow(now()->addSeconds(2));
        $this->assertFalse(ApiCircuit::isOpen());

        $this->expectException(ConnectionException::class);
        ApiCircuit::run(fn () => ApiCircuit::http()->get('http://api.test/again'));
    }

    public function test_an_api_that_answers_with_an_error_is_reachable_and_does_not_open_the_circuit(): void
    {
        Http::fake(function (Request $request) {
            $this->urls[] = $request->url();

            return Http::response('boom', 500);
        });

        ApiCircuit::run(fn () => ApiCircuit::http()->get('http://api.test/one'));
        ApiCircuit::run(fn () => ApiCircuit::http()->get('http://api.test/two'));

        $this->assertFalse(ApiCircuit::isOpen());
        $this->assertCount(2, $this->urls);
    }

    public function test_other_errors_pass_through_and_do_not_open_the_circuit(): void
    {
        try {
            ApiCircuit::run(fn () => throw new \LogicException('a bug, not an outage'));
            $this->fail('should have thrown');
        } catch (\LogicException $e) {
            $this->assertSame('a bug, not an outage', $e->getMessage());
        }

        $this->assertFalse(ApiCircuit::isOpen());
    }

    public function test_the_api_client_has_a_short_connect_timeout_and_sends_the_shared_key(): void
    {
        config(['services.shalotrack_api.sync_key' => 'autotest-key']);

        $client = ApiCircuit::http(7);
        $options = $client->getOptions();

        $this->assertSame(ApiCircuit::CONNECT_TIMEOUT_SECONDS, $options['connect_timeout']);
        $this->assertLessThanOrEqual(2, $options['connect_timeout']);
        $this->assertSame(7, $options['timeout']);
        $this->assertSame('autotest-key', $options['headers']['X-Admin-Sync-Key']);
    }

    // ------------------------------------------------------------------ the admin pages

    public function test_with_the_api_down_the_dashboard_still_opens_says_unavailable_and_asks_the_api_once(): void
    {
        $this->primeQuietDashboard();
        $this->everythingUnreachable();

        $r = $this->actingAs($this->makeAdmin('ADMIN'))->get(route('admin.dashboard'));

        $r->assertOk();
        $this->assertStringContainsString('unavailable', strtolower($r->getContent()));
        // Sidebar, open complaints and resolved complaints all need the API: only the first one tries.
        $this->assertSame(1, $this->callsTo('/api/internal/complaints'), implode("\n", $this->urls));
    }

    public function test_the_next_pages_inside_the_30_seconds_make_no_api_call_at_all(): void
    {
        $this->primeQuietDashboard();
        $this->everythingUnreachable();
        $admin = $this->makeAdmin('ADMIN');

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->assertSame(1, $this->callsTo('/api/internal/complaints'));

        Cache::forget('admin_complaints_count'); // the sidebar's own 1-second cache must not hide the check
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(1, $this->callsTo('/api/internal/complaints'), 'no further call while the API is known to be down');
    }

    public function test_when_the_api_comes_back_the_dashboard_shows_its_data_again(): void
    {
        $this->primeQuietDashboard();
        $this->everythingUnreachable();
        $admin = $this->makeAdmin('ADMIN');
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        Carbon::setTestNow(now()->addSeconds(ApiCircuit::COOLDOWN_SECONDS + 1));
        Cache::put('gateway_status:current', ['state' => 'ok', 'reasons' => [], 'devices_connected' => 0, 'last_packet_age_s' => 1.0,
            'public_path_ok' => null, 'checked_at' => now()->toIso8601String(), 'checked_ts' => now()->getTimestamp(), 'age_s' => 0], 300);
        Cache::put(StaleDeviceService::CACHE_KEY, ['stale' => 0, 'checked' => 0, 'unknown' => 0, 'unavailable' => false], 300);
        Cache::forget('admin_complaints_count');
        $this->answerWhenBack = ['data' => [['status' => 'Escalated', 'dealerName' => 'Autotest Dealer', 'createdAt' => '2026-01-01T00:00:00Z']]];

        $r = $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();

        $this->assertFalse((bool) $r->viewData('complaintsUnavailable'));
        $this->assertSame(1, $r->viewData('openComplaintsCount'));
    }

    // ------------------------------------------------------------------ the dealer sidebar

    public function test_the_dealer_sidebar_also_stops_asking_a_down_api(): void
    {
        $this->everythingUnreachable();
        $dealer = $this->makeDealer();
        $login  = $this->makeAdmin('DEALER', ['dealer_id' => $dealer->id, 'email' => $dealer->contact_email]);

        $this->actingAs($login)->get(route('dealer.dashboard'))->assertOk();
        $first = $this->callsTo('/api/internal/complaints/by-dealer/');
        $this->assertSame(1, $first, implode("\n", $this->urls));

        Cache::forget('dealer_complaints_count_' . $dealer->id);
        $this->actingAs($login)->get(route('dealer.dashboard'))->assertOk();

        $this->assertSame(1, $this->callsTo('/api/internal/complaints/by-dealer/'));
    }

    // ------------------------------------------------------------------ the gateway

    public function test_a_gateway_known_to_be_down_is_not_asked_for_its_device_list_again(): void
    {
        $this->primeQuietDashboard(['state' => 'down', 'devices_connected' => null, 'reasons' => ['The gateway Command API did not answer']]);
        $this->everythingUnreachable();

        $r = $this->actingAs($this->makeAdmin('ADMIN'))->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(0, $this->callsTo('/devices'), implode("\n", $this->urls));
        $this->assertSame(0, (int) $r->viewData('devicesOnlineNow'));
    }

    public function test_an_older_gateway_without_a_device_count_in_health_still_gets_the_device_list_asked(): void
    {
        $this->primeQuietDashboard(['state' => 'ok', 'devices_connected' => null]);
        Http::fake(function (Request $request) {
            $this->urls[] = $request->url();

            return str_ends_with($request->url(), '/devices')
                ? Http::response(['connected_devices' => [['imei' => '1'], ['imei' => '2'], ['imei' => '3']]], 200)
                : Http::response(['data' => []], 200);
        });

        $r = $this->actingAs($this->makeAdmin('ADMIN'))->get(route('admin.dashboard'))->assertOk();

        $this->assertSame(1, $this->callsTo('/devices'));
        $this->assertSame(3, (int) $r->viewData('devicesOnlineNow'));
    }
}
