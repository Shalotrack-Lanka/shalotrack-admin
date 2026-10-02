<?php

namespace App\Services;

use App\Models\Dealer;
use App\Models\DealerCommissionLedger;
use App\Models\DealerCommissionRate;
use App\Models\SetupShalotrackDevice;
use Illuminate\Support\Facades\DB;

/**
 * =====================================================================
 *  ⚠️  NOT PRODUCTION READY -- DO NOT DEPLOY UNTIL THIS BANNER IS REMOVED
 * =====================================================================
 * Commission is a real money calculation shown to dealers, and this
 * class is deliberately incomplete pending two client-dependent
 * decisions. Do not treat the numbers this produces as final, and do
 * not let this ship to production until both are resolved:
 *
 *   1. PAYMENT GATING (not implemented): commission is recorded the
 *      moment a device is assigned to a customer, with no check for
 *      whether the dealer/customer has actually paid for it. There is
 *      currently no "paid" concept anywhere in the dealer sale flow to
 *      gate on. Pending a client conversation to define what "paid"
 *      means here (see resolveRate()/recordEarned() below for where the
 *      gate will go once defined).
 *   2. HISTORICAL BACKFILL (not implemented): this ledger only records
 *      commission for devices assigned AFTER this system went live.
 *      Any device a dealer sold before this existed shows LKR 0 in
 *      their commission total until someone decides whether/how to
 *      backfill the ledger for pre-existing assignments. Not decided
 *      yet -- do not guess a backfill script into existence.
 *
 * Owns all dealer commission math. Nothing else in the app should compute
 * or total commission directly -- go through here so the rate-resolution
 * and ledger rules only exist in one place.
 */
class DealerCommissionService
{
    // Used only when NO rate has been configured at all yet -- lets the
    // system keep working on day one before an admin sets up the rate
    // table, without silently pretending 1000 is a real configured value.
    private const FALLBACK_RATE = 1000.00;

    /**
     * Resolve the commission rate for a dealer/device-type combination,
     * most specific match first:
     *   1. exact tier + exact device type
     *   2. tier, any device type
     *   3. any tier, exact device type
     *   4. network-wide default (dealer_status NULL, device_type_id NULL)
     *   5. hardcoded fallback, if nothing has been configured at all
     *
     * Returns ['rate' => float, 'source' => string] -- 'source' is shown in
     * the admin rate-config UI so it's never ambiguous whether a number on
     * screen is a configured rate or a fallback nobody has set up yet.
     */
    public function resolveRate(?string $dealerStatus, ?int $deviceTypeId): array
    {
        $match = DealerCommissionRate::where('dealer_status', $dealerStatus)
            ->where('device_type_id', $deviceTypeId)
            ->first();

        if ($match) {
            return ['rate' => (float) $match->rate, 'source' => 'exact'];
        }

        if ($dealerStatus !== null) {
            $tierDefault = DealerCommissionRate::where('dealer_status', $dealerStatus)
                ->whereNull('device_type_id')
                ->first();

            if ($tierDefault) {
                return ['rate' => (float) $tierDefault->rate, 'source' => 'tier_default'];
            }
        }

        if ($deviceTypeId !== null) {
            $typeDefault = DealerCommissionRate::whereNull('dealer_status')
                ->where('device_type_id', $deviceTypeId)
                ->first();

            if ($typeDefault) {
                return ['rate' => (float) $typeDefault->rate, 'source' => 'device_type_default'];
            }
        }

        $networkDefault = DealerCommissionRate::whereNull('dealer_status')
            ->whereNull('device_type_id')
            ->first();

        if ($networkDefault) {
            return ['rate' => (float) $networkDefault->rate, 'source' => 'network_default'];
        }

        return ['rate' => self::FALLBACK_RATE, 'source' => 'unconfigured_fallback'];
    }

    /**
     * Records a device as sold, IF it isn't already actively earning
     * commission (idempotent -- safe to call from every "assign device to
     * customer" code path without checking first).
     */
    /**
     * Retired: the flat per-device sale rate was replaced by the package margins in the Renewal Plans
     * guideline (see PackagePayments). Existing ledger rows stay valid and are still paid and clawed back
     * by the statements; no new ones are created. Flip to true only to restore the old behaviour.
     */
    public const FIXED_SALE_COMMISSION_ENABLED = false;

    public function recordEarned(SetupShalotrackDevice $device, Dealer $dealer, ?string $reason = null): ?DealerCommissionLedger
    {
        if (! self::FIXED_SALE_COMMISSION_ENABLED) {
            return null;
        }

        return DB::transaction(function () use ($device, $dealer, $reason) {
            $alreadyActive = DealerCommissionLedger::where('shdevice_id', $device->shdevice_id)
                ->where('entry_type', 'earned')
                ->whereNull('reversed_at')
                ->lockForUpdate()
                ->exists();

            if ($alreadyActive) {
                return null;
            }

            ['rate' => $rate] = $this->resolveRate($dealer->dealer_status, $device->device_type_id);

            return DealerCommissionLedger::create([
                'dealer_id'             => $dealer->id,
                'shdevice_id'           => $device->shdevice_id,
                'device_type_id'        => $device->device_type_id,
                'dealer_status_at_time' => $dealer->dealer_status,
                'rate_applied'          => $rate,
                'amount'                => $rate,
                'entry_type'            => 'earned',
                'reason'                => $reason,
            ]);
        });
    }

    /**
     * Reverses the currently-active earned entry for a device, if one
     * exists. Safe to call defensively (e.g. on every "mark broken" or
     * "unassign" action) even when the device never actually had an active
     * earned entry -- it's a no-op in that case rather than an error.
     */
    public function recordReversed(SetupShalotrackDevice $device, string $reason): ?DealerCommissionLedger
    {
        return DB::transaction(function () use ($device, $reason) {
            $active = DealerCommissionLedger::where('shdevice_id', $device->shdevice_id)
                ->where('entry_type', 'earned')
                ->whereNull('reversed_at')
                ->lockForUpdate()
                ->latest()
                ->first();

            if (!$active) {
                return null;
            }

            $active->reversed_at = now();
            $active->save();

            return DealerCommissionLedger::create([
                'dealer_id'             => $active->dealer_id,
                'shdevice_id'           => $active->shdevice_id,
                'device_type_id'        => $active->device_type_id,
                'dealer_status_at_time' => $active->dealer_status_at_time,
                'rate_applied'          => $active->rate_applied,
                'amount'                => -$active->amount,
                'entry_type'            => 'reversed',
                'reason'                => $reason,
            ]);
        });
    }

    public function currentTotal(Dealer $dealer): float
    {
        return (float) DealerCommissionLedger::where('dealer_id', $dealer->id)->sum('amount');
    }
}