<?php

namespace App\Http\Controllers\Admin\Complaints;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AdminComplaintController extends Controller
{
    public function __construct()
    {
        abort_unless(auth()->user()?->role === 'ADMIN', 403, 'You are not authorized to access this area.');
    }

    public function index()
    {
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

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
                'authorType' => 2, // Admin
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

        // 💡 DEBUG 1: C# API eka resolve karanna denne nathnam, hethuwa kalu screen eken pennai
        if (!$response->successful()) {
            dd('ERROR: API eka resolve karanna denne na!', 'Status: ' . $response->status(), 'C# API Response: ' . $response->body());
        }

        \Illuminate\Support\Facades\Cache::forget('admin_complaints_count');

        return back()->with('success', 'Complaint marked as resolved.');
    }

    public function resolved()
    {
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints');

        // 💡 DEBUG 2: C# API eke Resolved ewata route ekak nathnam, hethuwa kalu screen eken pennai
        if (!$response->successful()) {
            dd('ERROR: API eke okkoma complaints ganna route eka wada na!', 'Status: ' . $response->status(), 'C# API Response: ' . $response->body());
        }

        $allComplaints = $response->json('data') ?? [];

        $complaints = array_filter($allComplaints, function ($complaint) {
            return isset($complaint['status']) && (int)$complaint['status'] === 2;
        });

        $complaints = array_values($complaints);

        return view('admin.complaints.resolved', compact('complaints'));
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

    public function checkNew()
    {
        $response = \Illuminate\Support\Facades\Http::timeout(5)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

        if (!$response->successful()) {
            return response()->json(['has_new' => false]);
        }

        $complaints = $response->json('data') ?? [];
        $currentIds = array_column($complaints, 'complaintId');
        $storedIds = session('admin_complaint_ids');

        if ($storedIds === null) {
            session(['admin_complaint_ids' => $currentIds]);
            return response()->json(['has_new' => false]);
        }

        $newIds = array_diff($currentIds, $storedIds);
        $hasNew  = count($newIds) > 0;

        session(['admin_complaint_ids' => $currentIds]);

        return response()->json(['has_new' => $hasNew]);
    }



    public function checkNewReplies()
    {
        $response = \Illuminate\Support\Facades\Http::timeout(5)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

        if (!$response->successful()) {
            return response()->json(['has_new_reply' => false, 'author_name' => null]);
        }

        $complaints = $response->json('data') ?? [];

        $currentSnapshot = [];
        foreach ($complaints as $c) {
            $currentSnapshot[$c['complaintId']] = count($c['replies'] ?? []);
        }

        $storedSnapshot = session('admin_reply_snapshot');

        if ($storedSnapshot === null) {
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
                $newReplies = array_slice($replies, $storedCount);
                foreach ($newReplies as $reply) {
                    $authorType = (int) ($reply['authorType'] ?? -1);
                    if ($authorType !== 2) {
                        $hasNewReply = true;
                        $authorName  = $reply['authorName'] ?? null;
                        break 2;
                    }
                }
            }
        }

        session(['admin_reply_snapshot' => $currentSnapshot]);

        return response()->json([
            'has_new_reply' => $hasNewReply,
            'author_name'   => $authorName,
        ]);
    }
}