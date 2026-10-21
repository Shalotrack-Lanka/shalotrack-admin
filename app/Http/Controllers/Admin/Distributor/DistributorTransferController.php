<?php

namespace App\Http\Controllers\Admin\Distributor;

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

        $dealers = $network->dealers()->where('status', 'active')->orderBy('full_name')->get(['id', 'full_name']);

        $sent = DealerTransferLedger::with('dealer')
            ->where('from_dealer_id', $network->distributor()->id)
            ->latest()->limit(100)->get();

        $received = DealerTransferLedger::where('dealer_id', $network->distributor()->id)
            ->whereNull('from_dealer_id')
            ->latest()->limit(100)->get();

        return view('distributor.transfer', [
            'distributor' => $network->distributor(),
            'stock'       => $stock,
            'dealers'     => $dealers,
            'sent'        => $sent,
            'received'    => $received,
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