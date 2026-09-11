<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds a `correlation_id` (and a few other host-level fields) to every
 * log record. Combined with `Log::withContext()` from CorrelationIdMiddleware
 * this gives a single key that joins HTTP, queue, and DB lines in Kibana.
 *
 * Implemented as a Monolog processor so it works with the JSON channel
 * (and every other channel too — Monolog runs every processor on every record).
 */
final class CorrelationProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        // Monolog's `extra` is a stable place for cross-cutting fields.
        $extra['hostname']    = gethostname() ?: 'unknown';
        $extra['app_name']    = config('app.name', 'laravel');
        $extra['app_env']     = config('app.env', 'production');
        $extra['php_version'] = PHP_VERSION;
        $extra['memory_mb']   = (int) (memory_get_usage(true) / 1024 / 1024);

        // Promote the correlation_id from context to a top-level field
        // for easy filtering in Kibana / Loki / CloudWatch.
        if (! empty($record->context['correlation_id'])) {
            $extra['correlation_id'] = $record->context['correlation_id'];
        }

        return $record->with(extra: $extra);
    }
}
