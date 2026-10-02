<?php

namespace App\Services;

use App\Models\RenewalPackage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pushes the renewal price list to the mobile API, so the app shows the same prices the admin set.
 * The admin database is the single source of truth. Only what customers may see is sent: price,
 * warranty, label. Company, distributor and retailer margins NEVER leave the admin portal.
 *
 * The call replaces the API's copy with the full current list, so it is safe to repeat.
 */
class RenewalPackagesApi
{
    /** @return array{ok: bool, message: string} */
    public function push(): array
    {
        $packages = RenewalPackage::orderBy('sort_order')->get()->map(fn (RenewalPackage $p) => [
            'code'           => $p->code,
            'label'          => $p->label,
            'positioning'    => $p->positioning,
            'months'         => (int) $p->months,
            'customerPrice'  => $p->customer_price !== null ? (float) $p->customer_price : null,
            'warrantyMonths' => (int) $p->warranty_months,
            'isActive'       => (bool) $p->is_active,
            'sortOrder'      => (int) $p->sort_order,
        ])->values()->all();

        if ($packages === []) {
            return ['ok' => false, 'message' => 'There are no packages to send.'];
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Admin-Sync-Key' => (string) config('services.shalotrack_api.sync_key')])
                ->acceptJson()
                ->put(rtrim((string) config('services.shalotrack_api.base_url'), '/') . '/api/internal/renewal-packages', ['packages' => $packages]);
        } catch (\Throwable $e) {
            Log::warning('Renewal packages push: API unreachable: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'The mobile API could not be reached.'];
        }

        if ($response->successful()) {
            return ['ok' => true, 'message' => 'The mobile app price list is up to date.'];
        }

        $detail = $response->json('errors.0') ?: $response->json('message') ?: 'HTTP ' . $response->status();
        Log::warning('Renewal packages push refused: ' . $detail);

        return ['ok' => false, 'message' => 'The mobile API refused the price list: ' . $detail];
    }
}