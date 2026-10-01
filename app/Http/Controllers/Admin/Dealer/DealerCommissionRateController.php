<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Services\Audit;
use App\Http\Controllers\Controller;
use App\Models\DealerCommissionRate;
use App\Models\DeviceType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ⚠️ NOT PRODUCTION READY: rates configured here feed a commission ledger
 * (DealerCommissionService) that has no payment-status gate and no
 * historical backfill yet -- both pending a client conversation. See that
 * class's docblock. Rate CRUD itself is fine; the ledger it feeds is not
 * deploy-ready until that banner is removed.
 */
class DealerCommissionRateController extends Controller
{
    // Same tier list DealerManagementController/DealerProfileController
    // validate dealer_status against -- kept in sync manually since there's
    // no shared enum/constant class in this codebase to pull from.
    private const DEALER_TIERS = [
        'lbc', 'distributor', 'retailer', 'dsa', 'ba', 'csa', 'lt_point', 'Authorized Dealer'
    ];

    public function index()
    {
        $rates = DealerCommissionRate::with('deviceType')
            ->orderByRaw('dealer_status IS NULL, dealer_status')
            ->orderByRaw('device_type_id IS NULL, device_type_id')
            ->get();

        $deviceTypes = DeviceType::orderBy('device_category')->get();

        return view('admin.dealer.commission_rates', [
            'rates'       => $rates,
            'deviceTypes' => $deviceTypes,
            'tiers'       => self::DEALER_TIERS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'dealer_status'   => ['nullable', Rule::in(self::DEALER_TIERS)],
            'device_type_id'  => ['nullable', 'exists:device_types,id'],
            'rate'            => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ]);

        // Nullable columns mean the DB unique constraint doesn't actually
        // stop two NULL/NULL (or two tier=X/NULL) rows from coexisting --
        // standard SQL treats every NULL as distinct from every other NULL
        // for uniqueness purposes. Enforced here instead: update in place
        // if this exact combo already has a rate, rather than ever creating
        // a silently-competing duplicate.
        $existing = DealerCommissionRate::where('dealer_status', $validated['dealer_status'] ?? null)
            ->where('device_type_id', $validated['device_type_id'] ?? null)
            ->first();

        $scope = ($validated['dealer_status'] ?? 'any tier') . ' / device type ' . ($validated['device_type_id'] ?? 'any');

        if ($existing) {
            $oldRate = $existing->rate;
            $existing->update(['rate' => $validated['rate']]);
            Audit::record('commission.rate_saved', 'rate', $existing->id, $scope, ['rate' => ['from' => (string) $oldRate, 'to' => (string) $validated['rate']]]);
            return back()->with('success', 'Commission rate updated.');
        }

        $created = DealerCommissionRate::create($validated);
        Audit::record('commission.rate_saved', 'rate', $created->id, $scope, ['rate' => ['from' => null, 'to' => (string) $validated['rate']]]);

        return back()->with('success', 'Commission rate added.');
    }

    public function destroy(DealerCommissionRate $rate)
    {
        $scope = ($rate->dealer_status ?? 'any tier') . ' / device type ' . ($rate->device_type_id ?? 'any');
        $oldRate = (string) $rate->rate;
        $rateId = $rate->id;
        $rate->delete();
        Audit::record('commission.rate_deleted', 'rate', $rateId, $scope, ['rate' => ['from' => $oldRate, 'to' => null]]);

        return back()->with('success', 'Commission rate removed. Matching sales will now fall through to the next most specific rate (or the unconfigured fallback).');
    }
}