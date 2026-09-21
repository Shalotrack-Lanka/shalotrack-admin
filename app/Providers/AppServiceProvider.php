<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\Dealer;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Reuses the OTel trace ID that TraceRequestMiddleware already creates
        // for every request, so the reference ID shown to a user maps directly
        // to a searchable trace in Grafana Tempo — no separate ID system needed.
        // Falls back to a short random ID outside HTTP context (e.g. artisan commands).
        $this->app->singleton('error.reference_id', function () {
            $span = request()?->attributes->get('otel_span');
            if ($span) {
                return strtoupper($span->getContext()->getTraceId());
            }
            return strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        });
    }

   public function boot(): void
    {
        // Admin Sidebar එක සඳහා
        View::composer('partials.sidebars.admin', function ($view) {
            if (auth()->check() && auth()->user()->role === 'ADMIN') {
                
                // තත්පර 1ක Cache එකක් දැමීම (මෙහි 1 යනු තත්පර 1යි)
                $adminCount = Cache::remember('admin_complaints_count', 1, function () {
                    $response = Http::timeout(5)
                        ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                        ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

                    if ($response->successful()) {
                        $complaints = $response->json('data') ?? [];
                        
                        $unresolved = array_filter($complaints, function ($c) {
                            $status = strtolower($c['status'] ?? $c['Status'] ?? $c['state'] ?? '');
                            return in_array($status, ['escalated', 'with admin']);
                        });
                        
                        return count($unresolved);
                    }
                    return 0;
                });

                $view->with('complaintsCount', $adminCount);
            }
        });

        // Dealer Sidebar එක සඳහා
        View::composer('layouts.dealer', function ($view) {
            if (auth()->check() && auth()->user()->role === 'DEALER') {
                
                $user = auth()->user();
                $dealer = $user->dealer ?? (Dealer::find($user->dealer_id) ?? null);

                if ($dealer) {
                    $dealerCacheKey = 'dealer_complaints_count_' . $dealer->id;
                    
                    // තත්පර 1ක Cache එකක් දැමීම
                    $dealerCount = Cache::remember($dealerCacheKey, 1, function () use ($dealer) {
                        $response = Http::timeout(5)
                            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                            ->get(config('services.shalotrack_api.base_url') . "/api/internal/complaints/by-dealer/{$dealer->id}");

                        if ($response->successful()) {
                            $complaints = $response->json('data') ?? [];
                            
                            $unresolved = array_filter($complaints, function ($c) {
                                $status = strtolower($c['status'] ?? $c['Status'] ?? $c['state'] ?? '');
                                return !in_array($status, ['resolved', 'closed']);
                            });
                            
                            return count($unresolved);
                        }
                        return 0;
                    });

                    $view->with('complaintsCount', $dealerCount);
                }
            }
        });
    }
}
