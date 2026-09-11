<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ActivityStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Exceptions\InactiveProductException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\ProductNotFoundException;
use App\Exceptions\PurchaseCooldownException;
use App\Jobs\ProcessOrder;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Product;
use App\Services\Contracts\DiscountServiceInterface;
use App\Services\Contracts\PurchaseServiceInterface;
use App\Services\DTOs\PurchaseResult;
use Illuminate\Contracts\Cache\Repository as CacheContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Core orchestration for the flash-sale purchase flow.
 *
 * Responsibilities (in order):
 *   1. Cooldown check — cheap, fail fast on repeat purchases (Task 6)
 *   2. Find product + validate it is active (Task 2)
 *   3. Atomic stock decrement inside a DB transaction (Task 5, prevents oversell)
 *   4. Roll mystery discount (Bonus task)
 *   5. Persist Order with status=pending
 *   6. Dispatch the ProcessOrder queue job (Task 3)
 *   7. Write activity log (Task 7)
 *
 * Domain failures throw typed exceptions that the controller maps to specific HTTP
 * status codes (409, 404, 422, 429). Unexpected failures re-throw so the global
 * handler can produce a generic 500.
 */
final class PurchaseService implements PurchaseServiceInterface
{
    public function __construct(
        private readonly DiscountServiceInterface $discountService,
        private readonly CacheContract $cache,
    ) {
    }

    /**
     * Attempt a purchase.
     *
     * @param string      $email           Authenticated user email.
     * @param string      $sku             Product SKU.
     * @param int         $quantity        Units requested (>0).
     * @param string|null $paymentRef      Optional client payment reference (audit only).
     * @param string|null $idempotencyKey  Optional Idempotency-Key header for replays.
     *
     * @throws ProductNotFoundException     when the SKU does not exist
     * @throws InactiveProductException     when the SKU is not active
     * @throws InsufficientStockException   when not enough stock
     * @throws PurchaseCooldownException    when the buyer has already purchased recently
     */
    public function attempt(
        string  $email,
        string  $sku,
        int     $quantity,
        ?string $paymentRef     = null,
        ?string $idempotencyKey = null,
        ?int    $userId         = null,
    ): PurchaseResult {
        $cooldownKey = $this->cooldownKey($email, $sku);
        $store = config('purchase.cooldown_store');
        $cache = $store ? Cache::store($store) : $this->cache;

        $attempt = function () use (
            $cache,
            $cooldownKey,
            $email,
            $sku,
            $quantity,
            $paymentRef,
            $idempotencyKey,
            $userId,
        ): PurchaseResult {
            if ($cache->has($cooldownKey)) {
                $this->logFailure($email, $sku, $quantity, 'cooldown_active');

                throw new PurchaseCooldownException($email, $sku);
            }

            try {
                $order = DB::transaction(function () use ($email, $sku, $quantity, $paymentRef, $idempotencyKey, $userId) {
                /** @var Product|null $product */
                $product = Product::query()
                    ->where('sku', $sku)
                    ->lockForUpdate()
                    ->first();

                if (! $product) {
                    throw new ProductNotFoundException($sku);
                }

                if ($product->status !== ProductStatus::Active) {
                    throw new InactiveProductException($sku);
                }

                // Conditional UPDATE — even if the lock were skipped, MySQL guarantees
                // correctness because the WHERE clause runs against committed data.
                if (! $product->decrementStock($quantity)) {
                    throw new InsufficientStockException(
                        requested: $quantity,
                        available: (int) ($product->fresh()?->stock_quantity ?? 0),
                    );
                }

                $discount = $this->discountService->roll();
                $payable  = $this->discountService->calculatePayable(
                    (float) $product->price,
                    $quantity,
                    $discount,
                );

                    return Order::create([
                        'product_id'          => $product->id,
                        'user_email'          => $email,
                        'user_id'             => $userId,
                        'sku'                 => $sku,
                        'quantity'            => $quantity,
                        'unit_price'          => $product->price,
                        'discount_percentage' => $discount,
                        'payable_amount'      => $payable,
                        'payment_ref'         => $paymentRef,
                        'idempotency_key'     => $idempotencyKey,
                        'status'              => OrderStatus::Pending,
                    ]);
                });
            } catch (ProductNotFoundException|InactiveProductException|InsufficientStockException $e) {
                $this->logFailure($email, $sku, $quantity, $e::class);

                throw $e;
            } catch (PurchaseCooldownException $e) {
                throw $e;
            } catch (Throwable $e) {
                $this->logFailure($email, $sku, $quantity, 'unexpected_error');
                Log::error('purchase.unexpected', [
                    'email'    => $email,
                    'sku'      => $sku,
                    'quantity' => $quantity,
                    'error'    => $e->getMessage(),
                    'class'    => $e::class,
                ]);

                throw new \RuntimeException('Could not process purchase.', 0, $e);
            }

            $ttl = (int) config('purchase.cooldown_seconds', 60);
            if ($ttl > 0) {
                $cache->put($cooldownKey, true, $ttl);
            }

            ProcessOrder::dispatch($order->id);
            $this->logSuccess($email, $sku, $quantity);

            return PurchaseResult::ok($order);
        };

        if ($cache->getStore() instanceof LockProvider) {
            return $cache->lock($cooldownKey.':lock', 10)->block(5, $attempt);
        }

        return $attempt();
    }

    public function completeOrder(Order $order): void
    {
        $invoice = sprintf('INV-%s-%05d', now()->format('Ymd'), $order->id);

        $order->update([
            'invoice_number'  => $invoice,
            'status'          => OrderStatus::Completed,
            'failure_reason'  => null,
        ]);

        Log::info('order.completed', [
            'order_id' => $order->id,
            'invoice'  => $invoice,
        ]);
    }

    public function failOrder(Order $order, Throwable $e): void
    {
        $order->update([
            'status'         => OrderStatus::Failed,
            'failure_reason' => $e->getMessage(),
        ]);

        Log::error('order.failed', [
            'order_id' => $order->id,
            'reason'   => $e->getMessage(),
        ]);
    }

    private function cooldownKey(string $email, string $sku): string
    {
        return sprintf('purchase_cooldown:%s:%s', strtolower($email), strtoupper($sku));
    }

    private function logSuccess(string $email, string $sku, int $quantity): void
    {
        ActivityLog::create([
            'email'          => $email,
            'sku'            => $sku,
            'quantity'       => $quantity,
            'status'         => ActivityStatus::Success,
            'failure_reason' => null,
        ]);
    }

    private function logFailure(string $email, string $sku, int $quantity, string $reason): void
    {
        ActivityLog::create([
            'email'          => $email,
            'sku'            => $sku,
            'quantity'       => $quantity,
            'status'         => ActivityStatus::Failed,
            'failure_reason' => $reason,
        ]);
    }
}


