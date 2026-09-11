<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Standard idempotency pattern (Stripe / IETF draft).
 *
 * Client passes `Idempotency-Key: <uuid>`; we hash (key + path + user) into
 * the cache. Repeated calls with the same key + body return the cached
 * response without re-executing the controller.
 *
 * TTL is 24h (configurable via config('purchase.idempotency_ttl')).
 */
final class IdempotencyKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');

        // No header = no idempotency. POSTing without one is allowed but discouraged.
        if (! $key || ! is_string($key)) {
            return $next($request);
        }

        $userId = optional($request->user())->getAuthIdentifier() ?? 'anon';
        $bodyHash = sha1((string) json_encode($request->all()));
        $cacheKey = 'idem:'.sha1($userId.':'.$request->path().':'.$key.':'.$bodyHash);

        $process = function () use ($cacheKey, $next, $request): Response {
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['status'], $cached['body'])) {
                return response()
                    ->json($cached['body'], $cached['status'])
                    ->header('Idempotent-Replay', 'true');
            }

            /** @var Response $response */
            $response = $next($request);

            // Only cache 2xx — errors should be retryable.
            if ($response->isSuccessful()) {
                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body'   => $response->getOriginalContent(),
                ], now()->addSeconds((int) config('purchase.idempotency_ttl', 86_400)));
            }

            return $response;
        };

        if (Cache::getStore() instanceof LockProvider) {
            return Cache::lock($cacheKey.':lock', 30)->block(5, $process);
        }

        return $process();
    }
}
