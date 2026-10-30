<?php

namespace App\Console\Commands;

use App\Services\GatewayStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckGatewayStatus extends Command
{
    protected $signature = 'gateway:check';

    protected $description = 'Probes the gateway (deep health plus the public device endpoint), refreshes the status the '
        . 'dashboard shows, and records CONFIRMED state changes (two identical checks in a row) in gateway_status_events. '
        . 'Always exits 0 -- a down gateway is a result to record, not a failure of this command.';

    public function handle(): int
    {
        $status = GatewayStatus::current(fresh: true);

        // Written on every run, before anything that can fail: the dashboard uses it to tell
        // "the scheduler is running" from "history is not being recorded at all".
        GatewayStatus::heartbeat();

        $event = null;
        try {
            $event = GatewayStatus::track($status);
        } catch (\Throwable $e) {
            Log::warning('gateway:check - could not record status history: ' . $e->getMessage());
        }

        $this->info('Gateway ' . strtoupper($status['state'])
            . ($status['reasons'] ? ': ' . implode(' | ', $status['reasons']) : '')
            . ($event ? " (state change recorded: {$event->state})" : ''));

        return self::SUCCESS;
    }
}