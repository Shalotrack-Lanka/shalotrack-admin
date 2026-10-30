<?php

namespace App\Services;

use App\Models\GatewayStatusEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The one place that knows how to ask "is the gateway healthy?".
 *
 * Before this class, the gateway call was copy-pasted into six places, each treating
 * "/devices answered 200" as "gateway online". That proves the gateway's HTTP thread
 * is alive and nothing else.
 *
 * Two probes, from the Admin server:
 *   1. GET {command_url}/health -- the gateway's deep health report (event loop, database,
 *      pool, capacity, packet flow). Private network, shared-secret header.
 *   2. A plain TCP connect to the PUBLIC device endpoint (gateway.shalotrack.com:8000) --
 *      the path real devices use. Catches Cloudflare/NLB/security-group breakage that no
 *      internal check can see.
 *
 * Result: ok | degraded | down, plus the reasons, in a small array (see probe()).
 *
 * Rollout note: an older gateway still answers /health with just {"status":"ok"}. That is
 * accepted as "ok", so the portal can be deployed before the gateway.
 *
 * Continuous history needs the Laravel scheduler (gateway:check, every minute). Without it
 * the dashboard still shows live status, but history and availability are NOT recorded --
 * schedulerRunning() lets the page say so instead of showing a misleading 100%.
 */
class GatewayStatus
{
    public const OK = 'ok';
    public const DEGRADED = 'degraded';
    public const DOWN = 'down';

    private const RANK = [self::OK => 0, self::DEGRADED => 1, self::DOWN => 2];

    private const CACHE_KEY     = 'gateway_status:current';
    private const STREAK_KEY    = 'gateway_status:streak';
    private const HEARTBEAT_KEY = 'gateway_status:heartbeat';

    /**
     * A probe result is reused this long. gateway:check refreshes it every minute when the
     * scheduler runs; without a scheduler a page load re-probes at most this often.
     */
    private const CACHE_SECONDS = 90;

    /** A state must be seen on this many consecutive checks before it is written to history. */
    private const CONFIRM_READINGS = 2;

    /** gateway:check counts as "the scheduler is running" for this long after its last run. */
    private const SCHEDULER_STALE_AFTER = 180;

    /**
     * The gateway's current status. Cached (see CACHE_SECONDS); $fresh bypasses and refreshes it.
     *
     * @return array{
     *   state: string, reasons: list<string>, devices_connected: ?int, last_packet_age_s: ?float,
     *   public_path_ok: ?bool, checked_at: string, checked_ts: int, age_s: int
     * }
     */
    public static function current(bool $fresh = false): array
    {
        if (! $fresh) {
            $cached = self::cacheGet(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['state'], $cached['checked_ts'])) {
                $cached['age_s'] = max(0, now()->getTimestamp() - (int) $cached['checked_ts']);

                return $cached;
            }
        }

        $status = self::probe();
        self::cachePut(self::CACHE_KEY, $status, self::CACHE_SECONDS);
        $status['age_s'] = 0;

