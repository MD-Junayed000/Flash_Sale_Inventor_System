<?php

namespace App\Jobs;

use App\Enums\OrderStatus;
use App\Events\OrderCompleted;
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
 * Queue job that finalises an order after the synchronous stock decrement.
 *
 * Why is this a job at all?
 *   * Decoupling I/O such as payment confirmation, email sending and analytics
 *     pings from the HTTP request keeps the synchronous part of /purchase under
 *     50 ms even under flash-sale load.
 *   * Failed jobs can be retried with exponential backoff ([10s, 30s, 60s]),
 *     persisted to the failed_jobs table on final failure and re-driven
 *     manually via `php artisan queue:retry {uuid}`.
 *
 * Retries will look like:
 *   attempts=1  -> process    -> exception   -> wait 10s
 *   attempts=2  -> process    -> exception   -> wait 30s
 *   attempts=3  -> process    -> exception   -> failed() (DLQ)
 */
class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Maximum attempts before the job is moved to the failed_jobs table. */
    public int $tries = 3;

    /** Hard ceiling per attempt – guards against runaway jobs. */
    public int $timeout = 30;

    /**
     * Progressive exponential backoff (seconds) between attempts.
     * Using a method (not a property) lets us return per-job delays later.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * Absolute deadline (5 min after dispatch) for "give up and DLQ".
     * Backoff + tries together guarantee max attempts within the budget.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addMinutes(5)->toDateTime();
    }

    public function __construct(public readonly int $orderId)
    {
        $this->onConnection(config('purchase.queue_connection'));
        $this->onQueue(config('purchase.queue_name'));
    }

    /**
     * Worker entry-point.
     *
     * Idempotent: if the order is no longer Pending (already Completed/Failed),
     * we short-circuit so that an at-least-once retry cannot double-fulfil.
     */
    public function handle(PurchaseServiceInterface $purchaseService): void
    {
        /** @var Order|null $order */
        $order = Order::query()->find($this->orderId);

        if (! $order) {
            Log::warning('process_order.missing', [
                'order_id' => $this->orderId,
                'attempt'  => $this->attempts(),
            ]);
            return;
        }

        if ($order->status !== OrderStatus::Pending) {
            Log::info('process_order.skipped', [
                'order_id' => $order->id,
                'status'   => $order->status->value,
                'reason'   => 'order_not_pending',
            ]);
            return;
        }

        Log::info('process_order.start', [
            'order_id' => $order->id,
            'attempt'  => $this->attempts(),
            'tries'    => $this->tries,
        ]);

        $purchaseService->completeOrder($order);
        event(new OrderCompleted($order->fresh()));

        Log::info('process_order.complete', [
            'order_id' => $order->id,
            'attempt'  => $this->attempts(),
        ]);
    }

    /**
     * Final-failure handler.  Called by the worker when all retries are
     * exhausted (or when retryUntil() expires).  We mark the order FAILED so
     * the user sees a clear status and the operator can investigate without
     * trying to read the queue tables.
     */
    public function failed(Throwable $e): void
    {
        Log::error('process_order.failed', [
            'order_id' => $this->orderId,
            'attempts' => $this->attempts(),
            'error'    => $e->getMessage(),
            'class'    => $e::class,
        ]);

        /** @var Order|null $order */
        $order = Order::query()->find($this->orderId);
        if ($order && $order->status === OrderStatus::Pending) {
            $order->status = OrderStatus::Failed;
            $order->failure_reason = $e->getMessage();
            $order->save();
            event(new OrderCompleted($order->fresh()));

            Log::warning('process_order.marked_failed', [
                'order_id' => $order->id,
            ]);
        }
    }
}
