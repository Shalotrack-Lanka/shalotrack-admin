<?php

namespace App\Logging;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use OpenTelemetry\API\Logs\LogRecord as OtelLogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\SDK\Logs\LoggerProvider;

class OtelLogHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        try {
            $logger = app(LoggerProvider::class)->getLogger('shalotrack-admin');

            $otelRecord = (new OtelLogRecord($record->message))
                ->setSeverityText($record->level->getName())
                ->setSeverityNumber($this->mapSeverity($record->level->value))
                ->setAttributes($record->context)
                ->setTimestamp((int) ($record->datetime->format('Uu')));

            $logger->emit($otelRecord);
        } catch (\Throwable $ex) {
            // never let a logging failure break the request that triggered the log
        }
    }

    private function mapSeverity(int $level): int
    {
        return match (true) {
            $level >= 550 => Severity::ERROR,
            $level >= 300 => Severity::WARN,
            default => Severity::INFO,
        };
    }
}