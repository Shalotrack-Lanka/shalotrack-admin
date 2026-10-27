<?php

namespace App\Http\Controllers\Admin\Distributor;

use App\Support\Input;
use App\Http\Controllers\Controller;
use App\Models\DealerTransferItem;
use App\Models\DealerTransferLedger;
use App\Services\Audit;
use App\Services\DistributorNetwork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A distributor passes devices from its OWN stock to one of ITS dealers (route middleware: role:DISTRIBUTOR).
 *
 * Everything is decided on the server: the receiver must be an active dealer of this distributor, and every
 * device must be in this distributor's own available stock (activated, unsold, unbound). IDs from the form are
 * never trusted: they are re-selected through DistributorNetwork inside a transaction with row locks, so a
 * stale page, a double click or a forged id cannot move someone else's device or the same device twice.
 *
 * A distributor can only send, never edit or delete a transfer: corrections are an admin matter.
 */
class DistributorTransferController extends Controller
{
    private function network(Request $request): DistributorNetwork
    {
        $network = DistributorNetwork::forUser($request->user());
        abort_if($network === null, 403, 'This login is not linked to an active distributor. Please contact the company.');

        return $network;
    }

    public function index(Request $request)
    {
        $network = $this->network($request);

        $stock = $network->ownAvailableStock()
            ->orderBy('device_category')->orderBy('imei_number')
            ->get(['shdevice_id', 'device_category', 'imei_number', 'sim_number'])
            ->groupBy(fn ($d) => $d->device_category ?: 'Uncategorised');

        $dealers = $network->dealers()->where('status', 'active')->orderBy('full_name')->get(['id', 'full_name', 'region']);

        // Current stock of each dealer, so the distributor can see who actually needs devices.
        $dealerStock = \App\Models\SetupShalotrackDevice::query()
            ->whereIn('dealer_id', $dealers->pluck('id')->all() ?: [0])
            ->whereNull('assigned_customer_id')->where('status', \App\Enums\DeviceStatus::Activated->value)
            ->selectRaw('dealer_id, count(*) as c')->groupBy('dealer_id')->pluck('c', 'dealer_id');

        // ?dealer=ID (from the My Dealers page) preselects a dealer; an id outside the network is ignored.
        $preselected = Input::id($request->query('dealer'));
        $preselected = $preselected !== null && $dealers->contains('id', $preselected) ? $preselected : null;

        return view('distributor.transfer', [
            'distributor'  => $network->distributor(),
            'stock'        => $stock,
            'stockTotal'   => $stock->sum(fn ($g) => $g->count()),
            'dealers'      => $dealers,
            'dealerStock'  => $dealerStock,
            'preselected'  => $preselected,
        ]);
    }

