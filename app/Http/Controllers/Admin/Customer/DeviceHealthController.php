<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Services\DeviceHealthReport;
use App\Services\RenewalsReport;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Device health": paid devices that have gone quiet, were never connected, or are unplugged.
 * Staff see every device; a dealer sees only the devices they sold. Same report for both.
 */
class DeviceHealthController extends Controller
{
    public function __construct(private DeviceHealthReport $report)
    {
    }

    public function admin(Request $request)
    {
        return $this->respond($request, null, false, 'admin.device-health.index', 'admin.device-health');
    }

    public function dealer(Request $request)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        // A dealer login with no dealer record sees nothing (never "everything").
        return $this->respond($request, $dealerId, $dealerId === null, 'dealer.device-health', 'dealer.device-health');
    }

    private function respond(Request $request, ?int $dealerId, bool $failClosed, string $view, string $routeName)
    {
        $level = in_array($request->query('level'), ['critical', 'warning'], true) ? $request->query('level') : 'all';
        $type = in_array($request->query('type'), DeviceHealthReport::TYPES, true) ? $request->query('type') : 'all';

        $result = $this->report->build($dealerId, $failClosed);

        // Counts are for the whole list; the filters only narrow what is shown.
        $typeCounts = array_fill_keys(DeviceHealthReport::TYPES, 0);
        foreach ($result['rows'] as $r) {
            $typeCounts[$r['type']]++;
        }

        $rows = array_values(array_filter($result['rows'], fn ($r) =>
            ($level === 'all' || $r['level'] === $level) && ($type === 'all' || $r['type'] === $type)));

        if ($request->query('export') === 'csv' && $result['available']) {
            return $this->csv($rows);
        }

        $page = LengthAwarePaginator::resolveCurrentPage();
        $paginator = new LengthAwarePaginator(
            array_slice($rows, ($page - 1) * 50, 50),
            count($rows),
            50,
            $page,
            ['path' => route($routeName), 'query' => $request->query()]
        );

        return view($view, [
            'rows'       => $paginator,
            'available'  => $result['available'],
            'counts'     => $result['counts'],
            'typeCounts' => $typeCounts,
            'level'      => $level,
            'type'       => $type,
            'routeName'  => $routeName,
            'showDealer' => $dealerId === null && ! $failClosed,
        ]);
    }

    private function csv(array $rows): StreamedResponse
    {
        $name = 'device-health-' . now()->local()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Severity', 'Problem', 'Customer', 'Phone', 'Vehicle', 'IMEI', 'Dealer', 'Last contact', 'Hours', 'Detail']);

            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['level'],
                    DeviceHealthReport::TYPE_LABELS[$r['type']],
                    RenewalsReport::csvSafe($r['customer_name']),
                    RenewalsReport::csvSafe($r['customer_phone']),
                    RenewalsReport::csvSafe($r['vehicle_number']),
                    RenewalsReport::csvSafe($r['imei']),
                    RenewalsReport::csvSafe($r['dealer_name']),
                    $r['last_contact'] ? $r['last_contact']->copy()->local()->format('Y-m-d H:i') : '',
                    round($r['hours'], 1),
                    RenewalsReport::csvSafe($r['detail']),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}