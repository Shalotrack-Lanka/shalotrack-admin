<?php

namespace App\Http\Controllers\Admin\Complaints;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AdminComplaintController extends Controller
{
    public function index()
    {
        $response = Http::timeout(10)
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

        return back()->with('success', 'Complaint closed.');
    }
}