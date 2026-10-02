<?php

namespace App\Services;

use App\Models\ActivatedDevice;
use App\Models\ExpiredDevice;
use Illuminate\Database\Eloquent\Builder;

/**
 * Installs and warranty, one row per bound device. Warranty is the subscription period (a founder
 * decision, 2026-09-30), so there is no warranty column: it is read from the subscription.
 *
 * How the data looks (see ExpireDeviceSubscriptions): a paid device has payment_status 'Paid' and an
 * end date. When it lapses the end date is cleared and the lapse date is kept in expired_devices.
 * So a warranty is:
 *   - active  = Paid, end date in the future
 *   - ended   = not Paid, with an expired_devices record (that record's date is when it ended)
 *   - none    = not Paid and never paid (a new device awaiting its first payment)
 */
class InstallsWarrantyReport
{
    public const FILTER_ALL = 'all';
    public const FILTER_ENDING = 'ending';
    public const FILTER_ENDED = 'ended';
    public const FILTER_NONE = 'none';
    public const FILTER_MISSING = 'missing';

    public const FILTERS = [
        self::FILTER_ALL     => 'All installs',
        self::FILTER_ENDING  => 'Warranty ending soon',
        self::FILTER_ENDED   => 'Warranty ended',
        self::FILTER_NONE    => 'Never paid',
        self::FILTER_MISSING => 'Missing install details',
    ];

    public const WINDOWS = [30, 60, 90];

    public function query(string $filter, int $days): Builder
    {
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;

        $q = ActivatedDevice::query()
            ->leftJoin('setup_shalotrack_devices as sd', 'sd.imei_number', '=', 'activated_devices.imei_number')
            ->leftJoin('dealers as d', 'd.id', '=', 'sd.dealer_id')
            ->whereNotNull('activated_devices.imei_number')
            ->select(['activated_devices.*', 'd.full_name as dealer_name'])
            ->selectSub(
                ExpiredDevice::query()
                    ->selectRaw('max(expired_date)')
                    ->whereColumn('expired_devices.imei_number', 'activated_devices.imei_number'),
                'ended_on'
            );

        $notPaid = fn ($w) => $w->whereNull('activated_devices.payment_status')
            ->orWhere('activated_devices.payment_status', '!=', 'Paid');
        $hasLapsed = fn () => ExpiredDevice::query()
            ->whereColumn('expired_devices.imei_number', 'activated_devices.imei_number');

        switch ($filter) {
            case self::FILTER_ENDING:
                return $q->where('activated_devices.payment_status', 'Paid')
                    ->whereNotNull('activated_devices.subscription_end_date')
                    ->where('activated_devices.subscription_end_date', '>=', now())
                    ->where('activated_devices.subscription_end_date', '<=', now()->addDays($days))
                    ->orderBy('activated_devices.subscription_end_date');

            case self::FILTER_ENDED:
                return $q->where($notPaid)->whereExists($hasLapsed())
                    ->orderByDesc('ended_on');

            case self::FILTER_NONE:
                return $q->where($notPaid)->whereNotExists($hasLapsed())
                    ->orderByDesc('activated_devices.created_at');

            case self::FILTER_MISSING:
                return $q->whereNull('activated_devices.installed_on')
                    ->orderByDesc('activated_devices.created_at');

            default:
                return $q->orderByRaw('activated_devices.installed_on is null')
                    ->orderByDesc('activated_devices.installed_on')
                    ->orderByDesc('activated_devices.created_at');
        }
    }

    /**
     * @return array{state: string, label: string, date: \Illuminate\Support\Carbon|null, days: int|null}
     */
    public static function warranty(object $row): array
    {
        if ($row->payment_status === 'Paid' && $row->subscription_end_date) {
            $days = RenewalsReport::daysLeft($row->subscription_end_date);

            return $days !== null && $days < 0
                ? ['state' => 'ended', 'label' => 'Ended', 'date' => $row->subscription_end_date, 'days' => $days]
                : ['state' => 'active', 'label' => 'Active', 'date' => $row->subscription_end_date, 'days' => $days];
        }

        if (! empty($row->ended_on)) {
            return ['state' => 'ended', 'label' => 'Ended', 'date' => \Illuminate\Support\Carbon::parse($row->ended_on), 'days' => null];
        }

        return ['state' => 'none', 'label' => 'Not started (never paid)', 'date' => null, 'days' => null];
    }
}