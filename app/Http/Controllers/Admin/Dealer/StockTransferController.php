<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\Stock;
use App\Models\Dealer;
use App\Models\SetupShalotrackDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\DeviceType;
use App\Models\Supplier;

class StockTransferController extends Controller
{
    public function index()
    {
        // 1. Stock eke company_available_stock eka 0 ta wada wadi ewa vitharak gannawa
        // 'with' eken DeviceType eka join karanawa auto model name eka ganna
        $availableStocks = Stock::with('deviceType')
                                ->where('company_available_stock', '>', 0)
                                ->get();


        // 2. Database eken Active wela inna Dealers lawa gannawa
        $dealers = Dealer::where('status', 'active')->orderBy('full_name')->get();

        $deviceTypes = DeviceType::select('device_types.*')
    ->join('stocks', 'stocks.device_type_id', '=', 'device_types.id')
    ->where('stocks.company_available_stock', '>', 0)
    ->distinct()
    ->orderBy('device_types.device_category')
    ->get();

        $suppliers = Supplier::orderBy('name')->get();

        // 3. Kalin transfer karapu history eka
        $transfers = StockTransfer::with(['stock.deviceType', 'dealer'])->latest()->get();

            return view(
                'admin.dealer.stock_transfer',
                compact(
                    'deviceTypes',
                    'suppliers',
                    'availableStocks',
                    'dealers',
                    'transfers'
                )

);
    }

    public function store(Request $request)
{
    $validated = $request->validate([
        'device_type_id' => 'required|exists:device_types,id',
        'supplier_id'    => 'required|exists:suppliers,id',
        'dealer_id'      => 'required|exists:dealers,id',
        'quantity'       => 'required|integer|min:1',
        'remarks'        => 'nullable|string|max:255',
    ]);

    // Find selected stock
    $stock = Stock::with('deviceType')
        ->where('device_type_id', $validated['device_type_id'])
        ->where('supplier_id', $validated['supplier_id'])
        ->where(
            'company_available_stock',
            '>=',
            $validated['quantity']
        )
        ->orderByDesc('company_available_stock')
        ->first();

    if (!$stock) {
        return back()->withErrors([
            'stock' => 'Selected stock record not found or not enough company stock is available.'
        ])->withInput();
    }

    /*
    |--------------------------------------------------------------------------
    | Check physical registered devices
    |--------------------------------------------------------------------------
    |
    | Stock table tracks quantities.
    | setup_shalotrack_devices tracks actual IMEI devices.
    |
    */

    $availableIndividualDevices =
        SetupShalotrackDevice::whereNull('dealer_id')
            ->where(
                'device_type_id',
                $validated['device_type_id']
            )
            ->where('status', 'Not Activated')
            ->count();

    if ($availableIndividualDevices < $validated['quantity']) {

        return back()->withErrors([
            'quantity' =>
                "Only {$availableIndividualDevices} registered physical device(s) are available for this device type."
        ])->withInput();
    }

    $assignedCount = 0;

    /*
    |--------------------------------------------------------------------------
    | Transfer stock + allocate physical devices
    |--------------------------------------------------------------------------
    */

    DB::transaction(function () use (
        $stock,
        $validated,
        &$assignedCount
    ) {

        /*
         * Lock stock row so two transfers cannot modify
         * the same quantity at the same time.
         */
        $stock = Stock::where('id', $stock->id)
            ->lockForUpdate()
            ->firstOrFail();

        if (
            $stock->company_available_stock
            < $validated['quantity']
        ) {
            throw new \RuntimeException(
                'Not enough company stock available.'
            );
        }

        /*
         * Get exact physical devices.
         *
         * No LIKE matching anymore.
         * device_type_id provides the exact relationship.
         */

        $matchingDevices =
            SetupShalotrackDevice::whereNull('dealer_id')
                ->where(
                    'device_type_id',
                    $validated['device_type_id']
                )
                ->where('status', 'Not Activated')
                ->orderBy('shdevice_id')
                ->limit($validated['quantity'])
                ->lockForUpdate()
                ->get();

        /*
         * Recheck inside transaction.
         */
        if (
            $matchingDevices->count()
            < $validated['quantity']
        ) {
            throw new \RuntimeException(
                'Not enough registered physical devices are available.'
            );
        }

        /*
         * Update bulk stock
         */

        $stock->company_available_stock -=
            $validated['quantity'];

        $stock->dealer_transferred +=
            $validated['quantity'];

        $stock->total_available -=
            $validated['quantity'];

        $stock->save();

        /*
         * Create transfer history
         */

        StockTransfer::create([
            'stock_id'  => $stock->id,
            'dealer_id' => $validated['dealer_id'],
            'quantity'  => $validated['quantity'],
            'remarks'   => $validated['remarks'] ?? null,
        ]);

        /*
         * Allocate each actual IMEI device
         * to the selected dealer
         */

        foreach ($matchingDevices as $device) {

            $device->dealer_id =
                $validated['dealer_id'];

            $device->save();
        }

        $assignedCount =
            $matchingDevices->count();
    });

    /*
    |--------------------------------------------------------------------------
    | Success message
    |--------------------------------------------------------------------------
    */

    $dealer = Dealer::findOrFail(
        $validated['dealer_id']
    );

    $deviceType = DeviceType::findOrFail(
        $validated['device_type_id']
    );

    $deviceName =
        $deviceType->device_category .
        ' with ' .
        $deviceType->model;

    return back()->with(
        'success',
        "{$assignedCount} {$deviceName} device(s) successfully transferred and allocated to {$dealer->full_name}."
    );
}

    public function getSuppliers($deviceTypeId)
    {
        $suppliers = Supplier::select('suppliers.id', 'suppliers.name')
            ->join('stocks', 'stocks.supplier_id', '=', 'suppliers.id')
            ->where('stocks.device_type_id', $deviceTypeId)
            ->where('stocks.company_available_stock', '>', 0)
            ->distinct()
            ->orderBy('suppliers.name')
            ->get();

        return response()->json($suppliers);
    }

    public function getStockInfo($deviceTypeId, $supplierId)
    {
        $stock = Stock::where('device_type_id', $deviceTypeId)
            ->where('supplier_id', $supplierId)
            ->first();

        if (!$stock) {
            return response()->json([
                'available' => 0
            ]);
        }

        return response()->json([
            'available' => $stock->company_available_stock
        ]);
    }

}