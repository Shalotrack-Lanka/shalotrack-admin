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
        // $sims වෙනුවට, Activated සහ Not Activated කියලා variables දෙකකට data ගන්නවා
        $activatedSims = Sim::with('stock')->where('sim_status', 'Activated')->latest()->get();
        
        // කලින් තිබ්බ පරණ SIM වලට sim_status එකක් නැති නිසා, orWhereNull එකකුත් දානවා එතකොට ඒවත් මේකටම වැටෙනවා
        $notActivatedSims = Sim::with('stock')
                            ->where('sim_status', 'Not Activated')
                            ->orWhereNull('sim_status') 
                            ->latest()
                            ->get();

        return view('admin.master_pages.add_sim', compact('activatedSims', 'notActivatedSims'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'sim_type'    => 'required|string|max:255',
            'sim_number'  => 'required|string|unique:sims,sim_number|max:255',
            'imei_number' => 'nullable|string|max:255',
            'sim_status'  => 'required|string|in:Activated,Not Activated', // අලුත් Dropdown එකේ validation එක
        ]);

        // device_types.protocol is NOT NULL — 'N/A' is a placeholder because
        // SIMs don't have a communication protocol. This is a mismatch:
        // SIM stock and raw device stock don't really belong in the same
        // table long-term. Flagging it, not fixing it today.
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
                'status'              => 'Available', // පරණ column එක (වෙනස් කරලා නෑ)
                'sim_status'          => $validated['sim_status'], // අලුතින් හදන column එකට data යනවා
            ]);

            $stock->increment('stock_in');
            $stock->increment('company_available_stock');
            $stock->increment('total_available', 2);
        });

        return redirect()->back()->with('success', 'SIM Product Registered & Master Stock Updated Successfully!');
    }
}