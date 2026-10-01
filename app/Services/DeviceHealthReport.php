<?php

namespace App\Services;

use App\Models\ActivatedDevice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Device health worklist: paid devices that are not behaving, so staff (or the dealer who sold it)
 * can call the customer before the customer calls us.
 *
 * Source of truth is the API (the gateway writes device status into the API database). The admin
 * database supplies who the device belongs to and whether the subscription is paid.
 *
 * Why silence, not "online": the gateway only clears IsOnline on a clean disconnect, so a gateway
 * crash would leave every device "online" forever. How long since we last heard from the device
 * cannot be faked by a crash.
 *
 * Only PAID devices are listed. A lapsed customer is a renewals problem (see RenewalsReport), not a fault.
 *
 * One problem per device, most serious first:
 *   not_linked       paid and bound here, but the API has no active vehicle assignment for it
 *   never_connected  bound, never reported (installation problem)
 *   silent           no contact for WARN_HOURS (critical at CRITICAL_HOURS, or at once if its last
 *                    report said external power was disconnected: possible cut wire)
 *   unplugged        still reporting, but running on its internal battery
 *
 * If the API cannot be reached the report says so ("available" = false). It never returns an
 * empty list that would read as "all devices healthy".
 */
class DeviceHealthReport
{
    public const WARN_HOURS = 24;
    public const CRITICAL_HOURS = 72;
    /** Fresh installs and just-synced bindings get this long before they count as a problem. */
    public const LINK_GRACE_HOURS = 1;
    /** GT06 voltage level is 0-6; the gateway's own LOW_BATTERY_THRESHOLD is 2. */
    public const LOW_BATTERY_LEVEL = 2;

    public const CACHE_KEY = 'device_health_fleet';
    public const CACHE_SECONDS = 120;

    public const TYPE_NOT_LINKED = 'not_linked';
    public const TYPE_NEVER = 'never_connected';
    public const TYPE_SILENT = 'silent';
    public const TYPE_UNPLUGGED = 'unplugged';

    public const TYPES = [self::TYPE_NOT_LINKED, self::TYPE_NEVER, self::TYPE_SILENT, self::TYPE_UNPLUGGED];

    public const TYPE_LABELS = [
        self::TYPE_NOT_LINKED => 'Not linked in app',
        self::TYPE_NEVER      => 'Never connected',
        self::TYPE_SILENT     => 'Gone silent',
        self::TYPE_UNPLUGGED  => 'Unplugged',
    ];

    /**
     * @return array{available: bool, rows: array<int, array<string, mixed>>, counts: array<string,int>}
     */
    public function build(?int $dealerId, bool $failClosed = false, ?Carbon $now = null): array
    {
        $now = $now ?: now();
        $empty = ['critical' => 0, 'warning' => 0, 'total' => 0];

        if ($failClosed) {
            return ['available' => true, 'rows' => [], 'counts' => $empty];
        }

        $fleet = $this->fetchFleet();
        if ($fleet === null) {
            return ['available' => false, 'rows' => [], 'counts' => $empty];
        }

        $devices = ActivatedDevice::query()
            ->leftJoin('setup_shalotrack_devices as sd', 'sd.imei_number', '=', 'activated_devices.imei_number')
            ->leftJoin('dealers as d', 'd.id', '=', 'sd.dealer_id')
            ->leftJoin('Customer-ad as c', 'c.customer_id', '=', 'activated_devices.customer_id')
            ->whereNotNull('activated_devices.imei_number')
            ->where('activated_devices.payment_status', 'Paid')
            ->when($dealerId !== null, fn ($q) => $q->where('sd.dealer_id', $dealerId))
            ->get([
                'activated_devices.imei_number',
                'activated_devices.customer_name',
                'activated_devices.vehicle_number',
                'activated_devices.created_at as bound_at',
                'sd.dealer_id as dealer_id',
                'd.full_name as dealer_name',
                'c.phone_number as customer_phone',
            ]);

        $rows = [];
        foreach ($devices as $device) {
            $problem = self::classify(
                $fleet[$device->imei_number] ?? null,
                $device->bound_at ? Carbon::parse($device->bound_at, 'UTC') : $now,
                $now
            );

            if ($problem === null) {
                continue;
            }

            $rows[] = $problem + [
                'imei'           => $device->imei_number,
                'customer_name'  => $device->customer_name,
                'customer_phone' => $device->customer_phone,
                'vehicle_number' => $device->vehicle_number,
                'dealer_name'    => $device->dealer_name,
            ];
        }

        // Critical first, then the longest-silent first, then by customer so the order is stable.
        usort($rows, fn ($a, $b) =>
            [$a['level'] === 'critical' ? 0 : 1, -$a['hours'], (string) $a['customer_name']]
            <=> [$b['level'] === 'critical' ? 0 : 1, -$b['hours'], (string) $b['customer_name']]);

        $critical = count(array_filter($rows, fn ($r) => $r['level'] === 'critical'));

        return [
            'available' => true,
            'rows'      => $rows,
            'counts'    => ['critical' => $critical, 'warning' => count($rows) - $critical, 'total' => count($rows)],
        ];
    }

