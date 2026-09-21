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

        // අර අපි දාපු dd() කේතය දැන් ඉවත් කර ඇත. 
        // API එකෙන් එන 'data' ඇතුළේ තියෙන පැමිණිලි 6ම (සියල්ලම) කෙලින්ම View එකට යවයි.
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

   public function checkNew()
    {
        // API එකෙන් දත්ත ලබා ගැනීම
        $response = \Illuminate\Support\Facades\Http::timeout(5)
            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
            ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

        $hasNew = false;

        if ($response->successful()) {
            $complaints = $response->json('data') ?? [];
            
            // නිවැරදි කිරීම: Admin ට අදාළ පැමිණිලි (Transfer කරපු ඒවා) පමණක් වෙන් කිරීම
            $unresolved = array_filter($complaints, function ($c) {
                $status = strtolower($c['status'] ?? $c['Status'] ?? $c['state'] ?? '');
                return in_array($status, ['escalated', 'with admin']); // Admin ට අයිති ඒවා පමණයි
            });
            
            $currentCount = count($unresolved);
            
            // Session එකේ තියෙන පරණ Count එක ගන්නවා
            $lastCount = session('last_admin_complaints_count', $currentCount);

            // දැනට තියෙන ගාන පරණ ගානට වඩා වැඩි නම්, අලුත් එකක් (හෝ Transfer කරපු එකක්) ඇවිත්!
            if ($currentCount > $lastCount) {
                $hasNew = true;
            }
            
            // අලුත් Count එක Session එකේ සේව් කරනවා
            session(['last_admin_complaints_count' => $currentCount]);
        }

        // ප්‍රතිඵලය JavaScript එකට යවනවා
        return response()->json(['has_new' => $hasNew]);
    }
}