        return $status;
    }

    /**
     * Connected devices straight from the gateway, uncached (the Device Command Center needs live
     * data). Same call the controllers used to make themselves; 'ok' is whether the Command API
     * answered, which is exactly "can I send commands right now".
     *
     * @return array{ok: bool, devices: Collection}
     */
    public static function connectedDevices(): array
    {
        try {
            $response = Http::timeout(3)
                ->withHeaders(['X-Command-Api-Key' => self::apiKey()])
                ->get(self::baseUrl() . '/devices');

            if ($response->successful()) {
                return ['ok' => true, 'devices' => collect($response->json('connected_devices') ?? [])];
            }

            if ($response->status() === 401) {
                // A rejected key used to be indistinguishable from "gateway down" and logged nothing.
                Log::warning('Gateway /devices rejected the portal key (HTTP 401): GATEWAY_COMMAND_API_KEY does not match the gateway.');
            }
        } catch (\Throwable $e) {
            Log::warning('Gateway /devices unreachable: ' . $e->getMessage());
        }

        return ['ok' => false, 'devices' => collect()];
    }

    /** Plain TCP connect, nothing sent. Public so the probe can be tested against a local socket. */
    public static function tcpReachable(string $host, int $port, float $timeout = 2.0): bool
    {
        $errno  = 0;
        $errstr = '';
        $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    /**
     * Record a state change in history. Called by gateway:check only -- never from a page load,
     * because history built from "whenever someone had the dashboard open" would show a gateway
     * that went down overnight as 100% available.
     *
     * A state is written only after CONFIRM_READINGS identical readings in a row (one timeout must
     * not become an incident) and only if it differs from the last recorded state. The row is dated
     * from the first reading of the streak.
     */
    public static function track(array $status): ?GatewayStatusEvent
    {
        $state  = $status['state'];
        $streak = self::cacheGet(self::STREAK_KEY);

        if (is_array($streak) && ($streak['state'] ?? null) === $state) {
            $streak['count'] = (int) ($streak['count'] ?? 0) + 1;
        } else {
            $streak = ['state' => $state, 'count' => 1, 'since' => $status['checked_at']];
        }
        self::cachePut(self::STREAK_KEY, $streak, 900);

        if ($streak['count'] < self::CONFIRM_READINGS || ! self::historyTableExists()) {
            return null;
        }

        $last = GatewayStatusEvent::orderByDesc('recorded_at')->orderByDesc('id')->first();
        if ($last && $last->state === $state) {
            return null;
        }

        return GatewayStatusEvent::create([
            'state'             => $state,
            'reasons'           => $status['reasons'],
            'devices_connected' => $status['devices_connected'],
            'public_path_ok'    => $status['public_path_ok'],
            'recorded_at'       => Carbon::parse($streak['since']),
        ]);
    }

    /** gateway:check calls this on every run; schedulerRunning() reads it. */
    public static function heartbeat(): void
    {
        self::cachePut(self::HEARTBEAT_KEY, now()->getTimestamp(), 900);
    }

    public static function schedulerRunning(): bool
    {
        $beat = self::cacheGet(self::HEARTBEAT_KEY);

        return is_numeric($beat) && (now()->getTimestamp() - (int) $beat) <= self::SCHEDULER_STALE_AFTER;
    }

    /**
     * Incident history and availability for the dashboard. Never throws: a missing table (code
     * deployed before the migration) or any query problem just reports "no history".
     *
     * Availability = share of the window NOT spent in "down" (degraded still serves traffic).
     *
     * @return array{available: bool, availability_pct: ?float, covered_days: ?int, recent: Collection}
     */
    public static function history(int $days = 30): array
    {
        $none = ['available' => false, 'availability_pct' => null, 'covered_days' => null, 'recent' => collect()];

        try {
            if (! self::historyTableExists()) {
                return $none;
            }

            $recent = GatewayStatusEvent::orderByDesc('recorded_at')->orderByDesc('id')->limit(5)->get();
            if ($recent->isEmpty()) {
                return $none;
            }

            $now         = now();
            $windowStart = $now->copy()->subDays($days);

            // Only the transitions inside the window, plus the one before it (it set the state the
            // window opens in).
            $before = GatewayStatusEvent::where('recorded_at', '<', $windowStart)
                ->orderByDesc('recorded_at')->orderByDesc('id')->first();
            $inside = GatewayStatusEvent::where('recorded_at', '>=', $windowStart)
                ->orderBy('recorded_at')->orderBy('id')->get();

            $timeline = ($before ? collect([$before]) : collect())->concat($inside)->values();

            $total = 0;
            $down  = 0;
            foreach ($timeline as $i => $event) {
                $from = max($event->recorded_at->getTimestamp(), $windowStart->getTimestamp());
                $to   = isset($timeline[$i + 1]) ? $timeline[$i + 1]->recorded_at->getTimestamp() : $now->getTimestamp();
                $secs = max(0, $to - $from);

                $total += $secs;
                if ($event->state === self::DOWN) {
                    $down += $secs;
                }
            }

            return [
                'available'        => true,
                'availability_pct' => $total > 0 ? round(100 * (1 - $down / $total), 2) : null,
                'covered_days'     => $total > 0 ? max(1, (int) ceil($total / 86400)) : null,
                'recent'           => $recent,
            ];
        } catch (\Throwable $e) {
            Log::warning('Gateway status history unavailable: ' . $e->getMessage());

            return $none;
        }
    }

    // ------------------------------------------------------------------------------------------

    private static function probe(): array
    {
        $state     = self::OK;
        $reasons   = [];
        $devices   = null;
        $packetAge = null;

        // 1. The gateway's own deep health report.
        try {
            $response = Http::timeout(4)
                ->withHeaders(['X-Command-Api-Key' => self::apiKey()])
                ->get(self::baseUrl() . '/health');
            $body = $response->json();

            if ($response->status() === 401) {
                // Gateway is up, but the portal is blind to it: a config problem, not an outage.
                $state     = self::DEGRADED;
                $reasons[] = 'The gateway rejected this portal\'s key (HTTP 401) — GATEWAY_COMMAND_API_KEY here does not match the gateway\'s command_api_key.';
            } elseif (is_array($body) && in_array($body['status'] ?? null, [self::OK, self::DEGRADED, self::DOWN], true)) {
                $state = $body['status'];

                foreach (array_slice((array) ($body['reasons'] ?? []), 0, 6) as $reason) {
                    if (is_scalar($reason)) {
                        $reasons[] = Str::limit((string) $reason, 200, '…');
                    }
                }

                $devices   = isset($body['devices_connected']) && is_numeric($body['devices_connected']) ? (int) $body['devices_connected'] : null;
                $packetAge = isset($body['last_packet_age_s']) && is_numeric($body['last_packet_age_s']) ? (float) $body['last_packet_age_s'] : null;
            } else {
                $state     = self::DEGRADED;
                $reasons[] = 'The gateway answered /health with an unexpected response (HTTP ' . $response->status() . ').';
            }
        } catch (\Throwable $e) {
            $state     = self::DOWN;
            $reasons[] = 'The gateway Command API did not answer — the gateway process, or the network path to it, is down.';
            Log::warning('Gateway status: /health unreachable: ' . $e->getMessage());
        }

        // 2. The path real devices use.
        $publicOk = null;
        if (config('services.gateway.public_probe')) {
            $host = (string) config('services.gateway.public_host');
            $port = (int) config('services.gateway.public_port');

            if ($host !== '' && $port > 0) {
                $publicOk = self::tcpReachable($host, $port, 2.0);

                if (! $publicOk) {
                    $state     = self::worst($state, self::DOWN);
                    $reasons[] = "The public device endpoint {$host}:{$port} is not accepting connections (tested from the admin server) — "
                        . 'check that the Cloudflare record is DNS-only (grey cloud), the NLB listener and the gateway security group.';
                }
            }
        }

        return [
            'state'             => $state,
            'reasons'           => $reasons,
            'devices_connected' => $devices,
            'last_packet_age_s' => $packetAge,
            'public_path_ok'    => $publicOk,
            'checked_at'        => now()->toIso8601String(),
            'checked_ts'        => now()->getTimestamp(),
        ];
    }

    private static function worst(string $a, string $b): string
    {
        return self::RANK[$a] >= self::RANK[$b] ? $a : $b;
    }

    private static function baseUrl(): string
    {
        return rtrim((string) config('services.gateway.command_url', 'http://gateway.shalotrack.internal:8001'), '/');
    }

    private static function apiKey(): string
    {
        return (string) config('services.gateway.command_api_key', '');
    }

    private static function historyTableExists(): bool
    {
        return Schema::hasTable('gateway_status_events');
    }

    // A broken cache store must never take the dashboard (or a command screen) down with it:
    // the old code had no cache, so adding one must not add a way to fail.

    private static function cacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable $e) {
            Log::warning("Gateway status: cache read failed ({$key}): " . $e->getMessage());

            return null;
        }
    }

    private static function cachePut(string $key, mixed $value, int $seconds): void
    {
        try {
            Cache::put($key, $value, $seconds);
        } catch (\Throwable $e) {
            Log::warning("Gateway status: cache write failed ({$key}): " . $e->getMessage());
        }
    }
}