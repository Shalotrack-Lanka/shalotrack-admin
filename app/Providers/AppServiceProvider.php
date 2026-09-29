<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\Dealer;
use Illuminate\Support\Carbon;

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
        // Timestamps are stored and computed in UTC (config('app.timezone')). Screens and reports for
        // people in Sri Lanka show them through ->local(), which only changes how a time is displayed.
        Carbon::macro('local', function () {
            /** @var Carbon $this */
            return $this->clone()->setTimezone(config('app.display_timezone'));
        });

        // FIX: mixed-content bug. route()/url() were emitting http:// absolute
        // URLs in production (erp.shalotrack.com, served over HTTPS) even
        // though trustProxies(at: '*') is already set in bootstrap/app.php --
        // meaning the reverse proxy in front of this app isn't reliably
        // forwarding X-Forwarded-Proto: https, so Laravel's scheme
        // auto-detection guesses http. Browsers hard-block any fetch() from
        // an https:// page to an http:// resource with no useful error beyond
        // "Failed to fetch" / "TypeError: Failed to fetch" in the console --
        // this was silently breaking every admin feature that builds an
        // absolute URL for JS (Device Command Center's send/history calls,
        // the sidebar's complaint/reply notification pollers). Don't rely on
        // proxy header forwarding alone for something this load-bearing --
        // force it. Gated to production so local `php artisan serve` (plain
        // http) is unaffected.
        //
        // This is a mitigation, not the root-cause fix: the reverse proxy /
        // ALB config should also be checked to confirm it's actually setting
        // X-Forwarded-Proto on requests to this app. This line just makes
        // sure the app is correct regardless of whether that gets fixed.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

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
