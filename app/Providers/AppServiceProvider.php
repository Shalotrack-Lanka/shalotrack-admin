<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

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
        // Behind the SSL-terminating proxy, requests can still be seen as
        // http:// internally, causing route()/form action URLs to render
        // as http:// and trip the browser's insecure-form-submit warning.
        // Force https scheme outside local dev so generated URLs always match.
        if (! $this->app->environment('local', 'testing')) {
            URL::forceScheme('https');
        }
    }
}
