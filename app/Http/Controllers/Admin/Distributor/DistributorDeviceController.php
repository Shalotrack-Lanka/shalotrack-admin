<?php

namespace App\Http\Controllers\Admin\Distributor;

use App\Http\Controllers\Controller;
use App\Http\Requests\DealerStoreCustomerAdRequest;
use App\Models\ActivatedDevice;
use App\Models\DealerCustomerAd;
use App\Services\Audit;
use App\Services\DistributorDevices;
use App\Services\DistributorNetwork;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Distributor: bind devices of its network to customer vehicles, record the subscription payment, renew,
 * and see (read-only) the customers of its dealers. All decisions live in DistributorDevices / DistributorNetwork;
 * anything outside the network is a 404.
 */
class DistributorDeviceController extends Controller
{
    public function __construct(private DistributorDevices $devices)
    {
    }

    private function network(Request $request): DistributorNetwork
    {
        $network = DistributorNetwork::forUser($request->user());
        abort_if($network === null, 403, 'This login is not linked to an active distributor. Please contact the company.');

        return $network;
    }

    public function index(Request $request)
    {
        $n = $this->network($request);

        $ready = $this->devices->readyDevices($n)->orderBy('imei_number')->get();
        $holders = \App\Models\Dealer::whereIn('id', $ready->pluck('dealer_id')->unique())->pluck('full_name', 'id');
        $leads = DealerCustomerAd::withTrashed()->whereIn('id', $ready->pluck('assigned_customer_id')->filter()->unique())->pluck('name', 'id');

        $active = $this->devices->activeDevices($n)->orderByDesc('activated_device_id')->paginate(25)->withQueryString();
        $holderByImei = \App\Models\SetupShalotrackDevice::whereIn('imei_number', $active->pluck('imei_number'))
            ->pluck('dealer_id', 'imei_number');
        $holderNames = \App\Models\Dealer::whereIn('id', $holderByImei->unique())->pluck('full_name', 'id');

        return view('distributor.devices.index', [
            'distributor' => $n->distributor(),
            'ready'       => $ready,
            'holders'     => $holders,
            'leads'       => $leads,
            'active'      => $active,
            'holderByImei' => $holderByImei,
            'holderNames' => $holderNames,
        ]);
    }

    public function activateForm(Request $request, int $device)
    {
        $n = $this->network($request);
        $dev = $this->devices->findReady($n, $device);

        return view('distributor.devices.activate', [
            'distributor' => $n->distributor(),
            'device'      => $dev,
            'vehicles'    => $this->devices->vehiclesFor($n, $dev)->get(['vehicle_id', 'vehicle_number', 'customer_name', 'make', 'model']),
            'soldTo'      => (int) $dev->assigned_customer_id > 0 ? DealerCustomerAd::withTrashed()->find($dev->assigned_customer_id)?->name : null,
            'plans'       => $this->devices->plans(),
        ]);
    }

    public function activate(Request $request, int $device)
    {
        $n = $this->network($request);
        $this->devices->findReady($n, $device); // 404 for a device outside the network, before anything else

        $data = $request->validate([
            'vehicle_id'   => ['required', 'string', 'max:64'],
            'plan'         => ['required', 'string', 'max:20'],
            'bank_invoice' => ['required', 'string', 'max:60', Rule::unique('activated_devices', 'bank_invoice')],
            'installed_on' => ['nullable', 'date', 'before_or_equal:' . now()->local()->toDateString()],
            'installed_by' => ['nullable', 'string', 'max:100'],
        ], [
            'bank_invoice.unique' => 'This payment reference is already used for another device.',
            'installed_on.before_or_equal' => 'The install date cannot be in the future.',
        ]);

        $bound = $this->devices->activate($n, $device, $data['vehicle_id'], $data);

        return redirect()->route('distributor.devices')
            ->with('success', "Device {$bound->imei_number} is now active on {$bound->vehicle_number} until {$bound->subscription_end_date->format('Y-m-d')}.");
    }

    public function renewForm(Request $request, int $activated)
    {
        $n = $this->network($request);
        $row = $this->devices->findActive($n, $activated);

        return view('distributor.devices.renew', [
            'distributor' => $n->distributor(),
            'row'         => $row,
            'plans'       => $this->devices->plans(),
            'runningUntil' => $row->payment_status === 'Paid' && $row->subscription_end_date?->isFuture() ? $row->subscription_end_date : null,
        ]);
    }

    public function renew(Request $request, int $activated)
    {
        $n = $this->network($request);
        $this->devices->findActive($n, $activated);

        $data = $request->validate([
            'plan'         => ['required', 'string', 'max:20'],
            'bank_invoice' => ['required', 'string', 'max:60', Rule::unique('activated_devices', 'bank_invoice')],
        ], ['bank_invoice.unique' => 'This payment reference is already used for another payment.']);

        $row = $this->devices->renew($n, $activated, $data);

        return redirect()->route('distributor.devices')
            ->with('success', "Subscription for {$row->vehicle_number} renewed until {$row->subscription_end_date->format('Y-m-d')}.");
    }

    public function customers(Request $request)
    {
        $n = $this->network($request);
        $ids = array_merge([(int) $n->distributor()->id], $n->dealerIds());

        $leads = DealerCustomerAd::query()->whereIn('dealer_id', $ids)->orderBy('name')->paginate(30)->withQueryString();
        $names = \App\Models\Dealer::whereIn('id', $ids)->pluck('full_name', 'id');

        return view('distributor.devices.customers', [
            'distributor' => $n->distributor(),
            'leads' => $leads,
            'names' => $names,
            'me' => (int) $n->distributor()->id,
        ]);
    }

    /** The distributor's own customer (for direct sales from its own stock). */
    public function storeCustomer(DealerStoreCustomerAdRequest $request)
    {
        $n = $this->network($request);

        $lead = DealerCustomerAd::create([
            'dealer_id'     => $n->distributor()->id,
            'name'          => trim($request->name),
            'email'         => $request->email ? strtolower(trim($request->email)) : null,
            'contact'       => trim($request->contact),
            'nic_or_id'     => $request->nic_or_id ? trim($request->nic_or_id) : null,
            'no_of_devices' => 0,
            'imei_numbers'  => [],
            'address'       => $request->address,
        ]);

        Audit::record('distributor.customer_registered', 'customer_lead', $lead->id, $lead->name, [], ['distributor' => $n->distributor()->full_name]);

        return back()->with('success', "Customer {$lead->name} registered. Their app account (same phone or email) is needed before a device can be bound.");
    }
}