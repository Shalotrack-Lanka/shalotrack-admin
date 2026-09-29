<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Counts tracked vehicles whose device has not reported a GPS point in the last 24 hours.
 *
 * Uses the C# API's latest GPS point per IMEI (gps-tracking-sync with no date range returns the
 * newest point first). It deliberately does NOT use vehicle_ad.last_synced_at: that column only says
 * when this portal last pulled the vehicle list, and every pull refreshes every row, so it can never
 * show a silent device.
 *
 * The API loads the whole vehicle list on every call, so the check is cached for five minutes, runs
 * ten requests at a time and looks at no more than MAX_CHECKED vehicles per refresh.
 */
class StaleDeviceService
{
    public const MAX_CHECKED = 100;
    public const CACHE_KEY   = 'dashboard_stale_devices';
    private const CACHE_SECONDS = 300;

    /**
     * @param  Collection<int, string>  $imeis  IMEIs of tracked vehicles
     * @return array{stale:int, checked:int, unknown:int, unavailable:bool}
     */
    public function summarize(Collection $imeis): array
    {
        $imeis = $imeis->filter()->unique()->values();

        if ($imeis->isEmpty()) {
            return ['stale' => 0, 'checked' => 0, 'unknown' => 0, 'unavailable' => false];
        }

        try {
            // A failed check throws, so a failure is never cached as "0 stale".
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->check($imeis));
        } catch (\Throwable $e) {
            Log::warning('Dashboard: stale device check failed: ' . $e->getMessage());

            return ['stale' => 0, 'checked' => 0, 'unknown' => $imeis->count(), 'unavailable' => true];
        }
    }

    private function check(Collection $imeis): array
    {
        $stale = 0;
        $checked = 0;
        $unknown = 0;
        $cutoff = now()->subDay();

        foreach ($imeis->take(self::MAX_CHECKED)->chunk(10) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => $chunk->map(
                fn ($imei) => $pool->as($imei)
                    ->timeout(8)
                    ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                    ->acceptJson()
                    ->get(config('services.shalotrack_api.base_url') . '/api/internal/gps-tracking-sync', [
                        'imei' => $imei, 'page' => 1, 'pageSize' => 1,
                    ])
            )->all());

            foreach ($chunk as $imei) {
                $response = $responses[$imei] ?? null;

                if (! $response instanceof \Illuminate\Http\Client\Response || ! $response->successful()) {
                    $unknown++;   // API error or unknown IMEI: neither fresh nor proven stale
                    continue;
                }

                $checked++;
                $eventTime = $response->json('currentLocation.eventTime');

                // Event times come from the API in UTC (no offset in the string).
                if (! $eventTime || Carbon::parse($eventTime, 'UTC')->lt($cutoff)) {
                    $stale++;
                }
            }
        }

        if ($checked === 0) {
            throw new \RuntimeException('None of the ' . $unknown . ' GPS lookups succeeded.');
        }

        return ['stale' => $stale, 'checked' => $checked, 'unknown' => $unknown, 'unavailable' => false];
    }
}