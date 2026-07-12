<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\DeviceType;
use App\Models\Sim;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddSimController extends Controller
{
    public function index()
    {
        // Not Activated SIMs (Dropdown එකෙන් Activated කරපු නැති ඒවා සහ අලුත් ඒවා)
        $notActivatedSims = Sim::with('stock')
                            ->where('sim_status', 'Not Activated')
                            ->orWhereNull('sim_status')
                            ->latest()
                            ->get();

        // Activated SIMs (Dropdown එකෙන් Activated කරපු ඒවා විතරක් යට table එකට ගන්නවා)
        $activatedSims = Sim::with('stock')
                            ->where('sim_status', 'Activated')
                            ->latest()
                            ->get();

        return view('admin.master_pages.add_sim', compact('notActivatedSims', 'activatedSims'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sim_type'    => 'required|string|max:255',

            'imei_number'      => [
            'required',
            'digits:15',                                   // exactly 15 numeric digits, no letters/spaces
            'unique:setup_shalotrack_devices,imei_number',
        ],
        'sim_number'       => [
            'nullable',
            'digits:10',
        ],

           'sim_status'  => 'required|string|in:Activated,Not Activated',

    ],[
        'imei_number.digits' => 'IMEI number must be exactly 15 digits.',
        'imei_number.unique' => 'This IMEI number is already registered.',
        'sim_number.digits'  => 'SIM number must be exactly 10 digits.',
        'sim_number.regex'   => 'SIM number must start with 07 and be followed by 8 digits.',
        
        ]);

        $deviceType = DeviceType::firstOrCreate(
            ['device_category' => 'SIM', 'model' => $request->sim_type],
            ['protocol' => 'N/A']
        );

        $stock = Stock::firstOrCreate(
            ['device_type_id' => $deviceType->id],
            ['stock_in' => 0, 'company_available_stock' => 0, 'total_available' => 0]
        );

        DB::transaction(function () use ($validated, $stock, $request) {
            Sim::create([
                'stock_id'            => $stock->id,
                'sim_type'            => $validated['sim_type'],
                'sim_number'          => $validated['sim_number'],
                'provider'            => $request->provider ?? ucwords($request->sim_type),
                'imei_number'         => $request->imei_number,
                'activation_required' => $request->has('activation_required') ? true : false,
                'status'              => 'Available', 
                'sim_status'          => $validated['sim_status'],
            ]);

            $stock->increment('stock_in');
            $stock->increment('company_available_stock');
            $stock->increment('total_available', 2);
        });

        return redirect()->back()->with('success', 'SIM Product Registered Successfully!');
    }

    // Inline Dropdown එකෙන් මාරු කරද්දී සේව් වෙන්න අලුත් Function එක
    public function updateStatus(Request $request, Sim $sim)
    {
        $validated = $request->validate([
            'sim_status' => 'required|string|in:Activated,Not Activated',
        ]);

        $sim->update([
            'sim_status' => $validated['sim_status']
        ]);

        return redirect()->back()->with('success', 'SIM Status Updated Successfully!');
    }
}