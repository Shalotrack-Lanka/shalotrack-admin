<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\DealerTransferLedger;
use App\Models\SetupShalotrackDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    public function index()
    {
        // Only categories that currently have at least one un-transferred,
        // SIM-fitted device are worth offering — nothing to transfer otherwise.
        $deviceCategories = SetupShalotrackDevice::whereNull('dealer_id')
            ->whereNotNull('sim_number')
            ->distinct()
            ->orderBy('device_category')
            ->pluck('device_category');

        $dealers = Dealer::where('status', 'active')->orderBy('full_name')->get();

        $transfers = DealerTransferLedger::with('dealer')->latest()->get();

        // Individual transferred IMEI devices — same data as the
        // standalone Assigned Devices page, embedded here too since this
        // is where an admin naturally wants to see "which exact devices
        // did that bulk number actually turn into."
        $allocatedDevices = SetupShalotrackDevice::with(['dealer', 'deviceType'])
            ->whereNotNull('dealer_id')
            ->orderByDesc('allocated_at')
            ->get();

        return view(
            'admin.dealer.stock_transfer',
            compact('deviceCategories', 'dealers', 'transfers', 'allocatedDevices')
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'device_category' => 'required|string',
            'dealer_id'       => 'required|exists:dealers,id',
            'sim_numbers'     => 'required|array|min:1',
            'sim_numbers.*'   => 'required|string',
        ]);

        try {
            DB::transaction(function () use ($validated) {

                $devices = SetupShalotrackDevice::where('device_category', $validated['device_category'])
                    ->whereIn('sim_number', $validated['sim_numbers'])
                    ->whereNull('dealer_id')
                    ->lockForUpdate()
                    ->get();

                if ($devices->count() < count($validated['sim_numbers'])) {
                    throw new \Exception(
                        'Some selected SIM numbers are no longer available for transfer. Please refresh and try again.'
                    );
                }

                $ledger = DealerTransferLedger::create([
                    'dealer_id'       => $validated['dealer_id'],
                    'device_category' => $validated['device_category'],
                    'quantity'        => $devices->count(),
                ]);

                foreach ($devices as $device) {
                    $device->dealer_id = $validated['dealer_id'];
                    $device->transfer_id = $ledger->id;
                    $device->allocated_at = now();
                    $device->save();
                }
            });

            $dealer = Dealer::findOrFail($validated['dealer_id']);

            return back()->with(
                'success',
                count($validated['sim_numbers'])
                . ' ' . $validated['device_category']
                . ' device(s) successfully transferred to ' . $dealer->full_name . '.'
            );

        } catch (\Exception $e) {
            return back()
                ->withErrors(['transfer' => $e->getMessage()])
                ->withInput();
        }
    }

    public function update(Request $request, DealerTransferLedger $ledger)
    {
        $validated = $request->validate([
            'dealer_id'     => 'required|exists:dealers,id',
            'sim_numbers'   => 'required|array|min:1',
            'sim_numbers.*' => 'required|string',
        ]);

        try {
            DB::transaction(function () use ($validated, $ledger) {

                $currentDevices = SetupShalotrackDevice::where('transfer_id', $ledger->id)
                    ->lockForUpdate()
                    ->get();

                $currentSimNumbers = $currentDevices->pluck('sim_number')->all();

                $simsToRemove = array_diff($currentSimNumbers, $validated['sim_numbers']);
                $simsToAdd = array_diff($validated['sim_numbers'], $currentSimNumbers);

                // Detach devices that were deselected — they go back to the
                // unassigned pool and reappear in Setup Shalotrack Devices.
                if (!empty($simsToRemove)) {
                    SetupShalotrackDevice::where('transfer_id', $ledger->id)
                        ->whereIn('sim_number', $simsToRemove)
                        ->update([
                            'dealer_id'    => null,
                            'transfer_id'  => null,
                            'allocated_at' => null,
                        ]);
                }

                // Attach newly selected devices — must still be available and
                // of the same device category as this ledger entry.
                if (!empty($simsToAdd)) {
                    $newDevices = SetupShalotrackDevice::where('device_category', $ledger->device_category)
                        ->whereIn('sim_number', $simsToAdd)
                        ->whereNull('dealer_id')
                        ->lockForUpdate()
                        ->get();

                    if ($newDevices->count() < count($simsToAdd)) {
                        throw new \Exception(
                            'Some newly selected SIM numbers are no longer available. Please refresh and try again.'
                        );
                    }

                    foreach ($newDevices as $device) {
                        $device->dealer_id = $validated['dealer_id'];
                        $device->transfer_id = $ledger->id;
                        $device->allocated_at = now();
                        $device->save();
                    }
                }

                // Devices still selected (unchanged) — make sure their dealer
                // matches if the dealer itself was changed on this edit.
                SetupShalotrackDevice::where('transfer_id', $ledger->id)
                    ->update(['dealer_id' => $validated['dealer_id']]);

                $ledger->dealer_id = $validated['dealer_id'];
                $ledger->quantity = count($validated['sim_numbers']);
                $ledger->save();
            });

            return back()->with('success', 'Transfer record updated successfully.');

        } catch (\Exception $e) {
            return back()->withErrors(['transfer_edit' => $e->getMessage()]);
        }
    }

    public function destroy(DealerTransferLedger $ledger)
    {
        // Only the history row is removed — the linked devices keep their
        // dealer assignment (transfer_id is cleared via nullOnDelete), per
        // business decision: a deleted history record does not undo a
        // transfer that already happened.
        $ledger->delete();

        return back()->with('success', 'Transfer history record removed.');
    }

    public function getSimNumbers($category)
    {
        $simNumbers = SetupShalotrackDevice::where('device_category', $category)
            ->whereNull('dealer_id')
            ->whereNotNull('sim_number')
            ->orderBy('sim_number')
            ->pluck('sim_number');

        return response()->json($simNumbers);
    }

    public function editData(DealerTransferLedger $ledger)
    {
        $selected = SetupShalotrackDevice::where('transfer_id', $ledger->id)
            ->orderBy('sim_number')
            ->pluck('sim_number');

        $available = SetupShalotrackDevice::where('device_category', $ledger->device_category)
            ->whereNull('dealer_id')
            ->whereNotNull('sim_number')
            ->orderBy('sim_number')
            ->pluck('sim_number');

        return response()->json([
            'dealer_id'       => $ledger->dealer_id,
            'device_category' => $ledger->device_category,
            'selected'        => $selected,
            // Selected sims must stay in the option list even though
            // they're not "available" — otherwise the edit form silently
            // drops them the moment it renders.
            'sim_numbers'     => $selected->merge($available)->unique()->sort()->values(),
        ]);
    }
}
