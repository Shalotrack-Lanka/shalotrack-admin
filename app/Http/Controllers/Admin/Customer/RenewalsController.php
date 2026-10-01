<?php

namespace App\Http\Controllers\Admin\Customer;

use App\Http\Controllers\Controller;
use App\Services\RenewalsReport;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Renewals due": the worklist for calling customers before their subscription lapses.
 * Staff see every device; a dealer sees only devices they sold. Same query for both
 * (RenewalsReport), different layout and route.
 */
class RenewalsController extends Controller
{
    public function __construct(private RenewalsReport $report)
    {
    }

    public function admin(Request $request)
    {
        return $this->respond($request, null, false, 'admin.renewals.index', 'admin.renewals');
    }

    public function dealer(Request $request)
    {
        $dealerId = auth()->user()->dealer->id ?? null;

        // A dealer login with no dealer record sees nothing (never "everything").
        return $this->respond($request, $dealerId, $dealerId === null, 'dealer.renewals', 'dealer.renewals');
    }

    private function respond(Request $request, ?int $dealerId, bool $failClosed, string $view, string $routeName)
    {
        $scope = $request->query('scope') === RenewalsReport::SCOPE_OVERDUE
            ? RenewalsReport::SCOPE_OVERDUE
            : RenewalsReport::SCOPE_DUE;
        $days = (int) $request->query('days', 30);
        $days = in_array($days, RenewalsReport::WINDOWS, true) ? $days : 30;

        $query = $this->report->query($scope, $days, $dealerId, $failClosed);

        if ($request->query('export') === 'csv') {
            return $this->csv(clone $query, $scope);
        }

        // Counts for the two tabs, whatever tab is open.
        $dueCount = $this->report->query(RenewalsReport::SCOPE_DUE, $days, $dealerId, $failClosed)->count();
        $overdueCount = $this->report->query(RenewalsReport::SCOPE_OVERDUE, $days, $dealerId, $failClosed)->count();

        return view($view, [
            'rows'         => $query->paginate(50)->withQueryString(),
            'scope'        => $scope,
            'days'         => $days,
            'windows'      => RenewalsReport::WINDOWS,
            'dueCount'     => $dueCount,
            'overdueCount' => $overdueCount,
            'routeName'    => $routeName,
            'showDealer'   => $dealerId === null && ! $failClosed,
        ]);
    }

    private function csv($query, string $scope): StreamedResponse
    {
        $name = 'renewals-' . $scope . '-' . now()->local()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($query, $scope) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Customer', 'Phone', 'Vehicle', 'IMEI', 'Dealer', $scope === RenewalsReport::SCOPE_OVERDUE ? 'Ended on' : 'Ends on', 'Days left', 'Plan']);

            foreach ($query->cursor() as $row) {
                $end = $scope === RenewalsReport::SCOPE_OVERDUE
                    ? ($row->ended_on ? \Illuminate\Support\Carbon::parse($row->ended_on) : null)
                    : $row->subscription_end_date;

                fputcsv($out, [
                    RenewalsReport::csvSafe($row->customer_name),
                    RenewalsReport::csvSafe($row->customer_phone),
                    RenewalsReport::csvSafe($row->vehicle_number),
                    RenewalsReport::csvSafe($row->imei_number),
                    RenewalsReport::csvSafe($row->dealer_name),
                    $end ? $end->copy()->local()->format('Y-m-d') : '',
                    $scope === RenewalsReport::SCOPE_DUE ? RenewalsReport::daysLeft($end) : '',
                    RenewalsReport::csvSafe($row->subscription_model),
                ]);
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}