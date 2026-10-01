<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Services\DealerScorecard;
use App\Services\RenewalsReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Dealer scorecard. Staff (ADMIN) see every dealer side by side; a dealer sees only their own
 * numbers, never another dealer's. Same service for both, so the figures cannot disagree.
 */
class DealerScorecardController extends Controller
{
    public function __construct(private DealerScorecard $scorecard)
    {
    }

    public function admin(Request $request)
    {
        $days = $this->days($request);
        $rows = $this->scorecard->build($days);
        $sort = (string) $request->query('sort', 'sold_window');
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $rows = DealerScorecard::sort($rows, $sort, $dir);

        if ($request->query('export') === 'csv') {
            return $this->csv($rows, $days);
        }

        return view('admin.dealer.scorecard', [
            'rows'      => $rows,
            'days'      => $days,
            'windows'   => DealerScorecard::WINDOWS,
            'sort'      => in_array($sort, DealerScorecard::SORTS, true) ? $sort : 'sold_window',
            'dir'       => $dir,
            'routeName' => 'admin.dealer-scorecard',
            'ownView'   => false,
            'complaintsDown' => collect($rows)->contains(fn ($r) => $r['complaints'] === null),
        ]);
    }

    public function dealer(Request $request)
    {
        $dealerId = auth()->user()->dealer->id ?? null;
        $days = $this->days($request);

        // A dealer login with no dealer record sees nothing (never "everyone").
        $rows = $dealerId === null ? [] : $this->scorecard->build($days, $dealerId);

        return view('dealer.scorecard', [
            'rows'      => $rows,
            'days'      => $days,
            'windows'   => DealerScorecard::WINDOWS,
            'sort'      => 'name',
            'dir'       => 'asc',
            'routeName' => 'dealer.scorecard',
            'ownView'   => true,
            'complaintsDown' => collect($rows)->contains(fn ($r) => $r['complaints'] === null),
        ]);
    }

    private function days(Request $request): int
    {
        $days = (int) $request->query('days', DealerScorecard::DEFAULT_WINDOW);

        return in_array($days, DealerScorecard::WINDOWS, true) ? $days : DealerScorecard::DEFAULT_WINDOW;
    }

    private function csv(array $rows, int $days): StreamedResponse
    {
        $name = 'dealer-scorecard-' . $days . 'd-' . now()->local()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows, $days) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Dealer', 'Tier', 'Region',
                "Sold (last {$days}d)", 'Sold (all time)', 'Active subscriptions', 'Active rate %',
                "Lapsed (last {$days}d)", 'In stock', 'Unsold over ' . DealerScorecard::STALE_STOCK_DAYS . 'd',
                'Oldest stock (days)', 'Avg stock age (days)', 'Awaiting bind', 'Faulty', 'Fault rate %',
                "Complaints (last {$days}d)", 'Complaints open now', 'Escalated', 'Avg hours to resolve', 'Complaints per 100 sold',
                'Flags',
            ]);

            foreach ($rows as $r) {
                fputcsv($out, [
                    RenewalsReport::csvSafe($r['name']), RenewalsReport::csvSafe($r['tier']), RenewalsReport::csvSafe($r['region']),
                    $r['sold_window'], $r['sold_total'], $r['active'], $r['active_rate'] ?? '',
                    $r['lapsed_window'], $r['stock'], $r['stale_stock'],
                    $r['oldest_stock_days'] ?? '', $r['avg_stock_days'] ?? '', $r['awaiting'], $r['faulty'], $r['fault_rate'] ?? '',
                    $r['complaints'] ?? 'unavailable', $r['complaints_open'] ?? 'unavailable', $r['complaints_escalated'] ?? 'unavailable',
                    $r['complaints_avg_hours'] ?? '', $r['complaints_per_100'] ?? '',
                    implode('; ', $r['flags']),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}