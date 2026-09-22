<?php

namespace App\Http\Controllers\Admin\Complaints;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AdminComplaintController extends Controller
{
    // NEW -- these routes previously sat behind the generic 'auth'
    // middleware only, with nothing checking that the logged-in user was
    // actually an Admin. Any authenticated portal user (Dealer, Finance,
    // Technician, Supplier) could resolve/close/reply to complaints by
    // hitting these URLs directly. This app has no role-based route
    // middleware anywhere yet, so a full reusable role gate is a bigger,
    // separate piece of work -- this is a scoped fix for this controller
    // only, matching the roles already used elsewhere in this app
    // (routes/web.php's home-redirect match on 'ADMIN', 'DEALER', etc).

    public function __construct()
    {
        abort_unless(auth()->user()?->role === 'ADMIN', 403, 'You are not authorized to access this area.');
    }

    public function index()
    {
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

        // FIX: this used to re-filter the response with
        // array_filter(... strtolower($c['status']) in ['escalated','with admin'] ...).
        // That can never match anything -- the C# API has no
        // JsonStringEnumConverter configured anywhere, so `status` serializes
        // as a raw int (ComplaintStatus.WithAdmin = 1), never the word "with
        // admin". strtolower(1) is "1", which never equals either string in
        // that list, so every complaint was silently dropped on every request
        // -- this is why the page always showed 0, regardless of how many
        // complaints actually had status WithAdmin.
        //
        // It was also filtering for a status ("escalated") that doesn't
        // exist in the C# ComplaintStatus enum at all -- escalation is
        // recorded via the EscalatedAt timestamp, not a status value.
        //
        // The real fix is to delete the filter, not repair it: GetForAdminAsync
        // on the API side (Repositories/Implementations/ComplaintRepository.cs)
        // already does `.Where(c => c.Status == ComplaintStatus.WithAdmin)`
        // server-side, so /api/internal/complaints/for-admin only ever returns
        // WithAdmin complaints in the first place. Re-filtering here was both
        // redundant and broken.
        $complaints = $response->successful() ? ($response->json('data') ?? []) : [];

        if (!$response->successful()) {
            \Log::warning('Admin complaints fetch failed', ['status' => $response->status()]);
        }

        return view('admin.complaints.index', compact('complaints'));
    }

    public function reply(Request $request, string $complaintId)
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/reply", [
                'message' => $request->input('message'),
                'authorType' => 2, // Admin -- must match ComplaintReplyAuthorType.Admin on the API side
                'authorName' => auth()->user()->name ?? 'ShaloTrack Support',
            ]);

        if (!$response->successful()) {
            return back()->withErrors(['reply' => 'Could not send reply. Please try again.']);
        }

        return back()->with('success', 'Reply sent.');
    }

    public function resolve(string $complaintId)
    {
        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/resolve");

        if (!$response->successful()) {
            return back()->withErrors(['resolve' => 'Could not resolve this complaint. Please try again.']);
        }

        \Illuminate\Support\Facades\Cache::forget('admin_complaints_count');

        return back()->with('success', 'Complaint marked as resolved.');
    }

    public function close(string $complaintId)
    {
        $response = Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->post(config('services.shalotrack_api.base_url') . "/api/internal/complaints/{$complaintId}/close");

        if (!$response->successful()) {
            return back()->withErrors(['close' => 'Could not close this complaint. Please try again.']);
        }

        \Illuminate\Support\Facades\Cache::forget('admin_complaints_count');

        return back()->with('success', 'Complaint closed.');
    }

    /**
     * FIX: was comparing complaint COUNT stored in session, which fails silently
     * when a complaint is resolved and a new one arrives in the same polling
     * window (count stays the same → no notification fires).
     *
     * Now stores the full set of complaint IDs in session instead. A notification
     * fires only when an ID appears that wasn't in the previous snapshot, which
     * is correct regardless of how many complaints were resolved in between.
     *
     * On the very first call the snapshot is seeded with the current IDs so the
     * admin doesn't get a burst of notifications for everything that already
     * exists when they first log in.
     */
    public function checkNew()
    {
        $response = \Illuminate\Support\Facades\Http::timeout(5)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

        if (!$response->successful()) {
            return response()->json(['has_new' => false]);
        }

        $complaints = $response->json('data') ?? [];

        // Extract the current set of complaint IDs from the API response.
        // /api/internal/complaints/for-admin already filters to WithAdmin only
        // server-side, so no additional filtering is needed here.
        $currentIds = array_column($complaints, 'complaintId');

        $storedIds = session('admin_complaint_ids');

        if ($storedIds === null) {
            // First call this session -- seed so we don't immediately fire
            // for every existing WithAdmin complaint.
            session(['admin_complaint_ids' => $currentIds]);
            return response()->json(['has_new' => false]);
        }

        // Any ID present now but not in the stored snapshot is a truly new
        // (or newly escalated) complaint.
        $newIds = array_diff($currentIds, $storedIds);
        $hasNew  = count($newIds) > 0;

        // Always update the snapshot -- even when nothing is new, the list
        // may have shrunk (resolved/closed complaints fall off).
        session(['admin_complaint_ids' => $currentIds]);

        return response()->json(['has_new' => $hasNew]);
    }

    /**
     * Notify the admin when someone OTHER than the admin replies to one of
     * the complaints currently sitting with the admin (WithAdmin status).
     *
     * In practice this fires when a dealer adds a follow-up message after
     * escalating -- authorType 0 = Customer, 1 = Dealer, 2 = Admin. We
     * notify whenever the new reply is NOT from Admin (i.e. authorType ≠ 2).
     *
     * We track reply counts per complaint ID in session rather than a global
     * total, so a reply on complaint A is not hidden by the admin having just
     * replied on complaint B.
     *
     * On the first call the snapshot is seeded to prevent a burst of stale
     * notifications on login.
     */
    public function checkNewReplies()
    {
        $response = \Illuminate\Support\Facades\Http::timeout(5)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

        if (!$response->successful()) {
            return response()->json(['has_new_reply' => false, 'author_name' => null]);
        }

        $complaints = $response->json('data') ?? [];

        // Build current snapshot: complaintId => total reply count
        $currentSnapshot = [];
        foreach ($complaints as $c) {
            $currentSnapshot[$c['complaintId']] = count($c['replies'] ?? []);
        }

        $storedSnapshot = session('admin_reply_snapshot');

        if ($storedSnapshot === null) {
            // First call -- seed to avoid notification burst on login.
            session(['admin_reply_snapshot' => $currentSnapshot]);
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
                // New replies arrived on this complaint -- check if any are
                // from someone other than the admin (authorType ≠ 2).
                // array_slice from $storedCount gives us only the newly
                // appended replies, assuming the API returns them in insertion
                // order (which the C# repository does -- ORDER BY CreatedAt ASC).
                $newReplies = array_slice($replies, $storedCount);
                foreach ($newReplies as $reply) {
                    $authorType = (int) ($reply['authorType'] ?? -1);
                    if ($authorType !== 2) {
                        // Dealer or customer replied -- notify admin.
                        $hasNewReply = true;
                        $authorName  = $reply['authorName'] ?? null;
                        break 2; // One notification is enough per poll cycle.
                    }
                }
            }
        }

        // Update snapshot regardless of whether we found anything new.
        session(['admin_reply_snapshot' => $currentSnapshot]);

        return response()->json([
            'has_new_reply' => $hasNewReply,
            'author_name'   => $authorName,
        ]);
    }
}