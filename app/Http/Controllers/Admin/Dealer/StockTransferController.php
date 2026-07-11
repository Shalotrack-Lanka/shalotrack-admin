<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\Stock;
use App\Models\Dealer;
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

        $deviceTypes = DeviceType::orderBy('device_category')->get();

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

    // Find the correct stock row
    $stock = Stock::where('device_type_id', $validated['device_type_id'])
              ->where('supplier_id', $validated['supplier_id'])
              ->where('company_available_stock', '>=', $validated['quantity'])
              ->orderByDesc('company_available_stock')
              ->first();

    if (!$stock) {
        return back()->withErrors([
            'stock' => 'Selected stock record not found.'
        ]);
    }

    // Check available quantity
    if ($stock->company_available_stock < $validated['quantity']) {
        return back()->withErrors([
            'quantity' => 'Not enough company stock available.'
        ]);
    }

    DB::transaction(function () use ($stock, $validated) {

        $stock->company_available_stock -= $validated['quantity'];
        $stock->dealer_transferred += $validated['quantity'];
        $stock->total_available -= $validated['quantity'];

        $stock->save();

        StockTransfer::create([
            'stock_id'  => $stock->id,
            'dealer_id' => $validated['dealer_id'],
            'quantity'  => $validated['quantity'],
            'remarks'   => $validated['remarks'] ?? null,
        ]);

    });

    return back()->with('success', 'Stock transferred successfully.');
}
}