<?php

namespace App\Http\Controllers\Admin\Distributor;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use App\Services\DistributorNetwork;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The distributor's own portal (route middleware: role:DISTRIBUTOR): dashboard, dealers and stock. Every query
 * goes through DistributorNetwork, so a distributor can only ever see its own business and its own dealers.
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

    /** One row per dealer under this distributor, with the numbers the screens show. */
    private function dealerRows(DistributorNetwork $network)
    {
        $dealers = $network->dealers()->orderBy('full_name')->get();
        $ids = $dealers->pluck('id')->all() ?: [0];

        $stock = SetupShalotrackDevice::query()
            ->whereIn('dealer_id', $ids)->whereNull('assigned_customer_id')
            ->where('status', DeviceStatus::Activated->value)
            ->selectRaw('dealer_id, count(*) as c')->groupBy('dealer_id')->pluck('c', 'dealer_id');

        $sold = SetupShalotrackDevice::query()
            ->whereIn('dealer_id', $ids)
            ->whereNotNull('assigned_customer_id')->where('assigned_customer_id', '>', 0)
            ->selectRaw('dealer_id, count(*) as c')->groupBy('dealer_id')->pluck('c', 'dealer_id');

        $leads = $network->dealerLeads()
            ->selectRaw('dealer_id, count(*) as c')->groupBy('dealer_id')->pluck('c', 'dealer_id');

        $last = DealerTransferLedger::query()
            ->where('from_dealer_id', $network->distributor()->id)->whereIn('dealer_id', $ids)
            ->selectRaw('dealer_id, max(created_at) as last_at')->groupBy('dealer_id')->pluck('last_at', 'dealer_id');

        return $dealers->map(function ($d) use ($stock, $sold, $leads, $last) {
            $d->in_stock = (int) ($stock[$d->id] ?? 0);
            $d->sold = (int) ($sold[$d->id] ?? 0);
            $d->customers = (int) ($leads[$d->id] ?? 0);
            $d->last_transfer_at = isset($last[$d->id]) ? Carbon::parse($last[$d->id]) : null;

            return $d;
        });
    }

    public function dashboard(Request $request)
    {
        $network = $this->network($request);
        $dealerIds = $network->dealerIds();
        $dealers = $this->dealerRows($network);

        $ownStock = $network->ownAvailableStock()->count();

        return view('distributor.dashboard', [
            'distributor'     => $network->distributor(),
            'ownStock'        => $ownStock,
            'ownSold'         => $network->ownDevices()->whereNotNull('assigned_customer_id')->where('assigned_customer_id', '>', 0)->count(),
            'dealerCount'     => count($dealerIds),
            'dealerStock'     => (int) $dealers->sum('in_stock'),
            'dealerSold'      => (int) $dealers->sum('sold'),
            'dealerCustomers' => (int) $dealers->sum('customers'),
            'stockByCategory' => $network->ownAvailableStock()
                ->selectRaw("coalesce(device_category, 'Uncategorised') as category, count(*) as c")
                ->groupBy('category')->orderByDesc('c')->pluck('c', 'category'),
            'recent'          => $network->transfers()->with(['dealer', 'fromDealer'])->latest()->limit(6)->get(),
            'dealers'         => $dealers,
            'dealersWithoutStock' => $dealers->where('status', 'active')->where('in_stock', 0)->count(),
        ]);
    }

    public function dealers(Request $request)
    {
        $network = $this->network($request);

        return view('distributor.dealers', [
            'distributor' => $network->distributor(),
            'dealers'     => $this->dealerRows($network),
        ]);
    }

    public function stock(Request $request)
    {
        $network = $this->network($request);

        $filters = $request->validate([
            'q'            => ['nullable', 'string', 'max:60'],
            'category'     => ['nullable', 'string', 'max:100'],
            'availability' => ['nullable', 'in:all,available,other'],
        ]);
        $availability = $filters['availability'] ?? 'all';

        $query = $availability === 'available' ? $network->ownAvailableStock() : $network->ownDevices();

        if ($availability === 'other') {
            $query->whereNotIn('shdevice_id', $network->ownAvailableStock()->select('shdevice_id'));
        }
        if (! empty($filters['category'])) {
            $filters['category'] === 'Uncategorised'
                ? $query->whereNull('device_category')
                : $query->where('device_category', $filters['category']);
        }
        if (! empty($filters['q'])) {
            $term = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $query->where(function ($w) use ($term) {
                $w->where('imei_number', 'like', $term)->orWhere('sim_number', 'like', $term);
            });
        }

        $devices = $query->latest('shdevice_id')->paginate(25)->withQueryString();

        return view('distributor.stock', [
            'distributor'    => $network->distributor(),
            'devices'        => $devices,
            'transferable'   => $network->ownAvailableStock()->whereIn('shdevice_id', $devices->pluck('shdevice_id'))->pluck('shdevice_id')->flip(),
            'categories'     => $network->ownDevices()->selectRaw("coalesce(device_category, 'Uncategorised') as category, count(*) as c")->groupBy('category')->orderBy('category')->pluck('c', 'category'),
            'totalHeld'      => $network->ownDevices()->count(),
            'totalAvailable' => $network->ownAvailableStock()->count(),
            'filters'        => ['q' => $filters['q'] ?? '', 'category' => $filters['category'] ?? '', 'availability' => $availability],
        ]);
    }
}
