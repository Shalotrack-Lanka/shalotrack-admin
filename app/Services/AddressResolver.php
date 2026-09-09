<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AddressResolver
{
    public function resolve(float $lat, float $lng): string
    {
        $cacheKey = 'geocode:' . round($lat, 5) . ',' . round($lng, 5);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            // 1. Google Maps API එක Try කිරීම
            $googleKey = config('services.google.maps_api_key') ?? env('GOOGLE_MAPS_API_KEY');

            if (!empty($googleKey)) {
                $response = Http::timeout(5)->get('https://maps.googleapis.com/maps/api/geocode/json', [
                    'latlng' => $lat . ',' . $lng,
                    'key'    => $googleKey,
                ]);

                if ($response->successful()) {
                    $json = $response->json();
                    if (isset($json['status']) && $json['status'] === 'OK' && !empty($json['results'])) {
                        $address = $json['results'][0]['formatted_address'];
                        Cache::forever($cacheKey, $address);
                        return $address;
                    }
                }
            }

            // 2. Google API වැඩ නොකරන්නේ නම් OpenStreetMap (Free Fallback) මගින් Address එක ගැනීම
            $osmResponse = Http::timeout(5)
                ->withHeaders(['User-Agent' => 'ShaloTrackAdmin/1.0'])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat'    => $lat,
                    'lon'    => $lng,
                    'format' => 'json',
                ]);

            if ($osmResponse->successful()) {
                $osmJson = $osmResponse->json();
                if (!empty($osmJson['display_name'])) {
                    $address = $osmJson['display_name'];
                    Cache::forever($cacheKey, $address);
                    return $address;
                }
            }

        } catch (\Throwable $e) {
            Log::error('Geocoding Exception: ' . $e->getMessage());
        }

        return "{$lat}, {$lng}";
    }
}