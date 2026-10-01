<?php

namespace App\Services;

use App\Models\Dealer;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dealer scorecard: how each dealer is actually doing, from data we already hold.
 *
 * Deliberately NO single composite "score": nobody has agreed a weighting, and a made-up number
 * gets argued about instead of acted on. Raw, sortable metrics plus a few plain flags instead.
 * Commission is deliberately not shown (the commission service is still marked not production ready).
 *
 * Definitions (so the numbers can be defended):
 *   - sold            a device the dealer holds that staff have bound to a customer vehicle
 *                     (an activated_devices row). Counted by the bind date (activated_devices.created_at).
 *                     A replacement device that is bound as a new row counts again.
 *   - active rate     of the dealer's sold devices, the share whose subscription is currently Paid.
 *                     Includes never-paid new sales as "not active".
 *   - lapsed          subscriptions that expired inside the chosen period (expired_devices.expired_date).
 *   - in stock        Activated, held by the dealer, not yet bound (unsold).
 *   - stale stock     in stock and allocated to the dealer more than STALE_STOCK_DAYS ago. Devices with no
 *                     allocation date are counted as stock but excluded from the age figures.
 *   - awaiting bind   dealer has assigned it to a customer but staff have not bound it yet. That is a staff
 *                     backlog, not a dealer fault, so it is shown but never flagged.
 *   - faulty          status Pending Repair or Broken Device.
 *   - complaints      from the API (complaints live in the other database), by the date they were filed.
 *                     null means the API could not be reached: never shown as zero.
 */
class DealerScorecard
{
    public const WINDOWS = [30, 90, 180, 365];
    public const DEFAULT_WINDOW = 90;

    public const STALE_STOCK_DAYS = 60;
    public const LOW_ACTIVE_RATE = 70;   // percent
    public const MIN_SOLD_FOR_RATE_FLAG = 5;
    public const MIN_COMPLAINTS_FOR_ESCALATION_FLAG = 5;
    public const HIGH_ESCALATION_SHARE = 50; // percent

    public const COMPLAINT_CACHE_SECONDS = 900;

    /** Columns the table can be sorted by. */
    public const SORTS = [
        'name', 'sold_window', 'sold_total', 'active_rate', 'lapsed_window',
        'stock', 'stale_stock', 'faulty', 'complaints', 'flags',
    ];

