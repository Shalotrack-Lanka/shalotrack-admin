<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SDK\Common\Attribute\Attributes;

class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TracerProvider::class, function () {
            $resource = ResourceInfoFactory::defaultResource()->merge(
                ResourceInfo::create(Attributes::create([
                    ResourceAttributes::SERVICE_NAME => 'shalotrack-admin',
                ]))
            );

            $transport = (new OtlpHttpTransportFactory())->create(
                'http://otel.shalotrack.internal:4318/v1/traces',
                'application/x-protobuf'
            );

            $exporter = new SpanExporter($transport);

            // SimpleSpanProcessor exports synchronously, one span at a time —
            // deliberate choice here, since PHP-FPM's process dies right after
            // the request ends. A batching processor relies on a background
            // thread/timer that PHP doesn't have between requests.
            return new TracerProvider(new SimpleSpanProcessor($exporter), null, $resource);
        });
    }

    public function boot(): void
    {
        //
    }
}