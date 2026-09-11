<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

/**
 * Customises the `json` log channel.
 *
 * Output is a single JSON object per line — parseable by every log
 * aggregator (Datadog, CloudWatch, Loki, ELK) without a regex.
 *
 * Disable the correlation processor at runtime with
 *   LOG_CORRELATION_ENABLED=false
 * (useful in unit tests that don't want the noise).
 */
final class JsonChannelFactory
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            // Pretty-print JSON in development, single-line in production.
            $formatter = new JsonFormatter(
                jsonPrettyPrint: config('app.debug', false),
                includeStacktraces: true,
            );
            $handler->setFormatter($formatter);

            // Force stderr in local dev so Docker's `docker compose logs` shows it.
            if ($handler instanceof StreamHandler) {
                $handler->setStream($handler->getUrl() === 'php://stderr'
                    ? 'php://stderr'
                    : $handler->getUrl());
            }
        }

        if (config('logging.correlation_enabled', true)) {
            $logger->pushProcessor(new CorrelationProcessor());
        }
    }
}
