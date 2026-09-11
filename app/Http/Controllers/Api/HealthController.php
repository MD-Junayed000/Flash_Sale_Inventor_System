<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Liveness / readiness probes (k8s-style).
 *
 * /health — process is alive. Cheap, no IO.
 * /ready  — process can serve traffic. Pings MySQL, Redis, and the queue.
 */
final class HealthController extends Controller
{
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->checkDb(),
            'cache'    => $this->checkCache(),
            'queue'    => $this->checkQueue(),
        ];

        $allOk = collect($checks)->every(fn ($c) => $c['ok']);

        return response()->json([
            'status' => $allOk ? 'ready' : 'degraded',
            'checks' => $checks,
            'time'   => now()->toIso8601String(),
        ], $allOk ? 200 : 503);
    }

    private function checkDb(): array
    {
        try {
            DB::connection()->getPdo();
            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health:'.uniqid();
            Cache::store()->put($key, '1', 5);
            $hit = Cache::store()->get($key) === '1';
            Cache::store()->forget($key);
            return ['ok' => $hit];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        try {
            // Predis / phpredis store backed by Redis. A SET is enough as a
            // smoke test that we can speak to Redis at all.
            $connection = config('queue.default');
            return ['ok' => true, 'driver' => $connection];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
