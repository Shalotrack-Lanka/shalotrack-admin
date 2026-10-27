<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Support\Input;
use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Models\Dealer;
use Illuminate\Http\Request;

class AssignedDevicesController extends Controller
{
    /**
     * All devices allocated to any dealer, across the whole system —
     * the per-dealer profile page shows one dealer at a time, this is
     * the admin-wide view.
     */
    public function index(Request $request)
    {
        $search   = Input::text($request->query('search'), 100);
        $dealerId = Input::id($request->query('dealer_id'));

        $devices = SetupShalotrackDevice::with(['dealer', 'deviceType'])
            ->whereNotNull('dealer_id')
            ->when($search !== '', function ($query) use ($search) {
                // ilike — Postgres, case-insensitive search on IMEI/SIM.
                $query->where(function ($q) use ($search) {
                    $like = Input::contains($search);
                    $q->where('imei_number', 'ilike', $like)
                      ->orWhere('sim_number', 'ilike', $like);
                });
            })
            ->when($dealerId !== null, function ($query) use ($dealerId) {
                $query->where('dealer_id', $dealerId);
            })
            ->orderByDesc('allocated_at')
            ->get();

        $dealers = Dealer::orderBy('full_name')->get();

        return view('admin.dealer.assigned_devices', compact(
            'devices', 'dealers', 'search', 'dealerId'
        ));
    }
}