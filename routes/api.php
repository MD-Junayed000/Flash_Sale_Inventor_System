<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PurchaseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| All routes are prefixed with /api/v1 to allow future v2 coexistence.
| `auth:sanctum` replaces the spoofable X-User-Email header pattern.
| `throttle.purchase` enforces per-(IP, SKU) rate limits.
| `idempotency` caches responses for repeat requests with the same key.
*/

Route::prefix('v1')->group(function () {

    // ── Public ────────────────────────────────────────────────────────────
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login',    [AuthController::class, 'login']);

    Route::get('/products',           [ProductController::class, 'index']);
    Route::get('/products/{product}', [ProductController::class, 'show']);

    // ── Operational ───────────────────────────────────────────────────────
    // Liveness — process is up. No DB call.
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    // Readiness — DB + Cache + Queue must answer. Used by k8s/load balancers.
    Route::get('/ready', [HealthController::class, 'ready']);

    // ── Protected ─────────────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me',    [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/orders',                 [OrderController::class, 'index']);
        Route::get('/orders/{order}',         [OrderController::class, 'show']);

        // Hot path: idempotent + rate-limited + authenticated.
        Route::middleware(['idempotency', 'throttle.purchase'])
            ->post('/purchase', [PurchaseController::class, 'store']);
    });
});