    /**
     * @param int|null $onlyDealerId  restrict to one dealer (the dealer's own view)
     * @return array<int, array<string, mixed>>  one row per dealer, keyed by dealer id
     */
    public function build(int $days, ?int $onlyDealerId = null): array
    {
        $days = in_array($days, self::WINDOWS, true) ? $days : self::DEFAULT_WINDOW;
        $now = now();
        $since = $now->copy()->subDays($days);

        $dealers = Dealer::query()
            ->when($onlyDealerId !== null, fn ($q) => $q->where('id', $onlyDealerId))
            ->orderBy('full_name')
            ->get(['id', 'full_name', 'dealer_status', 'region', 'status']);

        if ($dealers->isEmpty()) {
            return [];
        }

        $ids = $dealers->pluck('id')->all();

        $sold = $this->soldAndActive($ids, $since);
        $lapsed = $this->lapsedInPeriod($ids, $since);
        $stock = $this->stockAndFaults($ids);
        $age = $this->stockAging($ids, $now);
        $complaints = $this->complaintStats($ids, $since);

        $rows = [];
        foreach ($dealers as $d) {
            $id = $d->id;
            $s = $sold[$id] ?? ['total' => 0, 'in_window' => 0, 'active' => 0];
            $k = $stock[$id] ?? ['allocated' => 0, 'stock' => 0, 'awaiting' => 0, 'faulty' => 0];
            $a = $age[$id] ?? ['stale' => 0, 'oldest' => null, 'avg' => null];
            $c = $complaints[$id] ?? null;

            $activeRate = $s['total'] > 0 ? round($s['active'] * 100 / $s['total'], 1) : null;
            $faultRate = $k['allocated'] > 0 ? round($k['faulty'] * 100 / $k['allocated'], 1) : null;

            $flags = [];
            if ($a['stale'] > 0) {
                $flags[] = $a['stale'] . ' device' . ($a['stale'] === 1 ? '' : 's') . ' unsold over ' . self::STALE_STOCK_DAYS . ' days';
            }
            if ($activeRate !== null && $s['total'] >= self::MIN_SOLD_FOR_RATE_FLAG && $activeRate < self::LOW_ACTIVE_RATE) {
                $flags[] = 'Active rate under ' . self::LOW_ACTIVE_RATE . '%';
            }
            if ($c !== null && $c['total'] >= self::MIN_COMPLAINTS_FOR_ESCALATION_FLAG
                && $c['escalated'] * 100 / $c['total'] >= self::HIGH_ESCALATION_SHARE) {
                $flags[] = 'Escalates ' . self::HIGH_ESCALATION_SHARE . '%+ of complaints';
            }

            $rows[$id] = [
                'id'               => $id,
                'name'             => $d->full_name ?: ('Dealer #' . $id),
                'tier'             => $d->dealer_status,
                'region'           => $d->region,
                'sold_window'      => $s['in_window'],
                'sold_total'       => $s['total'],
                'active'           => $s['active'],
                'active_rate'      => $activeRate,
                'lapsed_window'    => $lapsed[$id] ?? 0,
                'allocated'        => $k['allocated'],
                'stock'            => $k['stock'],
                'awaiting'         => $k['awaiting'],
                'stale_stock'      => $a['stale'],
                'oldest_stock_days' => $a['oldest'],
                'avg_stock_days'   => $a['avg'],
                'faulty'           => $k['faulty'],
                'fault_rate'       => $faultRate,
                // null = complaints service unreachable
                'complaints'       => $c['total'] ?? null,
                'complaints_open'  => $c['open'] ?? null,
                'complaints_escalated' => $c['escalated'] ?? null,
                'complaints_avg_hours' => $c['avg_hours'] ?? null,
                'complaints_per_100' => ($c !== null && $s['total'] > 0) ? round($c['total'] * 100 / $s['total'], 1) : null,
                'flags'            => $flags,
            ];
        }

        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows */
    public static function sort(array $rows, string $by, string $dir): array
    {
        $by = in_array($by, self::SORTS, true) ? $by : 'sold_window';
        $desc = $dir !== 'asc';

        uasort($rows, function ($a, $b) use ($by, $desc) {
            $x = $by === 'flags' ? count($a['flags']) : $a[$by];
            $y = $by === 'flags' ? count($b['flags']) : $b[$by];

            // Unknown values (null) always sort last, whichever direction.
            if ($x === null && $y === null) {
                return strcasecmp($a['name'], $b['name']);
            }
            if ($x === null) {
                return 1;
            }
            if ($y === null) {
                return -1;
            }

            $cmp = is_string($x) ? strcasecmp($x, $y) : ($x <=> $y);
            if ($cmp === 0) {
                return strcasecmp($a['name'], $b['name']);
            }

            return $desc ? -$cmp : $cmp;
        });

        return $rows;
    }

    /** @return array<int, array{total:int,in_window:int,active:int}> */
    private function soldAndActive(array $ids, Carbon $since): array
    {
        $out = [];
        $rows = DB::table('activated_devices as ad')
            ->join('setup_shalotrack_devices as sd', 'sd.imei_number', '=', 'ad.imei_number')
            ->whereIn('sd.dealer_id', $ids)
            ->groupBy('sd.dealer_id')
            ->selectRaw(
                'sd.dealer_id as dealer_id, count(*) as total, '
                . 'sum(case when ad.created_at >= ? then 1 else 0 end) as in_window, '
                . "sum(case when ad.payment_status = 'Paid' then 1 else 0 end) as active",
                [$since]
            )
            ->get();

        foreach ($rows as $r) {
            $out[(int) $r->dealer_id] = [
                'total' => (int) $r->total, 'in_window' => (int) $r->in_window, 'active' => (int) $r->active,
            ];
        }

        return $out;
    }

    /** @return array<int,int> */
    private function lapsedInPeriod(array $ids, Carbon $since): array
    {
        return DB::table('expired_devices as e')
            ->join('setup_shalotrack_devices as sd', 'sd.imei_number', '=', 'e.imei_number')
            ->whereIn('sd.dealer_id', $ids)
            ->where('e.expired_date', '>=', $since)
            ->groupBy('sd.dealer_id')
            ->selectRaw('sd.dealer_id as dealer_id, count(distinct e.imei_number) as n')
            ->pluck('n', 'dealer_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return array<int, array{allocated:int,stock:int,awaiting:int,faulty:int}> */
    private function stockAndFaults(array $ids): array
    {
        $unbound = 'not exists (select 1 from activated_devices x where x.imei_number = sd.imei_number)';

        $rows = DB::table('setup_shalotrack_devices as sd')
            ->whereIn('sd.dealer_id', $ids)
            ->groupBy('sd.dealer_id')
            ->selectRaw(
                'sd.dealer_id as dealer_id, count(*) as allocated, '
                . "sum(case when sd.status = 'Activated' and {$unbound} then 1 else 0 end) as stock, "
                . "sum(case when sd.status = 'Assigned to Customer' and {$unbound} then 1 else 0 end) as awaiting, "
                . "sum(case when sd.status in ('Pending Repair','Broken Device') then 1 else 0 end) as faulty"
            )
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->dealer_id] = [
                'allocated' => (int) $r->allocated, 'stock' => (int) $r->stock,
                'awaiting' => (int) $r->awaiting, 'faulty' => (int) $r->faulty,
            ];
        }

        return $out;
    }

    /** @return array<int, array{stale:int,oldest:?int,avg:?int}> */
    private function stockAging(array $ids, Carbon $now): array
    {
        $rows = DB::table('setup_shalotrack_devices as sd')
            ->whereIn('sd.dealer_id', $ids)
            ->where('sd.status', 'Activated')
            ->whereNotNull('sd.allocated_at')
            ->whereRaw('not exists (select 1 from activated_devices x where x.imei_number = sd.imei_number)')
            ->get(['sd.dealer_id', 'sd.allocated_at']);

        $ages = [];
        foreach ($rows as $r) {
            $days = (int) Carbon::parse($r->allocated_at)->diffInDays($now);
            $ages[(int) $r->dealer_id][] = max(0, $days);
        }

        $out = [];
        foreach ($ages as $id => $list) {
            $out[$id] = [
                'stale'  => count(array_filter($list, fn ($d) => $d > self::STALE_STOCK_DAYS)),
                'oldest' => max($list),
                'avg'    => (int) round(array_sum($list) / count($list)),
            ];
        }

        return $out;
    }

    /**
     * Complaint figures per dealer from the API. One parallel batch, each dealer cached for
     * COMPLAINT_CACHE_SECONDS. A dealer whose fetch fails is simply absent from the result (shown as
     * "unavailable"), and the failure is not cached.
     *
     * @return array<int, array{total:int,open:int,escalated:int,avg_hours:?float}>
     */
    private function complaintStats(array $ids, Carbon $since): array
    {
        $lists = [];
        $missing = [];

        foreach ($ids as $id) {
            $cached = Cache::get($this->cacheKey($id));
            if (is_array($cached)) {
                $lists[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $base = rtrim((string) config('services.shalotrack_api.base_url'), '/');
            $key = (string) config('services.shalotrack_api.sync_key');

            try {
                $responses = Http::pool(function (Pool $pool) use ($missing, $base, $key) {
                    return collect($missing)->map(
                        fn ($id) => $pool->as((string) $id)
                            ->timeout(8)
                            ->withHeaders(['X-Admin-Sync-Key' => $key])
                            ->get("{$base}/api/internal/complaints/by-dealer/{$id}")
                    )->all();
                });
            } catch (\Throwable $e) {
                Log::warning('Dealer scorecard: complaints fetch failed: ' . $e->getMessage());
                $responses = [];
            }

            foreach ($missing as $id) {
                $r = $responses[(string) $id] ?? null;
                if (! $r instanceof Response || ! $r->successful()) {
                    Log::warning('Dealer scorecard: complaints unavailable for dealer ' . $id);
                    continue;
                }

                // Keep only what we need (the API response also carries every reply message).
                $slim = [];
                foreach (($r->json('data') ?? []) as $c) {
                    $slim[] = [
                        'status'      => $c['status'] ?? null,
                        'createdAt'   => $c['createdAt'] ?? null,
                        'escalatedAt' => $c['escalatedAt'] ?? null,
                        'resolvedAt'  => $c['resolvedAt'] ?? null,
                    ];
                }
                Cache::put($this->cacheKey($id), $slim, self::COMPLAINT_CACHE_SECONDS);
                $lists[$id] = $slim;
            }
        }

        $out = [];
        foreach ($lists as $id => $list) {
            $total = 0;
            $open = 0;
            $escalated = 0;
            $hours = [];

            foreach ($list as $c) {
                // Open right now (WithDealer = 0, WithAdmin = 1), regardless of when it was filed.
                if (in_array($c['status'], [0, 1], true)) {
                    $open++;
                }

                $created = $this->utc($c['createdAt']);
                if (! $created || $created->lt($since)) {
                    continue;
                }
                $total++;
                if ($c['escalatedAt']) {
                    $escalated++;
                }
                $resolved = $this->utc($c['resolvedAt']);
                if ($resolved && $resolved->gte($created)) {
                    $hours[] = $created->floatDiffInHours($resolved);
                }
            }

            $out[$id] = [
                'total'     => $total,
                'open'      => $open,
                'escalated' => $escalated,
                'avg_hours' => $hours ? round(array_sum($hours) / count($hours), 1) : null,
            ];
        }

        return $out;
    }

    private function cacheKey(int $dealerId): string
    {
        return 'dealer_scorecard_complaints_' . $dealerId;
    }

    /** The API sends UTC timestamps, sometimes without a "Z". */
    private function utc(?string $value): ?Carbon
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