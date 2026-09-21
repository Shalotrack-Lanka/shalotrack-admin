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

    public function boot()
    {
        
        View::composer('partials.sidebars.admin', function ($view) {
            // if the user is logged in and has the role of ADMIN, fetch the complaints count
            if (auth()->check() && auth()->user()->role === 'ADMIN') {
                
                // Cache මගින් විනාඩි 5ක් count එක තබා ගනී (API එකට අනවශ්‍ය load එකක් නොයෑමට)
                $adminCount = Cache::remember('admin_complaints_count', 300, function () {
                    $response = Http::timeout(5)
                        ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                        ->get(config('services.shalotrack_api.base_url') . '/api/internal/complaints/for-admin');

                    if ($response->successful()) {
                        $complaints = $response->json('data') ?? [];
                        // Resolved හෝ Closed නොවන පැමිණිලි ගණන පමණක් ගණනය කිරීම
                        $unresolved = array_filter($complaints, function ($c) {
                            return isset($c['status']) && !in_array(strtolower($c['status']), ['resolved', 'closed']);
                        });
                        return count($unresolved);
                    }
                    return 0; // API එක Fail වුවහොත් 0 පෙන්වයි
                });

                $view->with('complaintsCount', $adminCount);
            }
        });

        // Dealer Sidebar එක සඳහා (View name එක හරියටම දෙන්න)
        View::composer('layouts.dealer', function ($view) {
            if (auth()->check() && auth()->user()->role === 'DEALER') { // Role එක Dealer නම්
                
                $user = auth()->user();
                $dealer = $user->dealer ?? (Dealer::find($user->dealer_id) ?? null);

                if ($dealer) {
                    $dealerCacheKey = 'dealer_complaints_count_' . $dealer->id;
                    
                    $dealerCount = Cache::remember($dealerCacheKey, 300, function () use ($dealer) {
                        $response = Http::timeout(5)
                            ->withHeaders(['X-Admin-Sync-Key' => config('services.shalotrack_api.sync_key')])
                            ->get(config('services.shalotrack_api.base_url') . "/api/internal/complaints/by-dealer/{$dealer->id}");

                        if ($response->successful()) {
                            $complaints = $response->json('data') ?? [];
                            $unresolved = array_filter($complaints, function ($c) {
                                return isset($c['status']) && !in_array(strtolower($c['status']), ['resolved', 'closed']);
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
