<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Common\Instrumentation\Globals;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\Contrib\Otlp\MetricExporter;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SDK\Common\Attribute\Attributes;

class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Defined once here, shared by all three providers below via `use ($resource)`
        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => 'shalotrack-admin',
            ]))
        );

        $this->app->singleton(TracerProvider::class, function () use ($resource) {
            $transport = (new OtlpHttpTransportFactory())->create(
                'http://otel.shalotrack.internal:4318/v1/traces',
                'application/x-protobuf',
                [],
                null,
                2
            );

            $exporter = new SpanExporter($transport);

            // SimpleSpanProcessor exports synchronously, one span at a time —
            // deliberate choice here, since PHP-FPM's process dies right after
            // the request ends. A batching processor relies on a background
            // thread/timer that PHP doesn't have between requests.
            return new TracerProvider(new SimpleSpanProcessor($exporter), null, $resource);
        });

        $this->app->singleton(MeterProvider::class, function () use ($resource) {
            $transport = (new OtlpHttpTransportFactory())->create(
                'http://otel.shalotrack.internal:4318/v1/metrics',
                'application/x-protobuf',
                [],
                null,
                2
            );
            $exporter = new MetricExporter($transport);
            // ExportingReader (not periodic) — this process dies after each request,
            // so metrics are pushed explicitly at the end of the request, same reasoning
            // as SimpleSpanProcessor for traces.
            $reader = new ExportingReader($exporter);
            return MeterProvider::builder()
                ->setResource($resource)
                ->addReader($reader)
                ->build();
        });

        $this->app->singleton(LoggerProvider::class, function () use ($resource) {
            $transport = (new OtlpHttpTransportFactory())->create(
                'http://otel.shalotrack.internal:4318/v1/logs',
                'application/x-protobuf'
                [],
                null,
                2
            );
            $exporter = new LogsExporter($transport);
            return new LoggerProvider(new SimpleLogRecordProcessor($exporter), null, $resource);
        });
    }

    public function boot(): void
    {
        //
    }
}