<?php

namespace App\Http\Controllers\Admin\CancelRequests; // 💡 New namespace

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

        // 💡 New view path (the cancel_requests folder)
        return view('admin.cancel_requests.cancel_sim', compact('sims'));
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