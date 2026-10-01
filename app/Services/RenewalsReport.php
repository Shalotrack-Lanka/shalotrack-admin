<?php

namespace App\Services;

use App\Models\ActivatedDevice;
use App\Models\ExpiredDevice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who needs to renew, and by when. One query for staff (all devices) and for a dealer (only
 * devices that dealer sold), so the two screens can never disagree.
 *
 * How the data looks (see ExpireDeviceSubscriptions): a paid subscription has payment_status
 * 'Paid' and an end date. When it lapses the row is kept, payment_status becomes 'not-Paid' and
 * the dates are cleared, with a record in expired_devices. So:
 *   - "due soon"  = Paid and ending within the next N days
 *   - "overdue"   = anything not Paid (lapsed, or never paid yet)
 *
 * The dealer is the one on the physical device (setup_shalotrack_devices.dealer_id), the same
 * link the dealer dashboard uses for "assigned devices".
 */
class RenewalsReport
{
    public const SCOPE_DUE = 'due';
    public const SCOPE_OVERDUE = 'overdue';

    public const WINDOWS = [7, 14, 30, 60];

    /**
     * @param int|null $dealerId  null = staff (everything). A dealer id restricts to that dealer's devices.
     *                            Pass $failClosed = true for a dealer with no dealer record: returns nothing.
     */
    public function query(string $scope, int $days, ?int $dealerId, bool $failClosed = false): Builder
    {
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;

        $q = ActivatedDevice::query()
            ->leftJoin('setup_shalotrack_devices as sd', 'sd.imei_number', '=', 'activated_devices.imei_number')
            ->leftJoin('dealers as d', 'd.id', '=', 'sd.dealer_id')
            ->leftJoin('Customer-ad as c', 'c.customer_id', '=', 'activated_devices.customer_id')
            ->whereNotNull('activated_devices.imei_number')
            ->select([
                'activated_devices.*',
                'sd.dealer_id as dealer_id',
                'd.full_name as dealer_name',
                'c.phone_number as customer_phone',
            ]);

        if ($failClosed) {
            return $q->whereRaw('1 = 0');
        }

        if ($dealerId !== null) {
            $q->where('sd.dealer_id', $dealerId);
        }

        if ($scope === self::SCOPE_OVERDUE) {
            $q->where(function ($w) {
                $w->whereNull('activated_devices.payment_status')
                    ->orWhere('activated_devices.payment_status', '!=', 'Paid');
            });
            $q->selectSub(
                ExpiredDevice::query()
                    ->selectRaw('max(expired_date)')
                    ->whereColumn('expired_devices.imei_number', 'activated_devices.imei_number'),
                'ended_on'
            );

            return $q->orderByDesc('ended_on')->orderBy('activated_devices.customer_name');
        }

        return $q->where('activated_devices.payment_status', 'Paid')
            ->whereNotNull('activated_devices.subscription_end_date')
            ->where('activated_devices.subscription_end_date', '>=', now())
            ->where('activated_devices.subscription_end_date', '<=', now()->addDays($days))
            ->orderBy('activated_devices.subscription_end_date');
    }

    /** Whole local calendar days from today until $end (negative once past). */
    public static function daysLeft($end): ?int
    {
        if (! $end) {
            return null;
        }

        return (int) now()->local()->startOfDay()->diffInDays($end->copy()->local()->startOfDay(), false);
    }

    /** Stops spreadsheet apps from running a customer-typed "=cmd" as a formula in the CSV export. */
    public static function csvSafe(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }
}