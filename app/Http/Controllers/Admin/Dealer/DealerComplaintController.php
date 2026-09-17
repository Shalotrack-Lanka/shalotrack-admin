<?php

namespace App\Http\Controllers\Admin\Dealer;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class DealerComplaintController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $dealer = $user->dealer ?? (Dealer::find($user->dealer_id) ?? null);

        if (!$dealer) {
            return redirect()->back()->with('error', 'Dealer profile not found.');
        }

        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . "/api/internal/complaints/by-dealer/{$dealer->id}");

        $complaints = $response->successful() ? ($response->json('data') ?? []) : [];

        if (!$response->successful()) {
            \Log::warning('Dealer complaints fetch failed', ['status' => $response->status(), 'dealer_id' => $dealer->id]);
        }

        return view('dealer.complaints', compact('complaints', 'dealer'));
    }

    public function reply(Request $request, string $complaintId)
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $user = auth()->user();
        $dealer = $user->dealer ?? (Dealer::find($user->dealer_id) ?? null);

        if (!$dealer) {
            return back()->withErrors(['dealer' => 'Dealer profile not found.']);
        }

        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/reply", [
                'message' => $request->input('message'),
                'authorType' => 1, // Dealer -- must match ComplaintReplyAuthorType.Dealer on the API side
                'authorName' => $dealer->full_name,
            ]);

        if (!$response->successful()) {
            return back()->withErrors(['reply' => 'Could not send reply. Please try again.']);
        }

        return back()->with('success', 'Reply sent.');
    }

    public function escalate(string $complaintId)
    {
        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/escalate");

        if (!$response->successful()) {
            return back()->withErrors(['escalate' => 'Could not escalate this complaint. Please try again.']);
        }

        return back()->with('success', 'Complaint transferred to ShaloTrack support.');
    }
}