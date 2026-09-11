<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Order;
use App\Services\DTOs\PurchaseResult;
use Throwable;

/**
 * Orchestrates the flash-sale purchase flow.
 *
 * Implementations MUST be idempotent across request retries (the queue is
 * at-least-once), throw typed domain exceptions for known failure modes so
 * the HTTP layer can map them to meaningful status codes, and never leak
 * implementation details to callers.
 */
interface PurchaseServiceInterface
{
    /**
     * Attempt a purchase.
     *
     * @param  string      $email           Authenticated user email (resolved from Sanctum).
     * @param  string      $sku             Product SKU being purchased.
     * @param  int         $quantity        Units requested (>0).
     * @param  string|null $paymentRef      Optional client payment processor reference (audit only).
     * @param  string|null $idempotencyKey  Optional Idempotency-Key header value for replays.
     * @param  int|null    $userId          Optional authenticated user id (FK to users).
     *
     * @throws \App\Exceptions\ProductNotFoundException
     * @throws \App\Exceptions\InactiveProductException
     * @throws \App\Exceptions\InsufficientStockException
     * @throws \App\Exceptions\PurchaseCooldownException
     */
    public function attempt(
        string  $email,
        string  $sku,
        int     $quantity,
        ?string $paymentRef     = null,
        ?string $idempotencyKey = null,
        ?int    $userId         = null,
    ): PurchaseResult;

    /**
     * Finalise an order (used by the {@see \App\Jobs\ProcessOrder} worker).
     */
    public function completeOrder(Order $order): void;

    /**
     * Mark an order as failed (used by the worker when retries are exhausted).
     */
    public function failOrder(Order $order, Throwable $e): void;
}
