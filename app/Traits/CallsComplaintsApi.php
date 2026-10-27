<?php

namespace App\Traits;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * One place for every call to the mobile API's internal complaints endpoints.
 *
 * Complaints live in the mobile API's database, not here. When that API is down or slow the portal used to
 * crash with a 500 page (a connection error is an exception, not a failed response). Now an unreachable API
 * gives null, and every caller treats null exactly like a failed response: a friendly message, never a crash.
 */
trait CallsComplaintsApi
{
    /**
     * @param 'get'|'post' $method
     * @param string $path path after the API base url, for example "/api/internal/complaints/for-admin"
     * @return Response|null null when the API could not be reached at all
     */
    protected function complaintsApi(string $method, string $path, array $data = [], int $timeout = 10): ?Response
    {
        try {
            $request = Http::timeout($timeout)
                ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')]);

            $url = config('services.shalotrack_api.base_url') . $path;

            return $method === 'get' ? $request->get($url) : $request->post($url, $data);
        } catch (\Throwable $e) {
            Log::warning('Complaints API unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }
}