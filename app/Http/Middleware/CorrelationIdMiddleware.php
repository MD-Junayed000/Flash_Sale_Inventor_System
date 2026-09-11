<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns every request a stable correlation ID (or honours an inbound one),
 * stores it in the Monolog context, and echoes it back on the response so
 * an SRE can trace a single purchase from HTTP entry through the queue
 * worker, the database, and back to the caller.
 *
 * Industry pattern — also known as request ID / trace ID. Standard headers:
 *   inbound  : X-Request-Id, X-Correlation-Id, traceparent (W3C)
 *   outbound : X-Request-Id
 */
final class CorrelationIdMiddleware
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->resolveCorrelationId($request);

        // Stash on the request for downstream services / logs / queue jobs.
        $request->attributes->set('correlation_id', $correlationId);

        // Push into the Monolog context so every `Log::*` call carries it.
        Log::withContext([
            'correlation_id' => $correlationId,
            'http_method'    => $request->getMethod(),
            'http_path'      => $request->path(),
            'client_ip'      => $request->ip(),
        ]);

        $response = $next($request);

        // Echo it back so the client can quote it in support tickets.
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    /**
     * Prefer a client-supplied header; fall back to a UUIDv4.
     * Reject anything longer than 200 chars to avoid log-injection.
     */
    private function resolveCorrelationId(Request $request): string
    {
        foreach (['X-Request-Id', 'X-Correlation-Id'] as $header) {
            $value = $request->headers->get($header);
            if ($value && strlen($value) <= 200 && preg_match('/^[A-Za-z0-9._\-:]+$/', $value)) {
                return $value;
            }
        }

        return (string) Str::uuid();
    }
}
