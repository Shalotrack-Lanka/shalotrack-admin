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
        // 1. Data Validation (IMEI එක unique ද කියා බලනවා)
        $validated = $request->validate([
            'imei_number'   => 'required|string|unique:devices,imei_number|max:255',
            'sim_number'    => 'nullable|string|max:255',
            'device_model'  => 'required|string|max:255',
            'branch_name'   => 'required|string',
            'status'        => 'required|string',
            'description'   => 'nullable|string',
        ]);

        // 2. තෝරාගත් Device Model එකට අදාළ Master Stock Record එක database එකෙන් සොයා ගැනීම
        $stock = Stock::firstOrCreate(
    ['product_model' => $request->device_model], // v5_basic හෝ v5_plus ද කියා සොයයි
    [
        // 🟢 ජනනය වන ලස්සන Display Name එක (v5_basic -> V5 Basic)
        'product_name' => ucwords(str_replace('_', ' ', $request->device_model)), 
        'stock_in' => 0,
        'company_available_stock' => 0,
        'dealer_available_stock' => 0,
        'sold_to_customer' => 0
    ]
);

        if (!$stock) {
            return redirect()->back()->withErrors(['device_model' => 'තෝරාගත් Device Model එක Stock Summary එකේ ලියාපදිංචි කර නැත. මුලින්ම එය සාදන්න.']);
        }

        // 3. Database Transaction එකක් ඇතුළත එකවර වැඩ දෙකම සිදු කිරීම (ආරක්ෂිත ක්‍රමය)
        DB::transaction(function () use ($validated, $stock, $request) {
            
            // (A) Device එක Devices Table එකට Save කිරීම (`stock_id` එකද සහිතව)
            Device::create([
                'stock_id'     => $stock->id,
                'imei_number'  => $validated['imei_number'],
                'sim_number'   => $request->sim_number,
                'device_model' => $validated['device_model'],
                'branch_name'  => $validated['branch_name'],
                'status'       => $validated['status'],
                'description'  => $request->description,
            ]);

            // (B) Device Status එක අනුව Master Stock Table එක ස්වයංක්‍රීයව Update කිරීම
            if ($request->status === 'Available') {
                $stock->increment('stock_in'); // මුළු Stock එක 1කින් වැඩි කරයි
                $stock->increment('company_available_stock'); // Company Available එක 1කින් වැඩි කරයි
            } 
            elseif ($request->status === 'Faulty') {
                // Faulty device එකක් නම් මුළු stock_in එකට එකතු වී company stock එක අඩු විය යුතු නම් හෝ වෙනත් logic එකක් නම් මෙතන වෙනස් කරන්න පුළුවන්.
                // දැනට සාමාන්‍යයෙන් Faulty ආපු එකක් නිසා stock_in එක 1කින් වැඩි කරමු:
                $stock->increment('stock_in');
            }
        });

        return redirect()->back()->with('success', 'Device Registered & Master Stock Updated Successfully!');
    }
}