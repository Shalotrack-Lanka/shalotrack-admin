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

        app(TracerProvider::class)->shutdown();
    }
}