    /** All transfers this distributor sent or received, with filters. */
    public function history(Request $request)
    {
        $network = $this->network($request);

        $filters = $request->validate([
            'direction' => ['nullable', 'in:all,sent,received'],
            'dealer_id' => ['nullable', 'integer'],
            'from'      => ['nullable', 'date'],
            'to'        => ['nullable', 'date'],
        ]);
        $direction = $filters['direction'] ?? 'all';
        $me = $network->distributor()->id;

        $query = $network->transfers()->with(['dealer', 'fromDealer']);

        if ($direction === 'sent') {
            $query->where('from_dealer_id', $me);
        } elseif ($direction === 'received') {
            $query->whereNull('from_dealer_id');
        }
        if (! empty($filters['dealer_id'])) {
            $query->where('dealer_id', $filters['dealer_id'])->where('from_dealer_id', $me);
        }
        if (! empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        // Totals for the strip above the table (same filters).
        $totals = (clone $query)->reorder()->selectRaw('count(*) as records, coalesce(sum(quantity), 0) as devices')->first();

        return view('distributor.transfers', [
            'distributor' => $network->distributor(),
            'transfers'   => $query->latest()->paginate(20)->withQueryString(),
            'dealers'     => $network->dealers()->orderBy('full_name')->get(['id', 'full_name']),
            'totals'      => $totals,
            'filters'     => ['direction' => $direction, 'dealer_id' => $filters['dealer_id'] ?? '', 'from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? ''],
        ]);
    }

    /** The exact devices of one transfer (only a transfer this distributor sent or received). */
    public function show(Request $request, DealerTransferLedger $ledger)
    {
        $network = $this->network($request);
        abort_unless($network->mayView($ledger), 404);

        $ledger->load(['dealer', 'fromDealer']);

        return view('distributor.transfer-show', [
            'distributor' => $network->distributor(),
            'ledger'      => $ledger,
            'items'       => $ledger->items()->orderBy('imei_number')->get(),
            'sentByMe'    => (int) $ledger->from_dealer_id === (int) $network->distributor()->id,
        ]);
    }

    public function store(Request $request)
    {
        $network = $this->network($request);

        $validated = $request->validate([
            'dealer_id'    => ['required', 'integer'],
            'device_ids'   => ['required', 'array', 'min:1', 'max:500'],
            'device_ids.*' => ['integer', 'distinct'],
        ]);

        $created = [];

        try {
            DB::transaction(function () use ($network, $validated, $request, &$created) {
                // Receiver must be an ACTIVE dealer that reports to this distributor. Anything else (another
                // distributor's dealer, a direct dealer, another distributor, an archived dealer) looks the
                // same as "not found": nothing about other networks is revealed.
                $dealer = $network->dealers()->where('status', 'active')->whereKey($validated['dealer_id'])->first();
                abort_if($dealer === null, 404);

                $ids = array_values(array_unique(array_map('intval', $validated['device_ids'])));

                $devices = $network->ownAvailableStock()
                    ->whereIn('shdevice_id', $ids)
                    ->lockForUpdate()
                    ->get();

                if ($devices->count() !== count($ids)) {
                    throw new \DomainException('Some selected devices are no longer in your available stock (already passed on, sold or bound). Please refresh and try again.');
                }

                // One transfer record per device category, so every record has a single category and quantity.
                foreach ($devices->groupBy(fn ($d) => $d->device_category ?: 'Uncategorised') as $category => $group) {
                    $ledger = DealerTransferLedger::create([
                        'dealer_id'           => $dealer->id,
                        'from_dealer_id'      => $network->distributor()->id,
                        'device_category'     => $category,
                        'quantity'            => $group->count(),
                        'created_by_admin_id' => $request->user()->admin_id,
                    ]);

                    foreach ($group as $device) {
                        $device->dealer_id = $dealer->id;
                        $device->transfer_id = $ledger->id;
                        $device->allocated_at = now();
                        $device->save();

                        DealerTransferItem::create([
                            'ledger_id'   => $ledger->id,
                            'device_id'   => $device->shdevice_id,
                            'imei_number' => $device->imei_number,
                            'sim_number'  => $device->sim_number,
                        ]);
                    }

                    $created[] = [$ledger, $dealer];
                }
            });
        } catch (\DomainException $e) {
            return back()->withErrors(['transfer' => $e->getMessage()])->withInput();
        } catch (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::error('Distributor transfer database error', ['error' => $e->getMessage()]);

            return back()->withErrors(['transfer' => 'The transfer could not be saved because of a database problem. Nothing was changed. Please try again, and tell the company if it keeps happening.'])->withInput();
        }

        foreach ($created as [$ledger, $dealer]) {
            Audit::record('stock.transferred', 'transfer_ledger', $ledger->id,
                $ledger->quantity . ' x ' . $ledger->device_category . ' to ' . $dealer->full_name,
                [], ['from' => 'distributor', 'from_dealer_id' => $network->distributor()->id,
                     'to_dealer_id' => $dealer->id, 'quantity' => $ledger->quantity]);
        }

        $total = collect($created)->sum(fn ($c) => $c[0]->quantity);
        $name = $created[0][1]->full_name;

        return back()->with('success', "{$total} device(s) transferred to {$name}.");
    }
}