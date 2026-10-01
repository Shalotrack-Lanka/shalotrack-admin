<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the API's /api/internal/renewals routes (customer renewal requests + bank slips).
 * Every method fails soft: an unreachable API gives null / a failure result, never an empty list
 * that would read as "no requests".
 */
class RenewalRequestsApi
{
    public const STATUSES = ['PendingReview', 'AwaitingSlip', 'Approved', 'Rejected', 'Cancelled', 'all'];

    /** @return array<int, array<string, mixed>>|null  null = API unavailable */
    public function list(string $status): ?array
    {
        if (! in_array($status, self::STATUSES, true)) {
            $status = 'PendingReview';
        }

        try {
            $response = $this->http(15)->get($this->url('/api/internal/renewals'), ['status' => $status]);
        } catch (\Throwable $e) {
            Log::warning('Renewal requests: API unreachable: ' . $e->getMessage());

            return null;
        }

        $data = $response->json('data');
        if (! $response->successful() || ! is_array($data)) {
            Log::warning('Renewal requests: API returned HTTP ' . $response->status());

            return null;
        }

        return array_values(array_filter($data, 'is_array'));
    }

    /** @return array{body: string, type: string}|null */
    public function slip(string $id): ?array
    {
        try {
            $response = $this->http(20)->get($this->url("/api/internal/renewals/{$id}/slip"));
        } catch (\Throwable $e) {
            Log::warning('Renewal slip: API unreachable: ' . $e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // Never pass the API's content type through blindly: only the three types we accept.
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if (! in_array($type, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            return null;
        }

        return ['body' => $response->body(), 'type' => $type];
    }

    /** @return array{ok: bool, message: string} */
    public function decide(string $id, array $payload): array
    {
        try {
            $response = $this->http(15)->acceptJson()->post($this->url("/api/internal/renewals/{$id}/decision"), $payload);
        } catch (\Throwable $e) {
            Log::warning('Renewal decision: API unreachable: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'The mobile API could not be reached. Nothing was changed. Try again in a minute.'];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => (string) $response->json('message', 'Done.')];
        }

        $detail = $response->json('errors.0') ?: $response->json('message') ?: 'The API refused the request.';

        return ['ok' => false, 'message' => (string) $detail];
    }

    private function http(int $timeout)
    {
        return Http::timeout($timeout)->withHeaders(['X-Admin-Sync-Key' => (string) config('services.shalotrack_api.sync_key')]);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.shalotrack_api.base_url'), '/') . $path;
    }
}