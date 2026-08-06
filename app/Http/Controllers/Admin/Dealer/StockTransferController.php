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

            try {

                DB::transaction(function () use ($validated) {

                    /*
                    |--------------------------------------------------------------------------
                    | 1. Find & lock stock
                    |--------------------------------------------------------------------------
                    */

                    $stock = Stock::where(
                            'device_type_id',
                            $validated['device_type_id']
                        )
                        ->where(
                            'supplier_id',
                            $validated['supplier_id']
                        )
                        ->lockForUpdate()
                        ->first();

                    if (!$stock) {
                        throw new \Exception(
                            'Selected stock record was not found.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 2. Check bulk stock quantity
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $stock->company_available_stock
                        < $validated['quantity']
                    ) {
                        throw new \Exception(
                            'Not enough company stock available.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 3. Find actual physical devices
                    |--------------------------------------------------------------------------
                    |
                    | Example:
                    |
                    | Stock Transfer:
                    | SIM with dialog
                    |
                    | device_type_id = 7
                    |
                    | We ONLY select:
                    |
                    | device_type_id = 7
                    | dealer_id = NULL
                    | status = Not Activated
                    |
                    */

                    $devices = SetupShalotrackDevice::where(
                            'device_type_id',
                            $validated['device_type_id']
                        )
                        ->whereNull('dealer_id')
                        ->where('status', 'Not Activated')
                        ->orderBy('shdevice_id')
                        ->limit($validated['quantity'])
                        ->lockForUpdate()
                        ->get();

                    /*
                    |--------------------------------------------------------------------------
                    | 4. Check physical device availability
                    |--------------------------------------------------------------------------
                    */

                    if (
                        $devices->count()
                        < $validated['quantity']
                    ) {

                        throw new \Exception(
                            'Only '
                            . $devices->count()
                            . ' registered physical device(s) are available for this device type.'
                        );
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | 5. Update Stock
                    |--------------------------------------------------------------------------
                    */

                    $stock->company_available_stock -=
                        $validated['quantity'];

                    $stock->dealer_transferred +=
                        $validated['quantity'];

                    $stock->total_available -=
                        $validated['quantity'];

                    $stock->save();


                    /*
                    |--------------------------------------------------------------------------
                    | 6. Create Stock Transfer History
                    |--------------------------------------------------------------------------
                    */

                    StockTransfer::create([
                        'stock_id'  => $stock->id,
                        'dealer_id' => $validated['dealer_id'],
                        'quantity'  => $validated['quantity'],
                        'remarks'   => $validated['remarks'] ?? null,
                    ]);


                    /*
                    |--------------------------------------------------------------------------
                    | 7. AUTOMATICALLY ASSIGN DEVICES TO DEALER
                    |--------------------------------------------------------------------------
                    |
                    | THIS IS THE IMPORTANT PART.
                    |
                    | dealer_id NULL
                    |
                    | becomes:
                    |
                    | dealer_id = selected dealer
                    |
                    */

                    foreach ($devices as $device) {

                        $device->dealer_id =
                            $validated['dealer_id'];

                        $device->save();
                    }

                });


                /*
                |--------------------------------------------------------------------------
                | 8. Success Message
                |--------------------------------------------------------------------------
                */

                $dealer = Dealer::findOrFail(
                    $validated['dealer_id']
                );

                $deviceType = DeviceType::findOrFail(
                    $validated['device_type_id']
                );

                $deviceName =
                    $deviceType->device_category
                    . ' with '
                    . $deviceType->model;

                return back()->with(
                    'success',
                    $validated['quantity']
                    . ' '
                    . $deviceName
                    . ' device(s) successfully transferred and allocated to '
                    . $dealer->full_name
                    . '.'
                );

            } catch (\Exception $e) {

                return back()
                    ->withErrors([
                        'transfer' => $e->getMessage()
                    ])
                    ->withInput();
            }
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