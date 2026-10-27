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
        // Short random reference ID shown to a user on an error page, so they can
        // quote it to support. One ID per request (singleton), also generated
        // outside HTTP context (e.g. artisan commands).
        $this->app->singleton('error.reference_id', function () {
            return strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        });
    }

   public function boot(): void
    {
        // Shape of every id that appears in a URL, decided once for all routes. A value that does not match is
        // a plain 404 before any controller or database call. Without this, /invoices/abc reaches Postgres as
        // a bigint comparison, the database raises "invalid input syntax", and a 500 page prints that SQL error.
        // (Parameters called {id} are constrained on their route groups, because the name is shared.)
        foreach (['ledger', 'invoice', 'creditNote', 'receipt', 'dealer', 'package', 'technician', 'job',
                  'activatedDevice', 'expiredDevice', 'productId', 'requestId'] as $name) {
            \Illuminate\Support\Facades\Route::pattern($name, '[0-9]{1,18}');
        }
        foreach (['vehicleId', 'customerId'] as $name) {
            \Illuminate\Support\Facades\Route::pattern($name, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }
        // Ids owned by the mobile API: letters, digits and dashes only, so they cannot carry ? / .. or spaces
        // into the internal API URL they are placed in.
        \Illuminate\Support\Facades\Route::pattern('complaintId', '[A-Za-z0-9\-]{1,64}');
        \Illuminate\Support\Facades\Route::pattern('imei', '[0-9]{15}');
        \Illuminate\Support\Facades\Route::pattern('month', '[0-9]{4}-[0-9]{2}');

        // Sign-in trail for the audit log: signed in, signed out, locked out (failed attempts are
        // recorded by LoginRequest). Registered here, not in app/Listeners, so it cannot register twice.
        \Illuminate\Support\Facades\Event::subscribe(\App\Services\AuthAudit::class);

        // Billing abilities: checks use ability names, config/billing.php says which role has which.
        foreach (collect(config('billing.abilities'))->flatten()->unique() as $ability) {
            \Illuminate\Support\Facades\Gate::define($ability, fn ($user) => in_array(
                $ability,
                config('billing.abilities.' . ($user->role ?? ''), []),
                true
            ));
        }

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