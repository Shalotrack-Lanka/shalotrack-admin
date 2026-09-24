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
        $user   = auth()->user();
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

        $user   = auth()->user();
        $dealer = $user->dealer ?? (Dealer::find($user->dealer_id) ?? null);

        if (!$dealer) {
            return back()->withErrors(['dealer' => 'Dealer profile not found.']);
        }

        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/reply", [
                'message'    => $request->input('message'),
                'authorType' => 1, // Dealer -- must match ComplaintReplyAuthorType.Dealer on the API side
                'authorName' => $dealer->full_name,
                // NEW -- lets the API verify this complaint is actually
                // this dealer's own before applying the reply, closing an
                // IDOR that previously let any dealer act on any
                // complaintId with no ownership check at all.
                'dealerId'   => $dealer->id,
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

        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/escalate?dealerId={$dealer->id}");

        if (!$response->successful()) {
            return back()->withErrors(['escalate' => 'Could not escalate this complaint. Please try again.']);
        }

        \Illuminate\Support\Facades\Cache::forget('dealer_complaints_count_' . $dealer->id);

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

    /**
     * FIX: was comparing total unresolved COUNT in session, which silently
     * misses new complaints when an old one closes at the same polling tick
     * (count stays the same → notification never fires).
     *
     * Now stores the full set of unresolved complaint IDs. A notification
     * fires only when a truly new ID appears in the snapshot delta.
     *
     * ComplaintStatus int values: WithDealer=0, WithAdmin=1, Resolved=2, Closed=3.
     * We track only open complaints (status 0 or 1) -- resolved/closed ones
     * have nothing new for the dealer to act on.
     *
     * On the very first call the snapshot is seeded so the dealer doesn't
     * see a burst of notifications for every existing open complaint on login.
     */
    public function checkNewComplaints()
    {
        $dealer = $this->currentDealer();
        if (!$dealer) {
            return response()->json(['has_new' => false]);
        }

        $response = Http::timeout(5)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . "/api/internal/complaints/by-dealer/{$dealer->id}");

        if (!$response->successful()) {
            return response()->json(['has_new' => false]);
        }

        $complaints = $response->json('data') ?? [];

        // Only track open complaints -- status must be 0 (WithDealer) or 1 (WithAdmin).
        // FIX: the previous code compared status as a string against 'resolved'/'closed',
        // which can never match because the C# API serialises status as a raw int with
        // no JsonStringEnumConverter. Every complaint was therefore counted as "unresolved"
        // forever, causing the count to grow continuously and fire false positives on
        // every poll after the first.
        $unresolved = array_values(array_filter($complaints, function ($c) {
            return !in_array((int) ($c['status'] ?? -1), [2, 3], true);
        }));

        $currentIds = array_column($unresolved, 'complaintId');

        $sessionKey = 'dealer_complaint_ids_' . $dealer->id;
        $storedIds  = session($sessionKey);

        if ($storedIds === null) {
            // First call -- seed to avoid notification burst on login.
            session([$sessionKey => $currentIds]);
            return response()->json(['has_new' => false]);
        }

        $newIds = array_diff($currentIds, $storedIds);
        $hasNew = count($newIds) > 0;

        session([$sessionKey => $currentIds]);

        return response()->json(['has_new' => $hasNew]);
    }

    /**
     * Notify the dealer when the admin replies to one of their complaints.
     *
     * In the authorType enum: 0 = Customer, 1 = Dealer, 2 = Admin.
     * This method fires when a new reply with authorType = 2 (Admin) appears
     * on any of this dealer's open or recently-active complaints.
     *
     * We track reply counts per complaint ID in session (keyed by dealer ID
     * to avoid cross-dealer leakage if the same server session is somehow
     * shared). On the first call we seed the snapshot to avoid notifications
     * for replies that were already there before the dealer opened their portal.
     */
    public function checkNewReplies()
    {
        $dealer = $this->currentDealer();
        if (!$dealer) {
            return response()->json(['has_new_reply' => false, 'author_name' => null]);
        }

        $response = Http::timeout(5)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . "/api/internal/complaints/by-dealer/{$dealer->id}");

        if (!$response->successful()) {
            return response()->json(['has_new_reply' => false, 'author_name' => null]);
        }

        $complaints = $response->json('data') ?? [];

        // Build current snapshot: complaintId => total reply count
        $currentSnapshot = [];
        foreach ($complaints as $c) {
            $currentSnapshot[$c['complaintId']] = count($c['replies'] ?? []);
        }

        $sessionKey     = 'dealer_reply_snapshot_' . $dealer->id;
        $storedSnapshot = session($sessionKey);

        if ($storedSnapshot === null) {
            // First call -- seed to avoid notification burst on login.
            session([$sessionKey => $currentSnapshot]);
            return response()->json(['has_new_reply' => false, 'author_name' => null]);
        }

        $hasNewReply = false;
        $authorName  = null;

        foreach ($complaints as $c) {
            $id           = $c['complaintId'];
            $replies      = $c['replies'] ?? [];
            $currentCount = count($replies);
            $storedCount  = $storedSnapshot[$id] ?? 0;

            if ($currentCount > $storedCount) {
                // New replies on this complaint -- check if any are from Admin (authorType = 2).
                // array_slice from $storedCount gives only the newly appended replies,
                // assuming the API returns them in insertion order (CreatedAt ASC).
                $newReplies = array_slice($replies, $storedCount);
                foreach ($newReplies as $reply) {
                    $authorType = (int) ($reply['authorType'] ?? -1);
                    if ($authorType === 2) {
                        // Admin replied -- notify dealer.
                        $hasNewReply = true;
                        $authorName  = $reply['authorName'] ?? 'ShaloTrack Support';
                        break 2; // One notification per poll cycle is enough.
                    }
                }
            }
        }

        // Update snapshot regardless of outcome.
        session([$sessionKey => $currentSnapshot]);

        return response()->json([
            'has_new_reply' => $hasNewReply,
            'author_name'   => $authorName,
        ]);
    }

    public function resolved()
    {
        // TODO: C# API eka haduwama methanata API call eka danna.
        // Danata UI eka test karanna podi sample data ekak pass karanawa.
        $complaints = [
            [
                'vehicleNumber' => 'WP BGU 1212 - TVS Moto',
                'categoryName' => 'Device Issue',
                'description' => 'The device was dropping signals frequently. Replaced the antenna.',
                'resolvedAt' => now()->subDays(2),
                'replies' => [
                    ['authorName' => 'ShaloTrack Support', 'message' => 'Antenna replacement completed successfully.']
                ]
            ]
        ];

        return view('dealer.complaints_resolved', compact('complaints'));
    }
}