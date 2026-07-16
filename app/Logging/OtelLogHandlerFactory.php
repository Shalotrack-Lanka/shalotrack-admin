<?php

namespace App\Logging;

use Monolog\Logger;

class OtelLogHandlerFactory
{
    public function __invoke(array $config): Logger
    {
        return new Logger('otel', [new OtelLogHandler($config['level'] ?? 'warning')]);
    }
}