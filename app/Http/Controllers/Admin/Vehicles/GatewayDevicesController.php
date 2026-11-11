<?php

namespace App\Http\Controllers\Admin\Vehicles;

use App\Http\Controllers\Controller;
use App\Models\ActivatedDevice;
use App\Services\GatewayStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Gateway Devices: which devices are connected right now, which are inside their free trial, which have
 * run out of it and wait for the office to bind them, and which are stopped. Read-only: the numbers come
 * from the gateway (it owns the trial clock), names from this database.
 */
class GatewayDevicesController extends Controller
{
    private const FILTERS = ['attention', 'connected', 'all'];

    public function index(Request $request)
    {
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'attention';

        $result = GatewayStatus::accessReport();
        $report = $result['report'];
        $devices = collect($report['devices'] ?? []);

        $names = collect();
        if ($devices->isNotEmpty() && Schema::hasTable('activated_devices')) {
            $names = ActivatedDevice::whereIn('imei_number', $devices->pluck('imei')->all())
                ->get(['imei_number', 'vehicle_number', 'customer_name'])
                ->keyBy('imei_number');
        }

        $rows = $devices
            ->filter(fn ($d) => match ($filter) {
                'connected' => (bool) ($d['connected'] ?? false),
                'all'       => true,
                default     => in_array($d['category'] ?? '', ['trial_expired', 'stopped', 'trial', 'trial_not_started'], true),
            })
            ->map(function ($d) use ($names) {
                $n = $names->get($d['imei']);
                $d['vehicle_number'] = $n->vehicle_number ?? null;
                $d['customer_name'] = $n->customer_name ?? null;

                return $d;
            })
            ->values();

        return view('admin.vehicles.gateway_devices', [
            'ok'     => $result['ok'],
            'status' => $result['status'],
            'report' => $report,
            'rows'   => $rows,
            'filter' => $filter,
        ]);
    }
}
