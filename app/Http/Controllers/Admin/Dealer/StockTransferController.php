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
            'stock_id'  => 'required|exists:stocks,id',
            'dealer_id' => 'required|exists:dealers,id',
            'quantity'  => 'required|integer|min:1',
            'remarks'   => 'nullable|string|max:255'
        ]);

        $stock = Stock::findOrFail($validated['stock_id']);

        if ($stock->company_available_stock < $validated['quantity']) {
            return redirect()->back()->withErrors(['quantity' => 'Not enough stock available in the company to transfer.']);
        }

        DB::transaction(function () use ($validated, $stock) {
            // 1. Manage Raw Stock එකෙන් ගාණ අඩු කරලා Dealer Transferred එකට එකතු කරනවා
            $stock->decrement('company_available_stock', $validated['quantity']);
            $stock->increment('dealer_transferred', $validated['quantity']);
            $stock->decrement('total_available', $validated['quantity']);

            // 2. Transfer History එක සේව් කරනවා
            StockTransfer::create($validated);
        });

        return redirect()->back()->with('success', 'Stock transferred to dealer successfully!');
    }
}