<?php

namespace App\Http\Controllers\Admin\MasterPages;

use App\Http\Controllers\Controller;
use App\Models\Sim;
use Illuminate\Http\Request;

class CancelSimController extends Controller
{
    public function index(Request $request)
    {
        $sims = Sim::latest()->get([
            'id', 
            'sim_number', 
            'sim_type', 
            'sim_status'
        ]);

        if ($request->wantsJson()) {
            return response()->json($sims);
        }

        return view('admin.master_pages.cancel_sim', compact('sims'));
    }

    public function update(Request $request, Sim $sim)
    {
        $validated = $request->validate([
            'status' => 'required|in:Activated,Not Activated,Temporary Blocked,Canceled',
        ]);

        
        $sim->sim_status = $validated['status'];
        $sim->save();

        return response()->json([
            'success'    => true,
            'sim_status' => $sim->sim_status,
        ]);
    }
}