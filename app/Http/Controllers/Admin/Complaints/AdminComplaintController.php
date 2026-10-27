<?php

namespace App\Http\Controllers\Admin\Complaints;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\CallsComplaintsApi;
use Illuminate\Support\Facades\Log;

class AdminComplaintController extends Controller
{
    use CallsComplaintsApi;

    public function __construct()
    {
        abort_unless(auth()->user()?->role === 'ADMIN', 403, 'You are not authorized to access this area.');
    }

    private const UNAVAILABLE = 'Complaints are unavailable right now (the app server could not be reached). Please try again in a minute.';

    public function index()
    {
        $response = $this->complaintsApi('get', '/api/internal/complaints/for-admin');

        $complaints = $response?->successful() ? ($response->json('data') ?? []) : [];

        if (! $response?->successful()) {
            Log::warning('Admin complaints fetch failed', ['status' => $response?->status()]);

            return view('admin.complaints.index', compact('complaints'))->withErrors(['api' => self::UNAVAILABLE]);
        }

        return view('admin.complaints.index', compact('complaints'));
    }

    public function reply(Request $request, string $complaintId)
    {
        $request->validate(['message' => 'required|string|max:2000']);

        $response = $this->complaintsApi('post', "/api/internal/complaints/{$complaintId}/reply", [
            'message' => $request->input('message'),
            'authorType' => 2, // Admin
            'authorName' => auth()->user()->name ?? 'ShaloTrack Support',
        ]);

        if (! $response?->successful()) {
            return back()->withErrors(['reply' => 'Could not send reply. Please try again.']);
        }

        return back()->with('success', 'Reply sent.');
    }

    public function resolve(string $complaintId)
    {
        $response = $this->complaintsApi('post', "/api/internal/complaints/{$complaintId}/resolve");

        if (! $response?->successful()) {
            Log::warning('Admin complaint resolve failed', ['complaint_id' => $complaintId, 'status' => $response?->status()]);

            return back()->withErrors(['resolve' => 'Could not resolve this complaint. Please try again.']);
        }

        \Illuminate\Support\Facades\Cache::forget('admin_complaints_count');

        return back()->with('success', 'Complaint marked as resolved.');
    }

    public function resolved()
    {
        // The API filters server-side (for-admin/resolved): a resolved complaint disappears from for-admin.
        $response = $this->complaintsApi('get', '/api/internal/complaints/for-admin/resolved');

        if (! $response?->successful()) {
            Log::warning('Admin resolved-complaints fetch failed', ['status' => $response?->status()]);

            return view('admin.complaints.resolved', ['complaints' => []])->withErrors(['api' => self::UNAVAILABLE]);
        }

        $complaints = $response->json('data') ?? [];

        return view('admin.complaints.resolved', compact('complaints'));
    }

    public function close(string $complaintId)
    {
        $response = $this->complaintsApi('post', "/api/internal/complaints/{$complaintId}/close");

        if (! $response?->successful()) {
            return back()->withErrors(['close' => 'Could not close this complaint. Please try again.']);
        }

        \Illuminate\Support\Facades\Cache::forget('admin_complaints_count');

        return back()->with('success', 'Complaint closed.');
    }

    public function checkNew()
    {
        $response = $this->complaintsApi('get', '/api/internal/complaints/for-admin', [], 5);

        if (! $response?->successful()) {
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
        $response = $this->complaintsApi('get', '/api/internal/complaints/for-admin', [], 5);

        if (! $response?->successful()) {
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