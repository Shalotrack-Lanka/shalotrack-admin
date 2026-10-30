<?php

namespace Tests\Feature;

use App\Models\GatewayStatusEvent;
use App\Services\GatewayStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Gateway status service, history and the gateway:check command.
 *
 * sqlite in-memory ONLY. setUp refuses to run on anything else: an earlier suite run against
 * the production database caused an incident (see project docs, 2026-10-28).
 */
class GatewayStatusTest extends TestCase
{
    private const GW = 'http://gateway.shalotrack.internal:8001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', \DB::getDriverName(), 'GatewayStatusTest must only run on sqlite.');

        config([
            'services.gateway.command_url'     => self::GW,
            'services.gateway.command_api_key' => 'test-key',
            'services.gateway.public_probe'    => false,
        ]);
        Cache::flush();

        Schema::create('gateway_status_events', function (Blueprint $t) {
            $t->id();
            $t->string('state', 10);
            $t->text('reasons')->nullable();
            $t->unsignedInteger('devices_connected')->nullable();
            $t->boolean('public_path_ok')->nullable();
            $t->timestamp('recorded_at')->index();
        });
    }

    /** Http::fake() keeps the FIRST matching stub, so start from a clean factory every time. */
    private function freshHttp(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
    }

    private function health(array $body, int $status = 200): void
    {
        $this->freshHttp();
        Http::fake([self::GW . '/health' => Http::response($body, $status)]);
    }

    // ---- probe ------------------------------------------------------------------------------

    public function test_ok_report(): void
    {
        $this->health(['status' => 'ok', 'reasons' => [], 'devices_connected' => 7, 'last_packet_age_s' => 3.5]);

        $s = GatewayStatus::current(true);

        $this->assertSame('ok', $s['state']);
        $this->assertSame(7, $s['devices_connected']);
        $this->assertSame(3.5, $s['last_packet_age_s']);
        $this->assertSame([], $s['reasons']);
        Http::assertSent(fn ($r) => $r->hasHeader('X-Command-Api-Key', 'test-key') && str_ends_with($r->url(), '/health'));
    }

    public function test_degraded_and_down_reports_pass_through_with_reasons(): void
    {
        $this->health(['status' => 'degraded', 'reasons' => ['Database slow']], 200);
        $this->assertSame(['degraded', ['Database slow']], [GatewayStatus::current(true)['state'], GatewayStatus::current(true)['reasons']]);

        $this->health(['status' => 'down', 'reasons' => ['Event loop stuck']], 503);
        $s = GatewayStatus::current(true);
        $this->assertSame('down', $s['state']);
        $this->assertSame(['Event loop stuck'], $s['reasons']);
    }

    public function test_reasons_are_capped_and_truncated(): void
    {
        $this->health(['status' => 'degraded', 'reasons' => array_fill(0, 20, str_repeat('x', 500))]);

        $s = GatewayStatus::current(true);

        $this->assertCount(6, $s['reasons']);
        $this->assertLessThanOrEqual(201, mb_strlen($s['reasons'][0]));
    }

    public function test_connection_failure_is_down(): void
    {
        $this->freshHttp();
        Http::fake([self::GW . '/health' => fn () => throw new \Illuminate\Http\Client\ConnectionException('refused')]);

        $s = GatewayStatus::current(true);

        $this->assertSame('down', $s['state']);
        $this->assertStringNotContainsString('refused', implode(' ', $s['reasons']), 'raw exception text must not reach the page');
    }

    public function test_rejected_key_is_degraded_not_down(): void
    {
        $this->health(['error' => 'unauthorized'], 401);

        $s = GatewayStatus::current(true);

        $this->assertSame('degraded', $s['state']);
        $this->assertStringContainsString('401', $s['reasons'][0]);
    }

    public function test_old_gateway_with_plain_ok_body_is_ok(): void
    {
        $this->health(['status' => 'ok']);

        $s = GatewayStatus::current(true);

        $this->assertSame('ok', $s['state']);
        $this->assertNull($s['devices_connected']);
    }

    public function test_unexpected_body_is_degraded(): void
    {
        $this->freshHttp();
        Http::fake([self::GW . '/health' => Http::response('<html>proxy error</html>', 502)]);

        $this->assertSame('degraded', GatewayStatus::current(true)['state']);

        $this->health(['status' => 'banana']);
        $this->assertSame('degraded', GatewayStatus::current(true)['state']);
    }

    // ---- public path probe --------------------------------------------------------------------

    public function test_tcp_reachable_against_a_real_local_socket(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server);
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);

        $this->assertTrue(GatewayStatus::tcpReachable('127.0.0.1', $port, 1.0));

        fclose($server);
        $this->assertFalse(GatewayStatus::tcpReachable('127.0.0.1', $port, 1.0));
    }

    public function test_closed_public_port_makes_status_down_even_if_health_is_ok(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        config(['services.gateway.public_probe' => true, 'services.gateway.public_host' => '127.0.0.1', 'services.gateway.public_port' => $port]);
        $this->health(['status' => 'ok', 'reasons' => []]);

        $s = GatewayStatus::current(true);

        $this->assertSame('down', $s['state']);
        $this->assertFalse($s['public_path_ok']);
        $this->assertStringContainsString('public device endpoint', $s['reasons'][0]);
    }

    public function test_open_public_port_keeps_ok(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
        config(['services.gateway.public_probe' => true, 'services.gateway.public_host' => '127.0.0.1', 'services.gateway.public_port' => $port]);
        $this->health(['status' => 'ok']);

        $s = GatewayStatus::current(true);
        fclose($server);

        $this->assertSame('ok', $s['state']);
        $this->assertTrue($s['public_path_ok']);
    }

    public function test_public_probe_disabled_reports_null(): void
    {
        $this->health(['status' => 'ok']);

        $this->assertNull(GatewayStatus::current(true)['public_path_ok']);
    }

    // ---- caching ----------------------------------------------------------------------------

    public function test_current_is_cached_and_fresh_bypasses_the_cache(): void
    {
        $this->health(['status' => 'ok']);
        GatewayStatus::current();
        GatewayStatus::current();
        Http::assertSentCount(1);

        GatewayStatus::current(true);
        Http::assertSentCount(2);
    }

    public function test_broken_cache_store_does_not_break_status(): void
    {
        $this->health(['status' => 'ok']);
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('cache down'));
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('cache down'));

        $this->assertSame('ok', GatewayStatus::current()['state']);
    }

    // ---- connectedDevices -------------------------------------------------------------------

    public function test_connected_devices_ok(): void
    {
        $this->freshHttp();
        Http::fake([self::GW . '/devices' => Http::response(['connected_devices' => [['imei' => '1'], ['imei' => '2']]])]);

        $r = GatewayStatus::connectedDevices();

        $this->assertTrue($r['ok']);
        $this->assertCount(2, $r['devices']);
    }

    public function test_connected_devices_unreachable_and_401(): void
    {
        $this->freshHttp();
        Http::fake([self::GW . '/devices' => fn () => throw new \Illuminate\Http\Client\ConnectionException('x')]);
        $this->assertFalse(GatewayStatus::connectedDevices()['ok']);

        $this->freshHttp();
        Http::fake([self::GW . '/devices' => Http::response([], 401)]);
        $r = GatewayStatus::connectedDevices();
        $this->assertFalse($r['ok']);
        $this->assertCount(0, $r['devices']);
    }

    // ---- track / history ----------------------------------------------------------------------

    private function reading(string $state, ?string $at = null): array
    {
        $at ??= now()->toIso8601String();

        return ['state' => $state, 'reasons' => $state === 'ok' ? [] : ['r'], 'devices_connected' => 3,
            'last_packet_age_s' => null, 'public_path_ok' => null, 'checked_at' => $at, 'checked_ts' => strtotime($at)];
    }

    public function test_single_reading_is_not_recorded(): void
    {
        $this->assertNull(GatewayStatus::track($this->reading('down')));
        $this->assertSame(0, GatewayStatusEvent::count());
    }

    public function test_two_consecutive_readings_record_one_event_dated_from_the_first(): void
    {
        $first = now()->subMinute()->toIso8601String();

        GatewayStatus::track($this->reading('down', $first));
        $event = GatewayStatus::track($this->reading('down'));

        $this->assertNotNull($event);
        $this->assertSame('down', $event->state);
        $this->assertEqualsWithDelta(strtotime($first), $event->recorded_at->getTimestamp(), 1);
    }

    public function test_no_duplicate_rows_while_state_is_unchanged(): void
    {
        foreach (range(1, 6) as $_) {
            GatewayStatus::track($this->reading('ok'));
        }

        $this->assertSame(1, GatewayStatusEvent::count());
    }

    public function test_flapping_single_blips_are_never_recorded(): void
    {
        foreach (['ok', 'ok', 'down', 'ok', 'down', 'ok', 'ok'] as $st) {
            GatewayStatus::track($this->reading($st));
        }

        $this->assertSame(['ok'], GatewayStatusEvent::pluck('state')->all());
    }

    public function test_track_without_table_does_not_throw(): void
    {
        Schema::drop('gateway_status_events');

        GatewayStatus::track($this->reading('down'));
        $this->assertNull(GatewayStatus::track($this->reading('down')));
    }

    public function test_history_availability_math(): void
    {
        // 10 days ago ok; down for exactly 1 day starting 5 days ago; ok since.
        GatewayStatusEvent::create(['state' => 'ok', 'recorded_at' => now()->subDays(10)]);
        GatewayStatusEvent::create(['state' => 'down', 'recorded_at' => now()->subDays(5)]);
        GatewayStatusEvent::create(['state' => 'ok', 'recorded_at' => now()->subDays(4)]);

        $h = GatewayStatus::history(30);

        $this->assertTrue($h['available']);
        // Known time = 10 days (history starts there), down = 1 day -> 90%.
        $this->assertEqualsWithDelta(90.0, $h['availability_pct'], 0.05);
        $this->assertSame(10, $h['covered_days']);
        $this->assertCount(3, $h['recent']);
        $this->assertSame('ok', $h['recent'][0]->state, 'newest first');
    }

    public function test_history_degraded_counts_as_available(): void
    {
        GatewayStatusEvent::create(['state' => 'degraded', 'recorded_at' => now()->subDays(2)]);

        $this->assertEquals(100.0, GatewayStatus::history(30)['availability_pct']);
    }

    public function test_history_empty_and_missing_table(): void
    {
        $this->assertFalse(GatewayStatus::history()['available']);

        Schema::drop('gateway_status_events');
        $h = GatewayStatus::history();
        $this->assertFalse($h['available']);
        $this->assertNull($h['availability_pct']);
    }

    // ---- heartbeat / command -------------------------------------------------------------------

    public function test_scheduler_running_flag(): void
    {
        $this->assertFalse(GatewayStatus::schedulerRunning());

        GatewayStatus::heartbeat();
        $this->assertTrue(GatewayStatus::schedulerRunning());

        $this->travel(4)->minutes();
        $this->assertFalse(GatewayStatus::schedulerRunning());
    }

    public function test_gateway_check_command_records_and_always_succeeds(): void
    {
        $this->health(['status' => 'down', 'reasons' => ['Event loop stuck']], 503);

        $this->artisan('gateway:check')->assertSuccessful();
        $this->assertSame(0, GatewayStatusEvent::count(), 'first reading is not yet an incident');
        $this->assertTrue(GatewayStatus::schedulerRunning());

        $this->artisan('gateway:check')->assertSuccessful();
        $this->assertSame(1, GatewayStatusEvent::count());
        $this->assertSame('down', GatewayStatusEvent::first()->state);
        $this->assertSame(['Event loop stuck'], GatewayStatusEvent::first()->reasons);
    }

    public function test_gateway_check_succeeds_even_when_gateway_unreachable(): void
    {
        $this->freshHttp();
        Http::fake([self::GW . '/health' => fn () => throw new \Illuminate\Http\Client\ConnectionException('x')]);

        $this->artisan('gateway:check')->assertSuccessful();
    }

    // ---- rendering ---------------------------------------------------------------------------

    public function test_health_card_escapes_reasons_and_explains_missing_scheduler(): void
    {
        $html = View::make('admin.partials.gateway-health-card', [
            'gatewayStatus'           => array_merge($this->reading('degraded'), ['age_s' => 12, 'reasons' => ['<script>alert(1)</script>']]),
            'gatewayHistory'          => ['available' => false, 'availability_pct' => null, 'covered_days' => null, 'recent' => collect()],
            'gatewaySchedulerRunning' => false,
        ])->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('not being recorded', $html);
        $this->assertStringNotContainsString('100', $html, 'must not invent an availability figure');
    }

    public function test_badge_renders_each_state(): void
    {
        foreach (['ok' => 'Gateway Online', 'degraded' => 'Gateway Degraded', 'down' => 'Gateway Offline'] as $state => $label) {
            $html = View::make('admin.partials.gateway-badge', ['gatewayStatus' => ['state' => $state]])->render();
            $this->assertStringContainsString($label, $html);
        }
    }
}