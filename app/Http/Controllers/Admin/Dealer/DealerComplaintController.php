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
                // NEW -- lets the API verify this complaint is actually
                // this dealer's own before applying the reply, closing an
                // IDOR that previously let any dealer act on any
                // complaintId with no ownership check at all.
                'dealerId' => $dealer->id,
            ]);

        if (!$response->successful()) {
            return back()->withErrors(['reply' => 'Could not send reply. Please try again.']);
        }

        return back()->with('success', 'Reply sent.');
    }


    public function escalate(string $complaintId)
    {
        $dealer = $this->currentDealer();
        if (!$dealer) {
            return back()->withErrors(['dealer' => 'Dealer profile not found.']);
        }

        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/escalate?dealerId={$dealer->id}");

        \Illuminate\Support\Facades\Cache::forget('dealer_complaints_count_' . $dealer->id);

        if (!$response->successful()) {
            // සැබෑ C# API Error එක ස්ක්‍රීන් එකේ පෙන්වන්න
            return back()->withErrors(['escalate' => 'API Error (' . $response->status() . '): ' . $response->body()]);
        }

        return back()->with('success', 'Complaint transferred to ShaloTrack support.');
    }

    // NEW -- lets a dealer close out a complaint they solved themselves,
    // instead of it sitting in "With Dealer" forever with no way to mark
    // it done (previously only Admin could resolve/close at all).
    public function resolve(string $complaintId)
    {
        $dealer = $this->currentDealer();
        if (!$dealer) {
            return back()->withErrors(['dealer' => 'Dealer profile not found.']);
        }

        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/resolve?dealerId={$dealer->id}");

        if (!$response->successful()) {
            return back()->withErrors(['resolve' => 'Could not resolve this complaint. Please try again.']);
        }

        \Illuminate\Support\Facades\Cache::forget('dealer_complaints_count_' . $dealer->id);

        return back()->with('success', 'Complaint marked as resolved.');
    }

    public function close(string $complaintId)
    {
        $dealer = $this->currentDealer();
        if (!$dealer) {
            return back()->withErrors(['dealer' => 'Dealer profile not found.']);
        }

        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/close?dealerId={$dealer->id}");

        if (!$response->successful()) {
            return back()->withErrors(['close' => 'Could not close this complaint. Please try again.']);
        }

        \Illuminate\Support\Facades\Cache::forget('dealer_complaints_count_' . $dealer->id);

        return back()->with('success', 'Complaint closed.');
    }

    // NEW -- small helper, same dealer-resolution logic that was
    // previously duplicated inline in index()/reply(); resolve()/close()
    // reuse it too rather than a third copy-paste.
    private function currentDealer(): ?Dealer
    {
        $user = auth()->user();
        return $user->dealer ?? (Dealer::find($user->dealer_id) ?? null);
    }

    public function checkNewComplaints()
    {
        $user = auth()->user();
        $dealer = $user->dealer ?? (Dealer::find($user->dealer_id) ?? null);

        if (!$dealer) {
            return response()->json(['has_new' => false]);
        }

        $response = Http::timeout(5)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . "/api/internal/complaints/by-dealer/{$dealer->id}");

        $hasNew = false;

        if ($response->successful()) {
            $complaints = $response->json('data') ?? [];
            
            $unresolved = array_filter($complaints, function ($c) {
                $status = strtolower($c['status'] ?? $c['Status'] ?? $c['state'] ?? '');
                return !in_array($status, ['resolved', 'closed']);
            });

            $currentCount = count($unresolved);
            
            // Session එකේ පරණ count එක ගන්නවා
            $sessionKey = 'dealer_last_count_' . $dealer->id;
            $lastCount = session($sessionKey, $currentCount);

            // දැනට තියෙන ගාන පරණ එකට වඩා වැඩි නම් අලුත් එකක් ඇවිත්!
            if ($currentCount > $lastCount) {
                $hasNew = true;
            }

            // අලුත් ගාන session එකේ save කරනවා
            session([$sessionKey => $currentCount]);
        }

        return response()->json(['has_new' => $hasNew]);
    }
}