<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Free reverse geocoding via Nominatim (OpenStreetMap) — no API key, no
 * billing. In exchange, Nominatim's usage policy requires:
 *   1. Max 1 request/second from a given client.
 *   2. A real, identifying User-Agent header (anonymous requests get blocked).
 *   3. Not meant for heavy/bulk production traffic without self-hosting your
 *      own instance — fine for this admin portal's search-driven volume,
 *      not fine if this ever gets called in a tight loop over thousands of
 *      points.
 *
 * Cache-first, same as the Google version this replaces: results are cached
 * forever keyed by coordinates rounded to 4 decimal places (~11m grid), so
 * a location only ever costs one real request, no matter how many times
 * it's searched again later. Failures are NOT cached — a transient error
 * shouldn't permanently freeze a valid point at "unknown location."
 */
class AddressResolver
{
    private static ?float $lastRequestAt = null;

    public function resolve(float $lat, float $lng): string
    {
        $cacheKey = 'geocode:' . round($lat, 4) . ',' . round($lng, 4);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $this->respectRateLimit();

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    // Required by Nominatim's usage policy — requests
                    // without a real User-Agent get blocked outright.
                    'User-Agent' => 'ShaloTrack-Admin/1.0 (fleet tracking admin portal)',
                ])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat'    => $lat,
                    'lon'    => $lng,
                    'format' => 'jsonv2',
                    'zoom'   => 18,
                ]);

            if ($response->successful()) {
                $address = $response->json('display_name');
                if ($address) {
                    Cache::forever($cacheKey, $address);
                    return $address;
                }
            } else {
                Log::warning('Nominatim reverse geocode returned an error status', [
                    'status' => $response->status(),
                    'lat'    => $lat,
                    'lng'    => $lng,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Nominatim reverse geocode failed', [
                'lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage(),
            ]);
        }

        return "{$lat}, {$lng}";
    }

    /**
     * Blocks just long enough to keep consecutive real (cache-miss) lookups
     * at or under 1 per second, per Nominatim's policy. Tracked as a static
     * timestamp so it holds across multiple resolve() calls within the same
     * request — e.g. 10 uncached trips means 20 lookups, and this will
     * genuinely make that request take ~20 seconds. That's the real cost of
     * "free," not a bug in this method.
     */
    private function respectRateLimit(): void
    {
        if (self::$lastRequestAt !== null) {
            $elapsed = microtime(true) - self::$lastRequestAt;
            $minInterval = 1.05; // small safety margin over the 1 req/sec cap

            if ($elapsed < $minInterval) {
                usleep((int) (($minInterval - $elapsed) * 1_000_000));
            }
        }

        self::$lastRequestAt = microtime(true);
    }
}