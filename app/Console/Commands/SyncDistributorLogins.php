<?php

namespace App\Http\Controllers\Admin\Distributor;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Models\SetupShalotrackDevice;
use App\Services\DistributorNetwork;
use Illuminate\Http\Request;

/**
 * The distributor's own portal (route middleware: role:DISTRIBUTOR). Read-only in this version: it shows
 * what the distributor holds and which dealers report to it. Every query goes through DistributorNetwork,
 * so a distributor can only ever see its own business and its own dealers.
 */
class DistributorPortalController extends Controller
{
    private function network(Request $request): DistributorNetwork
    {
        $network = DistributorNetwork::forUser($request->user());

        // A DISTRIBUTOR login that does not point at an active distributor record sees nothing at all.
        abort_if($network === null, 403, 'This login is not linked to an active distributor. Please contact the company.');

        return $network;
    }

    public function dashboard(Request $request)
    {
        $network = $this->network($request);

        $dealerIds = $network->dealerIds();

        return view('distributor.dashboard', [
            'distributor'      => $network->distributor(),
            'ownStock'         => $network->ownAvailableStock()->count(),
            'ownSold'          => $network->ownDevices()->whereNotNull('assigned_customer_id')->where('assigned_customer_id', '>', 0)->count(),
            'dealerCount'      => count($dealerIds),
            'dealerStock'      => $network->dealerDevices()->whereNull('assigned_customer_id')->where('status', DeviceStatus::Activated->value)->count(),
            'dealerSold'       => $network->dealerDevices()->whereNotNull('assigned_customer_id')->where('assigned_customer_id', '>', 0)->count(),
            'dealerCustomers'  => $network->dealerLeads()->count(),
        ]);
    }

    public function dealers(Request $request)
    {
        $network = $this->network($request);

        $dealers = $network->dealers()->orderBy('full_name')->get();
        $ids = $dealers->pluck('id')->all() ?: [0];

        $stockByDealer = SetupShalotrackDevice::query()
            ->whereIn('dealer_id', $ids)
            ->whereNull('assigned_customer_id')
            ->where('status', DeviceStatus::Activated->value)
            ->selectRaw('dealer_id, count(*) as c')->groupBy('dealer_id')->pluck('c', 'dealer_id');

        $soldByDealer = SetupShalotrackDevice::query()
            ->whereIn('dealer_id', $ids)
            ->whereNotNull('assigned_customer_id')->where('assigned_customer_id', '>', 0)
            ->selectRaw('dealer_id, count(*) as c')->groupBy('dealer_id')->pluck('c', 'dealer_id');

        $leadsByDealer = $network->dealerLeads()
            ->selectRaw('dealer_id, count(*) as c')->groupBy('dealer_id')->pluck('c', 'dealer_id');

        return view('distributor.dealers', [
            'distributor'   => $network->distributor(),
            'dealers'       => $dealers,
            'stockByDealer' => $stockByDealer,
            'soldByDealer'  => $soldByDealer,
            'leadsByDealer' => $leadsByDealer,
        ]);
    }

    public function stock(Request $request)
    {
        $network = $this->network($request);

        $devices = $network->ownDevices()->latest('shdevice_id')->paginate(50);

        return view('distributor.stock', [
            'distributor' => $network->distributor(),
            'devices'     => $devices,
        ]);
    }
}