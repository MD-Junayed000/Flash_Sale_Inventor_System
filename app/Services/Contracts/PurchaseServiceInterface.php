<?php

declare(strict_types=1);

namespace App\Services\Contracts;

use App\Models\Order;
use App\Services\DTOs\PurchaseResult;

interface PurchaseServiceInterface
{
    /**
     * Attempt to purchase a product.
     * Handles: validation of stock, active status, cooldown,
     * atomic stock decrement, discount application, order creation,
     * queue dispatch, and activity logging.
     */
    public function attempt(string $email, string $sku, int $quantity): PurchaseResult;

    /**
     * Process an order in the queue worker context.
     * Generates invoice, marks completed.
     */
    public function completeOrder(Order $order): void;
}
