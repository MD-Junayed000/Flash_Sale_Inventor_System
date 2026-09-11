<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate-limit purchase attempts by (IP, SKU) to prevent a single hostile
 * client from rotating emails to exhaust stock for legitimate users.
 *
 * Default: 30 req/min per (IP, SKU), configurable via
 * config('purchase.rate_limit_per_ip').
 *
 * Uses Laravel's cache-backed RateLimiter which transparently uses Redis
 * if CACHE_STORE=redis (atomic, multi-instance safe).
 */
final class ThrottlePurchase
{
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'purchase:'.$request->ip().':'.(string) $request->input('sku', '');
        $maxAttempts = (int) config('purchase.rate_limit.per_ip_per_min', 120);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);

            return response()->json([
                'error'      => 'RATE_LIMITED',
                'message'    => "Too many purchase attempts. Try again in {$retryAfter}s.",
                'retryAfter' => $retryAfter,
            ], 429)->header('Retry-After', (string) $retryAfter);
        }

        $this->limiter->hit($key, 60);

        return $next($request);
    }
}
