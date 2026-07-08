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
            'id', 'sim_number', 'sim_type', 'provider',
            'status', 'description', 'canceled_at',
        ]);

        // Refresh button hits this same URL with Accept: application/json —
        // one endpoint, two response shapes, instead of duplicating the query.
        if ($request->wantsJson()) {
            return response()->json($sims);
        }

        return view('admin.master_pages.cancel_sim', compact('sims'));
    }

    public function update(Request $request, Sim $sim)
    {
        $validated = $request->validate([
            'status'      => 'required|in:Available,Temporarily Stopped,Canceled,Faulty',
            'description' => 'nullable|string|max:1000',
        ]);

        $sim->canceled_at = $validated['status'] === 'Canceled'
            ? now()
            : null;

        $sim->status      = $validated['status'];
        $sim->description = $validated['description'];
        $sim->save();

        return response()->json([
            'success'     => true,
            'status'      => $sim->status,
            'canceled_at' => optional($sim->canceled_at)->format('Y-m-d H:i'),
        ]);
    }
}