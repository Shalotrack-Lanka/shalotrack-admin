<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

class TraceRequestMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->path() === 'up') {
            return $next($request);
        }

        // NEW: mark request start for duration histogram in terminate()
        $request->attributes->set('otel_start', microtime(true));

        $tracer = app(TracerProvider::class)->getTracer('shalotrack-admin');

        $span = $tracer->spanBuilder($request->method() . ' ' . $request->path())
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $span->setAttribute('http.method', $request->method());
        $span->setAttribute('http.route', $request->path());

        // Store on the request itself — this object is genuinely shared
        // between handle() and terminate(), unlike $this on the middleware instance
        $request->attributes->set('otel_span', $span);

        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        $span = $request->attributes->get('otel_span');

        if ($span === null) {
            return;
        }

        $span->setAttribute('http.status_code', $response->getStatusCode());

        if ($response->getStatusCode() >= 500) {
            $span->setStatus(StatusCode::STATUS_ERROR);
        }

        $span->end();

        // NEW: record request duration as a histogram metric
        $startedAt = $request->attributes->get('otel_start');
        if ($startedAt !== null) {
            $durationMs = (microtime(true) - $startedAt) * 1000;
            $routeName = optional($request->route())->getName() ?? 'unmatched';

            try {
                $meter = app(MeterProvider::class)->getMeter('shalotrack-admin');
                $histogram = $meter->createHistogram(
                    'http.server.duration',
                    unit: 'ms',
                    description: 'Admin portal request duration'
                );
                $histogram->record($durationMs, [
                    'http.method' => $request->method(),
                    'http.route' => $routeName, // route NAME, not raw path — avoids cardinality blowup
                    'http.status_code' => (string) $response->getStatusCode(),
                ]);
            } catch (\Throwable $e) {
                // telemetry must never break the response that's already been sent
            }
        }

        app(TracerProvider::class)->shutdown();

        // NEW: MeterProvider must also be flushed — PHP-FPM kills this process
        // right after the response, no background thread exists to export later
        app(MeterProvider::class)->shutdown();
    }
}