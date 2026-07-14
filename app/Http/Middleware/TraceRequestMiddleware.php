<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

class TraceRequestMiddleware
{
    protected ?\OpenTelemetry\API\Trace\SpanInterface $span = null;

    public function handle(Request $request, Closure $next)
    {
        // Skip health checks — same reasoning as the API's ALB filter:
        // don't drown real traffic in monitoring noise
        if ($request->path() === 'up') {
            return $next($request);
        }

        $tracer = app(TracerProvider::class)->getTracer('shalotrack-admin');

        $this->span = $tracer->spanBuilder($request->method() . ' ' . $request->path())
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $this->span->setAttribute('http.method', $request->method());
        $this->span->setAttribute('http.route', $request->path());

        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        if ($this->span === null) {
            return;
        }

        $this->span->setAttribute('http.status_code', $response->getStatusCode());

        if ($response->getStatusCode() >= 500) {
            $this->span->setStatus(StatusCode::STATUS_ERROR);
        }

        $this->span->end();

        // Force the export to actually happen before this PHP process dies —
        // this is the step that would silently vanish without it, same class
        // of bug as tonight if skipped.
        app(TracerProvider::class)->shutdown();
    }
}