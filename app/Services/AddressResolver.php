<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AddressResolver
{
    public function resolve(float $lat, float $lng): string
    {
        $cacheKey = 'google_geocode:' . round($lat, 5) . ',' . round($lng, 5);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {

            $response = Http::timeout(10)->get(
                'https://maps.googleapis.com/maps/api/geocode/json',
                [
                    'latlng' => $lat . ',' . $lng,
                    'key'    => env('GOOGLE_MAPS_API_KEY'),
                ]
            );

            if ($response->successful()) {

                $json = $response->json();

                if (
                    isset($json['status']) &&
                    $json['status'] === 'OK' &&
                    !empty($json['results'])
                ) {

                    $address = $json['results'][0]['formatted_address'];

                    Cache::forever($cacheKey, $address);

                    return $address;
                }

                Log::warning('Google Geocoding API returned no address.', [
                    'response' => $json,
                ]);
            } else {

                Log::warning('Google Geocoding HTTP Error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }

        } catch (\Throwable $e) {

            Log::error('Google Geocoding Exception', [
                'error' => $e->getMessage(),
            ]);

        }

        return "{$lat}, {$lng}";
    }
}