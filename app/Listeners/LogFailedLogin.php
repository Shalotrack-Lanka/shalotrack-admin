<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\SDK\Metrics\MeterProvider;

class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        Log::channel('security')->warning('Failed admin login attempt', [
            'email' => $event->credentials['email'] ?? 'unknown',
            'ip' => request()->ip(),
        ]);

        // NEW: increment a failed-login counter for the Grafana dashboard
        try {
            $meter = app(MeterProvider::class)->getMeter('shalotrack-admin');
            $meter->createCounter(
                'admin.login.failed_total',
                description: 'Count of failed admin login attempts'
            )->add(1);
        } catch (\Throwable $e) {
            // never let telemetry break the actual auth flow
        }
    }
}