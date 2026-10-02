<?php

namespace App\Services;

use App\Models\ActivatedDevice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Installs and warranty, one row per bound device.
 *
 * Warranty follows the Renewal Plans guideline: it ends at the device's ORIGINAL activation date plus
 * the best warranty its packages have earned (activated_devices.warranty_months). It is independent of
 * the subscription: renewing never moves it, and an expired subscription does not end it. Rows where
 * warranty_months is 0 have no added warranty (for example a 6 Months package).
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
        self::FILTER_ENDED   => 'Warranty expired',
        self::FILTER_NONE    => 'No added warranty',
        self::FILTER_MISSING => 'Missing install details',
    ];

    public const WINDOWS = [30, 60, 90];

    public function query(string $filter, int $days): Builder
    {
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;
        $today = now()->local()->toDateString();
        $until = now()->local()->addDays($days)->toDateString();
        $end = $this->warrantyEndSql();

        $q = ActivatedDevice::query()
            ->leftJoin('setup_shalotrack_devices as sd', 'sd.imei_number', '=', 'activated_devices.imei_number')
            ->leftJoin('dealers as d', 'd.id', '=', 'sd.dealer_id')
            ->whereNotNull('activated_devices.imei_number')
            ->select(['activated_devices.*', 'd.full_name as dealer_name']);

        $hasWarranty = fn ($w) => $w->where('activated_devices.warranty_months', '>', 0)
            ->whereNotNull('activated_devices.original_activated_at');

        switch ($filter) {
            case self::FILTER_ENDING:
                return $q->where($hasWarranty)->whereRaw("$end >= ?", [$today])->whereRaw("$end <= ?", [$until])
                    ->orderByRaw("$end asc");

            case self::FILTER_ENDED:
                return $q->where($hasWarranty)->whereRaw("$end < ?", [$today])
                    ->orderByRaw("$end desc");

            case self::FILTER_NONE:
                return $q->where('activated_devices.warranty_months', 0)
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
     * Warranty end as a Sri Lanka calendar date, in SQL so the filters and counts need no PHP loop.
     * Postgres clamps to month end like Carbon's addMonthsNoOverflow; SQLite (tests) is close enough.
     */
    private function warrantyEndSql(): string
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return "(((activated_devices.original_activated_at AT TIME ZONE 'UTC') AT TIME ZONE 'Asia/Colombo')::date"
                . " + (activated_devices.warranty_months * interval '1 month'))::date";
        }

        return "date(datetime(activated_devices.original_activated_at, '+5 hours', '+30 minutes'),"
            . " '+' || activated_devices.warranty_months || ' months')";
    }

    /** @return array{state: string, label: string, date: \Illuminate\Support\Carbon|null, days: int|null} */
    public static function warranty(object $row): array
    {
        return DeviceWarranty::status($row);
    }
}