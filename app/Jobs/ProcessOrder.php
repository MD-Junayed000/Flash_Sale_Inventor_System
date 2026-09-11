<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Services\Contracts\PurchaseServiceInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Queue job: process an order after creation (Task 3).
 * Generates invoice, marks order as completed.
 * On failure (Task 4), the order is marked FAILED with the reason.
 */
final class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum attempts. After this, failed() handler runs and persists FAILED state. */
    public int $tries = 3;

    /** Backoff between attempts (seconds). */
    public int $backoff = 5;

    /** Max execution time per attempt. */
    public int $timeout = 30;

    public function __construct(public readonly int $orderId) {}

    public function handle(PurchaseServiceInterface $purchaseService): void
    {
        /** @var Order|null $order */
        $order = Order::query()->find($this->orderId);
        if (! $order) {
            Log::warning("ProcessOrder: order {$this->orderId} not found");
            return;
        }

        // Idempotency: skip if already in a terminal state
        if ($order->status !== \App\Enums\OrderStatus::Pending) {
            Log::info("ProcessOrder: order {$order->id} already in status {$order->status->value}, skipping");
            return;
        }

        // Simulate external processing (e.g., payment gateway, invoice generation)
        $purchaseService->completeOrder($order);
    }

    /**
     * Called once all retries are exhausted.
     * Persists the FAILED state so the failure reason is visible (Task 4).
     */
    public function failed(Throwable $e): void
    {
        /** @var Order|null $order */
        $order = Order::query()->find($this->orderId);
        if (! $order) {
            return;
        }

        app(PurchaseServiceInterface::class)->failOrder($order, $e);
    }
}
