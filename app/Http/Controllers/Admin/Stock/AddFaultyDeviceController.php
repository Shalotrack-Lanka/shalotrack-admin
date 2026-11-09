<?php

namespace App\Http\Controllers\Admin\Stock;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddFaultyDeviceController extends Controller
{
    public function index()
    {
        $devices = Device::with('stock')->latest()->get();
        $productModels = Stock::select('product_model')->distinct()->pluck('product_model');
        
        return view('admin.stock.add_faulty_device', compact('devices', 'productModels'));
    }

    public function store(Request $request)
    {
        // 1. Data validation (check the IMEI is unique)
        $validated = $request->validate([
            'imei_number'   => 'required|string|unique:devices,imei_number|max:255',
            'sim_number'    => 'nullable|string|max:255',
            'device_model'  => 'required|string|max:255',
            'branch_name'   => 'required|string|max:100',
            'status'        => 'required|string|max:50',
            'description'   => 'nullable|string|max:1000',
        ]);

        // 2. Find the master stock record for the selected device model
        $stock = Stock::firstOrCreate(
    ['product_model' => $request->device_model], // looks up v5_basic or v5_plus
    [
        // 🟢 Generated display name (v5_basic -> V5 Basic)
        'product_name' => ucwords(str_replace('_', ' ', $request->device_model)), 
        'stock_in' => 0,
        'company_available_stock' => 0,
        'dealer_available_stock' => 0,
        'sold_to_customer' => 0
    ]
);

        if (!$stock) {
            return redirect()->back()->withErrors(['device_model' => 'The selected device model is not registered in the Stock Summary. Create it first.']);
        }

        // 3. Do both writes inside one database transaction (the safe way)
        DB::transaction(function () use ($validated, $stock, $request) {
            
            // (A) Save the device to the devices table (including stock_id)
            Device::create([
                'stock_id'     => $stock->id,
                'imei_number'  => $validated['imei_number'],
                'sim_number'   => $request->sim_number,
                'device_model' => $validated['device_model'],
                'branch_name'  => $validated['branch_name'],
                'status'       => $validated['status'],
                'description'  => $request->description,
            ]);

            // (B) Update the master stock table automatically according to the device status
            if ($request->status === 'Available') {
                $stock->increment('stock_in'); // Increase the total stock by 1
                $stock->increment('company_available_stock'); // Increase company available by 1
            } 
            elseif ($request->status === 'Faulty') {
                // If a faulty device should be added to the total stock_in while reducing company stock, or needs other logic, change it here.
                // For now, since it came back as faulty, increase stock_in by 1:
                $stock->increment('stock_in');
            }
        });

        return redirect()->back()->with('success', 'Device Registered & Master Stock Updated Successfully!');
    }
}