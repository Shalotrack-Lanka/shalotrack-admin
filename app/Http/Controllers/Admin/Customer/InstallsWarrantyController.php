<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Services\InstallsWarrantyReport;
use App\Services\RenewalsReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Installs and warranty": who fitted which device, when, and how long its warranty (the subscription
 * period) still runs. Staff only. To change a record, use Edit on Customer Device Management.
 */
class InstallsWarrantyController extends Controller
{
    public function __construct(private InstallsWarrantyReport $report)
    {
    }

    public function index(Request $request)
    {
        $filter = (string) $request->query('filter', InstallsWarrantyReport::FILTER_ALL);
        $filter = array_key_exists($filter, InstallsWarrantyReport::FILTERS) ? $filter : InstallsWarrantyReport::FILTER_ALL;
        $days = (int) $request->query('days', 30);
        $days = in_array($days, InstallsWarrantyReport::WINDOWS, true) ? $days : 30;

        $query = $this->report->query($filter, $days);

        if ($request->query('export') === 'csv') {
            return $this->csv(clone $query, $filter);
        }

        $counts = [];
        foreach (array_keys(InstallsWarrantyReport::FILTERS) as $key) {
            $counts[$key] = $this->report->query($key, $days)->count();
        }

        return view('admin.installs-warranty.index', [
            'rows'    => $query->paginate(50)->withQueryString(),
            'filter'  => $filter,
            'filters' => InstallsWarrantyReport::FILTERS,
            'counts'  => $counts,
            'days'    => $days,
            'windows' => InstallsWarrantyReport::WINDOWS,
        ]);
    }

    private function csv($query, string $filter): StreamedResponse
    {
        $name = 'installs-warranty-' . $filter . '-' . now()->local()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer', 'Vehicle', 'IMEI', 'Dealer', 'Installed on', 'Installed by', 'Install notes', 'Warranty', 'Warranty ends / ended', 'Days left']);

            foreach ($query->cursor() as $row) {
                $w = InstallsWarrantyReport::warranty($row);
                fputcsv($out, [
                    RenewalsReport::csvSafe($row->customer_name),
                    RenewalsReport::csvSafe($row->vehicle_number),
                    RenewalsReport::csvSafe($row->imei_number),
                    RenewalsReport::csvSafe($row->dealer_name),
                    $row->installed_on ? $row->installed_on->format('Y-m-d') : '',
                    RenewalsReport::csvSafe($row->installed_by),
                    RenewalsReport::csvSafe($row->install_notes),
                    $w['label'],
                    $w['date'] ? $w['date']->copy()->local()->format('Y-m-d') : '',
                    $w['state'] === 'active' ? $w['days'] : '',
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}