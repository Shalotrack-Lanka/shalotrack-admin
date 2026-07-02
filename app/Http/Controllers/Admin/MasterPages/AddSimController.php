<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\Sim;
use App\Models\Stock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AddSimController extends Controller
{

    // 🟢 මේ නව index function එක Controller එක ඇතුළට එකතු කරන්න
    public function index()
    {
        // (A) දැනට ඇතුළත් කර ඇති SIM රෙකෝඩ්ස් ටික ඩේටාබේස් එකෙන් ගන්නවා
        $sims = Sim::with('stock')->latest()->get();
        
        // (B) ඔයාගේ views ෆෝල්ඩරයේ තියෙන අදාළ blade view එක ලෝඩ් කරනවා
        // (views/admin/master-pages/add_sim.blade.php වැනි තැනක ඇති ෆයිල් එකේ නම දෙන්න)
        return view('admin.master_pages.add_sim', compact('sims'));
    }


    public function store(Request $request)
    {
        // 1. Data Validation (SIM Number එක කලින් ඇතුළත් කර ඇත්දැයි බලයි)
        $validated = $request->validate([
            'sim_type'    => 'required|string|max:255',
            'sim_number'  => 'required|string|unique:sims,sim_number|max:255',
            'imei_number' => 'nullable|string|max:255',
        ]);

       $stock = Stock::firstOrCreate(
        ['product_model' => $request->sim_type],
        [
            'product_name' => ucwords(str_replace('_', ' ', $request->sim_type)) . ' SIM',
            'stock_in' => 0, 'company_available_stock' => 0, 'dealer_available_stock' => 0, 'sold_to_customer' => 0
        ]
    );
        // 3. Database Transaction එකක් ඇතුළත එකවර වගු දෙකම ආරක්ෂිතව Update කිරීම
        DB::transaction(function () use ($validated, $stock, $request) {
            
            // (A) SIM එක Sims Table එකට එකතු කිරීම
            Sim::create([
                'stock_id'            => $stock->id,
                'sim_type'            => $validated['sim_type'],
                'sim_number'          => $validated['sim_number'],
                'provider'            => $request->provider ?? ucwords($request->sim_type),
                'imei_number'         => $request->imei_number,
                'activation_required' => $request->has('activation_required') ? true : false,
                'status'              => 'Available',
            ]);

            // (B) Master Stock Counts 1කින් වැඩි කිරීම (Qty Update)
            $stock->increment('stock_in');
            $stock->increment('company_available_stock');
        });
  

        // 4. සාර්ථකත්වයේ පණිවිඩය සමඟින් නැවත හරවා යැවීම
        return redirect()->back()->with('success', 'SIM Product Registered & Master Stock Updated Successfully!');
    }
}