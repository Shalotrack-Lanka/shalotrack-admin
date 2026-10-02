<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Warranty rules from the Renewal Plans guideline: expiry = ORIGINAL activation date + the package's
 * warranty months. Subscription validity is a separate value and never feeds into this.
 */
class DeviceWarranty
{
    /** @return array{state: string, label: string, date: Carbon|null, days: int|null} state: active|ended|none|unknown */
    public static function status(object $row): array
    {
        $months = (int) ($row->warranty_months ?? 0);
        if ($months <= 0) {
            return ['state' => 'none', 'label' => 'No added warranty', 'date' => null, 'days' => null];
        }
        if (empty($row->original_activated_at)) {
            return ['state' => 'unknown', 'label' => 'Activation date missing', 'date' => null, 'days' => null];
        }

        $end = self::expiry($row->original_activated_at, $months);
        $days = RenewalsReport::daysLeft($end);

        return $days !== null && $days < 0
            ? ['state' => 'ended', 'label' => 'Expired', 'date' => $end, 'days' => $days]
            : ['state' => 'active', 'label' => 'Active', 'date' => $end, 'days' => $days];
    }

    /** Calendar months, clamped to month end (31 Aug + 6 months = 28 Feb), computed on the Sri Lanka day. */
    public static function expiry($originalActivatedAt, int $months): Carbon
    {
        return Carbon::parse($originalActivatedAt)->local()->startOfDay()->addMonthsNoOverflow($months);
    }
}