    /**
     * Decides whether one paid device has a problem. Pure function (no I/O) so every rule is testable.
     *
     * @param array{lastContactUtc?:?string, power?:?string, batteryLevel?:?int}|null $health  null = the API does not list this device
     * @return array{type:string, level:string, hours:float, last_contact:?Carbon, detail:string}|null
     */
    public static function classify(?array $health, Carbon $boundAt, Carbon $now): ?array
    {
        $sinceBound = max(0.0, $boundAt->floatDiffInHours($now));

        if ($health === null) {
            if ($sinceBound < self::LINK_GRACE_HOURS) {
                return null;
            }

            return [
                'type' => self::TYPE_NOT_LINKED,
                'level' => $sinceBound >= self::WARN_HOURS ? 'critical' : 'warning',
                'hours' => $sinceBound,
                'last_contact' => null,
                'detail' => 'The app has no active vehicle for this device (check the binding and the sync).',
            ];
        }

        $last = self::utc($health['lastContactUtc'] ?? null);
        $power = $health['power'] ?? null;
        $battery = $health['batteryLevel'] ?? null;

        if ($last === null) {
            if ($sinceBound < self::WARN_HOURS) {
                return null;
            }

            return [
                'type' => self::TYPE_NEVER,
                'level' => $sinceBound >= self::CRITICAL_HOURS ? 'critical' : 'warning',
                'hours' => $sinceBound,
                'last_contact' => null,
                'detail' => 'Bound ' . self::duration($sinceBound) . ' ago and has never reported. Check the install, SIM and power.',
            ];
        }

        $silent = max(0.0, $last->floatDiffInHours($now));

        if ($silent >= self::WARN_HOURS) {
            $unpluggedBefore = $power === 'Disconnected';
            $detail = 'No signal for ' . self::duration($silent) . '.';
            if ($unpluggedBefore) {
                $detail .= ' Its last report said external power was disconnected (possible cut wire).';
            }

            return [
                'type' => self::TYPE_SILENT,
                'level' => ($silent >= self::CRITICAL_HOURS || $unpluggedBefore) ? 'critical' : 'warning',
                'hours' => $silent,
                'last_contact' => $last,
                'detail' => $detail,
            ];
        }

        if ($power === 'Disconnected') {
            $detail = 'Running on its internal battery';
            if ($battery !== null) {
                $detail .= ' (level ' . $battery . ' of 6' . ($battery <= self::LOW_BATTERY_LEVEL ? ', low' : '') . ')';
            }

            return [
                'type' => self::TYPE_UNPLUGGED,
                'level' => 'critical',
                'hours' => $silent,
                'last_contact' => $last,
                'detail' => $detail . '. It will go silent when the battery runs out.',
            ];
        }

        return null;
    }

    /** "3h", "1d 4h". */
    public static function duration(float $hours): string
    {
        $h = (int) floor($hours);

        return $h < 24 ? $h . 'h' : intdiv($h, 24) . 'd ' . ($h % 24) . 'h';
    }

    /**
     * Fleet health keyed by IMEI, or null when the API cannot be reached / answers badly.
     * Cached briefly; a failure is never cached.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function fetchFleet(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(15)
                ->withHeaders(['X-Admin-Sync-Key' => (string) config('services.shalotrack_api.sync_key')])
                ->get(rtrim((string) config('services.shalotrack_api.base_url'), '/') . '/api/internal/devices/health');
        } catch (\Throwable $e) {
            Log::warning('Device health: API unreachable: ' . $e->getMessage());

            return null;
        }

        $data = $response->json('data');
        if (! $response->successful() || ! is_array($data)) {
            Log::warning('Device health: API returned HTTP ' . $response->status());

            return null;
        }

        $fleet = [];
        foreach ($data as $row) {
            if (empty($row['imei'])) {
                continue;
            }
            $fleet[(string) $row['imei']] = [
                'vehicleNumber' => $row['vehicleNumber'] ?? null,
                'lastContactUtc' => $row['lastContactUtc'] ?? null,
                'power' => $row['power'] ?? null,
                'batteryLevel' => isset($row['batteryLevel']) ? (int) $row['batteryLevel'] : null,
            ];
        }

        Cache::put(self::CACHE_KEY, $fleet, self::CACHE_SECONDS);

        return $fleet;
    }

    private static function utc(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}